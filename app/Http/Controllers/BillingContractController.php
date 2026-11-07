<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\BillingDeliveryAttempt;
use App\Models\BillingReminder;
use App\Models\BillingRenewal;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ProductService;
use App\Models\Tax;
use App\Services\BillingContractService;
use App\Services\BillingInvoiceService;
use App\Services\BillingOccurrenceService;
use App\Services\BillingPaymentService;
use App\Services\BillingReminderService;
use App\Services\BillingRenewalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 1 — billing contracts (plan §5, §8).
 *
 * Screens for the contract master: list, create/edit (draft only),
 * detail with lifecycle actions, occurrences per contract,
 * amendment. All logic lives in BillingContractService /
 * BillingOccurrenceService; this controller only gates
 * permissions, scopes to the company and turns service
 * exceptions into redirects.
 *
 * Permissions: 'manage billing contracts' (create, edit, submit,
 * activate, suspend, resume, end, cancel, amend) and 'approve
 * billing contracts' (approve, reject). Viewing needs either.
 */
class BillingContractController extends Controller
{
    protected BillingContractService $service;
    protected BillingOccurrenceService $occurrences;
    protected BillingInvoiceService $billing;
    protected BillingPaymentService $payments;
    protected BillingReminderService $reminders;
    protected BillingRenewalService $renewals;

    public function __construct(
        BillingContractService $service,
        BillingOccurrenceService $occurrences,
        BillingInvoiceService $billing,
        ?BillingPaymentService $payments = null,
        ?BillingReminderService $reminders = null,
        ?BillingRenewalService $renewals = null
    ) {
        $this->service = $service;
        $this->occurrences = $occurrences;
        $this->billing = $billing;
        $this->payments = $payments ?? new BillingPaymentService();
        $this->reminders = $reminders ?? new BillingReminderService($this->payments, $this->billing);
        $this->renewals = $renewals ?? new BillingRenewalService($this->service);
    }

    public function index(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = BillingContract::where('created_by', $creatorId)
            ->with(['customer', 'lines'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', $search)->orWhere('title', 'like', $search);
            });
        }

        // Phase 3: "Contracts ending soon" — end date within 30 days.
        $endingSoonCount = $this->renewals->endingSoon($creatorId)->count();
        if ($request->boolean('ending_soon')) {
            $endingIds = $this->renewals->endingSoon($creatorId)->pluck('id')->all();
            $query->whereIn('id', $endingIds);
        }

        $contracts = $query->paginate(25);
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get();

        return view('billing-contracts.index', compact('contracts', 'customers', 'endingSoonCount'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get();
        $products = ProductService::where('created_by', $creatorId)->orderBy('name')->get();
        $taxes = Tax::where('created_by', $creatorId)->orderBy('name')->get();

        return view('billing-contracts.create', compact('customers', 'products', 'taxes'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $contract = $this->service->createContract($creatorId, $this->contractInput($request), (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $contract->id)
            ->with('success', __('Billing contract created.'));
    }

    public function show(int $id)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        $contract->load(['customer', 'lines', 'revisions', 'auditEvents']);
        $occurrences = $contract->occurrences()->orderBy('period_start')->paginate(25);
        // Phase 2: the invoices issued for this contract.
        $invoices = $contract->occurrences()
            ->whereNotNull('invoice_id')
            ->with('invoice')
            ->orderBy('period_start')
            ->get();

        // Phase 3: payment summary, renewal state.
        $paymentsSummary = $this->payments->summaryForContract($contract);
        $pendingRenewal = $this->renewals->pendingRenewal($contract);
        $canRenew = $this->renewals->canRenew($contract);
        $renewalHistory = $this->renewals->renewalHistory($contract);

        return view('billing-contracts.show', compact('contract', 'occurrences', 'invoices', 'paymentsSummary', 'pendingRenewal', 'canRenew', 'renewalHistory'));
    }

    public function edit(int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        if (! $contract->isEditable()) {
            return redirect()->route('billing-contracts.show', $contract->id)
                ->with('error', __('Only a draft contract can be edited. Use an amendment for an active contract.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get();
        $products = ProductService::where('created_by', $creatorId)->orderBy('name')->get();
        $taxes = Tax::where('created_by', $creatorId)->orderBy('name')->get();

        return view('billing-contracts.edit', compact('contract', 'customers', 'products', 'taxes'));
    }

    public function update(Request $request, int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
            $contract = $this->service->updateContract($contract, $this->contractInput($request), (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $contract->id)
            ->with('success', __('Billing contract updated.'));
    }

    public function occurrences(int $id, Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        $query = $contract->occurrences()->orderBy('period_start');
        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        $occurrences = $query->paginate(25);

        return view('billing-contracts.occurrences', compact('contract', 'occurrences'));
    }

    // ---------------------------------------------------------------
    // Phase 2 — recurring invoice generation (AC02/AC03/AC07/AC08)
    // ---------------------------------------------------------------

    /** Due occurrences queue: all pending occurrences whose billing date has been reached. */
    public function dueQueue(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $occurrences = $this->billing->dueOccurrences($creatorId);

        // Paginate the in-memory collection (the due set is small:
        // one row per billing period per contract).
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;
        $occurrences = new \Illuminate\Pagination\LengthAwarePaginator(
            $occurrences->forPage($page, $perPage)->values(),
            $occurrences->count(),
            $perPage,
            $page,
            ['path' => route('billing-contracts.due-queue'), 'query' => $request->query()]
        );

        return view('billing-contracts.due-queue', compact('occurrences'));
    }

    /** One occurrence: its invoice link, state history and delivery attempts. */
    public function occurrenceShow(int $contractId, int $occurrenceId)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $contractId);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        $occurrence = $contract->occurrences()->with(['invoice', 'deliveryAttempts'])->find($occurrenceId);
        if (! $occurrence) {
            return redirect()->route('billing-contracts.show', $contract->id)
                ->with('error', __('Occurrence not found for this contract.'));
        }

        $history = $contract->auditEvents()
            ->where('occurrence_id', $occurrence->id)
            ->orderBy('id')
            ->get();
        $attempt = $occurrence->invoice_id
            ? BillingDeliveryAttempt::where('invoice_id', $occurrence->invoice_id)->first()
            : null;
        // Phase 3: the reminder row for this occurrence's invoice, if any.
        // The reminders table arrives with the Phase 3 migration;
        // before it runs there can be no reminder row.
        $reminder = ($occurrence->invoice_id && Schema::hasTable('billing_reminders'))
            ? BillingReminder::where('invoice_id', $occurrence->invoice_id)->first()
            : null;

        return view('billing-contracts.occurrence-show', compact('contract', 'occurrence', 'history', 'attempt', 'reminder'));
    }

    /** Manual "Generate now" for one contract (all its due occurrences). */
    public function generateNow(int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
            $results = $this->billing->generateDueForContract($contract, (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        if ($results['failed'] > 0) {
            $first = $results['failures'][0];
            return redirect()->route('billing-contracts.show', $contract->id)
                ->with('error', __(':n invoice(s) issued, :f failed. First failure: :e', [
                    'n' => $results['generated'],
                    'f' => $results['failed'],
                    'e' => $first['error'],
                ]));
        }

        return redirect()->route('billing-contracts.show', $contract->id)
            ->with('success', __(':n invoice(s) issued.', ['n' => $results['generated']]));
    }

    /** Manual generate for one due occurrence (from the queue). */
    public function generateOccurrence(int $contractId, int $occurrenceId)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $contractId);
            $occurrence = $contract->occurrences()->find($occurrenceId);
            if (! $occurrence) {
                return redirect()->route('billing-contracts.due-queue')
                    ->with('error', __('Occurrence not found for this contract.'));
            }

            $result = $this->billing->generateForOccurrence($occurrence, (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        $message = $result['already_invoiced']
            ? __('Invoice already exists for this period.')
            : __('Invoice issued.');

        return redirect()->route('billing-contracts.occurrence-show', [$contract->id, $occurrence->id])
            ->with('success', $message);
    }

    /** Manual resend of an issued invoice's email (same invoice, same PDF). */
    public function resendOccurrence(int $contractId, int $occurrenceId)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $contractId);
            $occurrence = $contract->occurrences()->find($occurrenceId);
            if (! $occurrence) {
                return redirect()->route('billing-contracts.due-queue')
                    ->with('error', __('Occurrence not found for this contract.'));
            }

            $result = $this->billing->resendInvoice($occurrence, (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        if ($result['status'] === 'sent') {
            return redirect()->route('billing-contracts.occurrence-show', [$contract->id, $occurrence->id])
                ->with('success', __('Invoice email sent.'));
        }

        $attempt = $result['attempt'];
        $error = $attempt->last_error ?? __('Email could not be sent.');
        return redirect()->route('billing-contracts.occurrence-show', [$contract->id, $occurrence->id])
            ->with('error', __('Email could not be sent: :e', ['e' => $error]));
    }

    // ---------------------------------------------------------------
    // Phase 3 — payments (AC12), reminders, renewals
    // ---------------------------------------------------------------

    /** Contract payments view: each issued invoice, amount, paid, due, state. */
    public function payments(int $id)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        $rows = $this->payments->paymentsForContract($contract);
        $summary = $this->payments->summaryForContract($contract);

        return view('billing-contracts.payments', compact('contract', 'rows', 'summary'));
    }

    /** Reminders list for one contract + its per-invoice reminder history. */
    public function reminders(int $id)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->reminders->syncReminders($creatorId);
        $reminders = BillingReminder::where('contract_id', $contract->id)
            ->where('created_by', $creatorId)
            ->with(['occurrence', 'invoice'])
            ->orderByDesc('id')
            ->paginate(25);
        $settings = $this->reminders->reminderSettings($creatorId);

        return view('billing-contracts.reminders', compact('contract', 'reminders', 'settings'));
    }

    /** Manual send of a reminder for one occurrence's invoice (same invoice, same PDF). */
    public function remindNow(int $contractId, int $occurrenceId)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $creatorId = (int) Auth::user()->creatorId();
            $contract = $this->contractForCompany($creatorId, $contractId);
            $occurrence = $contract->occurrences()->find($occurrenceId);
            if (! $occurrence || ! $occurrence->invoice_id) {
                return redirect()->back()->with('error', __('No issued invoice for this period.'));
            }

            $this->reminders->syncReminders($creatorId);
            $reminder = BillingReminder::where('invoice_id', $occurrence->invoice_id)
                ->where('created_by', $creatorId)
                ->first();

            if (! $reminder) {
                return redirect()->back()->with('error', __('This invoice is not overdue, so no reminder is due.'));
            }

            $result = $this->reminders->sendReminderNow($reminder, (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($result['status'] === 'sent') {
            return redirect()->back()->with('success', __('Payment reminder sent.'));
        }
        if ($result['status'] === 'stopped') {
            return redirect()->back()->with('error', __('The reminder was stopped: the invoice is settled or the reminder limit was reached.'));
        }

        return redirect()->back()->with('error', __('The reminder could not be sent.') . ' ' . ($result['reminder']->last_error ?? ''));
    }

    /** Renewal draft form: new start/end pre-filled (old end + 1 day, same term). */
    public function renewForm(int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
            $contract->load(['customer', 'lines']);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        if (! $this->renewals->canRenew($contract)) {
            return redirect()->route('billing-contracts.show', $contract->id)
                ->with('error', __('This contract cannot be renewed (it has no end date, is not active or ended, or already has a pending renewal).'));
        }

        $oldEnd = \Carbon\Carbon::parse($contract->end_date)->startOfDay();
        $oldStart = \Carbon\Carbon::parse($contract->start_date)->startOfDay();
        $newStart = $oldEnd->copy()->addDay()->format('Y-m-d');
        $newEnd = $oldEnd->copy()->addDay()->addDays($oldStart->diffInDays($oldEnd))->format('Y-m-d');

        return view('billing-contracts.renew', compact('contract', 'newStart', 'newEnd'));
    }

    /** Create the renewal draft (same customer, lines, frequency, terms; new reference). */
    public function renew(Request $request, int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);

            $overrides = [];
            if ($request->filled('title')) {
                $overrides['title'] = $request->input('title');
            }
            if ($request->filled('start_date')) {
                $overrides['start_date'] = $request->input('start_date');
            }
            // An explicitly cleared end date means open-ended.
            if ($request->has('end_date')) {
                $overrides['end_date'] = $request->input('end_date') ?: null;
            }

            $new = $this->renewals->renew($contract, (int) Auth::id(), $overrides);
        } catch (BillingContractException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $new->id)
            ->with('success', __('Renewal draft created (:ref). It needs the same approval as a new contract.', ['ref' => $new->reference]));
    }

    /** Abandon a pending renewal: the old contract simply ends. */
    public function abandonRenewal(int $contractId, int $renewalId)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $creatorId = (int) Auth::user()->creatorId();
            $contract = $this->contractForCompany($creatorId, $contractId);
            $renewal = BillingRenewal::where('id', $renewalId)
                ->where('contract_id', $contract->id)
                ->where('created_by', $creatorId)
                ->first();

            if (! $renewal) {
                return redirect()->back()->with('error', __('Renewal not found for this contract.'));
            }

            $this->renewals->abandon($renewal, (int) Auth::id());
        } catch (BillingContractException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $contract->id)
            ->with('success', __('Renewal abandoned.'));
    }

    /**
     * Preview of the first 3 occurrences for unsaved form data.
     * Returns JSON; the create/edit form calls it with the
     * "Preview" button.
     */
    public function preview(Request $request)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        try {
            $input = $this->contractInput($request);
            $input['created_by'] = (int) Auth::user()->creatorId();
            $preview = $this->service->preview($input);
        } catch (BillingContractException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['occurrences' => $preview]);
    }

    // ------------------------------------------------------------------
    // Lifecycle actions
    // ------------------------------------------------------------------

    public function submit(int $id)
    {
        return $this->lifecycle($id, 'manage billing contracts', fn (BillingContract $c) => $this->service->submitForApproval($c, (int) Auth::id()), __('Billing contract submitted.'));
    }

    public function approve(int $id)
    {
        return $this->lifecycle($id, 'approve billing contracts', fn (BillingContract $c) => $this->service->approve($c, (int) Auth::id()), __('Billing contract approved and activated.'));
    }

    public function reject(Request $request, int $id)
    {
        return $this->lifecycle($id, 'approve billing contracts', fn (BillingContract $c) => $this->service->reject($c, (int) Auth::id(), (string) $request->input('reason', '')), __('Billing contract rejected.'));
    }

    public function activate(int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
            $contract = $this->service->activate($contract, (int) Auth::id());
            // Phase 3: a renewal draft that just became active
            // completes its renewal event.
            $this->renewals->syncRenewalStatus($contract);
        } catch (BillingContractException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $contract->id)->with('success', __('Billing contract activated.'));
    }

    public function suspend(Request $request, int $id)
    {
        return $this->lifecycle($id, 'manage billing contracts', fn (BillingContract $c) => $this->service->suspend($c, (int) Auth::id(), (string) $request->input('reason', '')), __('Billing contract suspended.'));
    }

    public function resume(Request $request, int $id)
    {
        return $this->lifecycle($id, 'manage billing contracts', fn (BillingContract $c) => $this->service->resume($c, (int) Auth::id(), (string) $request->input('missed_decision', 'bill')), __('Billing contract resumed.'));
    }

    public function endForm(int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED], true)) {
            return redirect()->route('billing-contracts.show', $contract->id)
                ->with('error', __('Only an active or suspended contract can be ended.'));
        }

        $effectiveDate = request('effective_date', now()->format('Y-m-d'));
        $affected = $this->service->affectedOccurrences($contract, $effectiveDate);

        return view('billing-contracts.end', compact('contract', 'effectiveDate', 'affected'));
    }

    public function end(Request $request, int $id)
    {
        return $this->lifecycle($id, 'manage billing contracts', fn (BillingContract $c) => $this->service->end($c, (int) Auth::id(), (string) $request->input('effective_date', now()->format('Y-m-d')), $request->input('reason')), __('Billing contract ended.'));
    }

    public function cancel(Request $request, int $id)
    {
        return $this->lifecycle($id, 'manage billing contracts', fn (BillingContract $c) => $this->service->cancel($c, (int) Auth::id(), (string) $request->input('reason', ''), $request->input('effective_date')), __('Billing contract cancelled.'));
    }

    public function amendForm(int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
        } catch (BillingContractException $e) {
            return redirect()->route('billing-contracts.index')->with('error', $e->getMessage());
        }

        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED], true)) {
            return redirect()->route('billing-contracts.show', $contract->id)
                ->with('error', __('Only an active or suspended contract can be amended.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get();
        $products = ProductService::where('created_by', $creatorId)->orderBy('name')->get();
        $taxes = Tax::where('created_by', $creatorId)->orderBy('name')->get();

        return view('billing-contracts.amend', compact('contract', 'customers', 'products', 'taxes'));
    }

    public function amend(Request $request, int $id)
    {
        if (! Auth::user()->can('manage billing contracts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
            $contract = $this->service->amend(
                $contract,
                $this->contractInput($request),
                (int) Auth::id(),
                (string) $request->input('effective_date', now()->format('Y-m-d')),
                (string) $request->input('reason', '')
            );
        } catch (BillingContractException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $contract->id)
            ->with('success', __('Billing contract amended. Future pending occurrences were regenerated.'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function lifecycle(int $id, string $permission, callable $action, string $successMessage)
    {
        if (! Auth::user()->can($permission)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $contract = $this->contractForCompany((int) Auth::user()->creatorId(), $id);
            $contract = $action($contract);
        } catch (BillingContractException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing-contracts.show', $contract->id)->with('success', $successMessage);
    }

    protected function contractForCompany(int $creatorId, int $id): BillingContract
    {
        $contract = BillingContract::where('id', $id)->where('created_by', $creatorId)->first();

        if ($contract === null) {
            throw new BillingContractException('Contract not found for this company.');
        }

        return $contract;
    }

    protected function canView(): bool
    {
        $user = Auth::user();

        return $user->can('manage billing contracts') || $user->can('approve billing contracts');
    }

    /** Normalise the contract form into service input. */
    protected function contractInput(Request $request): array
    {
        $input = $request->only([
            'customer_id', 'title', 'description', 'start_date', 'end_date',
            'currency', 'exchange_rate', 'billing_frequency',
            'custom_interval', 'custom_unit', 'anchor_day', 'billing_mode',
            'payment_terms_days', 'approval_mode', 'partial_period_rule',
            'renewal_notice_days', 'delivery_to', 'delivery_cc', 'notes',
            'branch_id',
        ]);

        // Checkboxes are absent when unchecked: always send a value.
        $input['proration_enabled'] = $request->boolean('proration_enabled');
        $input['auto_renew'] = $request->boolean('auto_renew');
        $input['delivery_auto_email'] = $request->boolean('delivery_auto_email');

        $input['lines'] = collect($request->input('lines', []))
            ->filter(fn ($line) => is_array($line) && trim((string) ($line['description'] ?? '')) !== '')
            ->values()
            ->all();

        return $input;
    }
}
