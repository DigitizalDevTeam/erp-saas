<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\Customer;
use App\Models\ProductService;
use App\Models\Tax;
use App\Services\BillingContractService;
use App\Services\BillingOccurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function __construct(BillingContractService $service, BillingOccurrenceService $occurrences)
    {
        $this->service = $service;
        $this->occurrences = $occurrences;
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

        $contracts = $query->paginate(25);
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get();

        return view('billing-contracts.index', compact('contracts', 'customers'));
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

        return view('billing-contracts.show', compact('contract', 'occurrences'));
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
        return $this->lifecycle($id, 'manage billing contracts', fn (BillingContract $c) => $this->service->activate($c, (int) Auth::id()), __('Billing contract activated.'));
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
