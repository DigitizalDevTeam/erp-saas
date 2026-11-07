<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\ChartOfAccount;
use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\TreasuryAdvance;
use App\Models\TreasuryAdvanceExpenseLine;
use App\Models\TreasuryFinancialAccount;
use App\Models\Vender;
use App\Services\TreasuryAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 6 — employee / supplier advances (plan §5, §6).
 *
 * All rules live in TreasuryAdvanceService; this controller only
 * gates permissions, scopes to the company and turns service
 * exceptions into redirects.
 */
class TreasuryAdvanceController extends Controller
{
    protected TreasuryAdvanceService $service;

    public function __construct(?TreasuryAdvanceService $service = null)
    {
        $this->service = $service ?? new TreasuryAdvanceService();
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryAdvance::where('created_by', $creatorId)
            ->with(['operation', 'sourceAccount'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('recipient_type')) {
            $query->where('recipient_type', $request->recipient_type);
        }
        if ($request->filled('overdue')) {
            $query->whereIn('state', TreasuryAdvance::DISBURSED_STATES)
                ->whereNotNull('due_date')
                ->where('due_date', '<', date('Y-m-d'))
                ->where('amount_outstanding', '>', 0);
        }

        $advances = $query->paginate(25);

        return view('treasury.advances.index', compact('advances'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.advances.form', [
            'advance' => null,
            'accounts' => $this->sourceAccounts($creatorId),
            'employees' => Employee::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Vender::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'baseCurrency' => \App\Services\TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate($this->formRules());

        try {
            $advance = $this->service->createAdvance(
                (int) Auth::user()->creatorId(),
                $this->advanceInput($request),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance requested as a draft. Submit it for approval.'));
    }

    public function show(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->advanceForCompany((int) Auth::user()->creatorId(), (int) $advance->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $advance->load(['operation', 'sourceAccount', 'lines.chartAccount', 'lines.costCentre']);

        return view('treasury.advances.show', [
            'advance' => $advance,
            'chartAccounts' => ChartOfAccount::where('created_by', $creatorId)->orderBy('code')->get(['id', 'code', 'name']),
            'costCentres' => CostCentre::where('created_by', $creatorId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'users' => \App\Models\User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->advanceForCompany((int) Auth::user()->creatorId(), (int) $advance->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if (! $advance->isDraft()) {
            return redirect()->route('treasury-advances.show', $advance)
                ->with('error', __('Only a draft advance can be edited.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.advances.form', [
            'advance' => $advance,
            'accounts' => $this->sourceAccounts($creatorId),
            'employees' => Employee::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Vender::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'baseCurrency' => \App\Services\TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function update(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate($this->formRules());

        try {
            $advance = $this->service->advanceForCompany((int) Auth::user()->creatorId(), (int) $advance->id);
            $advance = $this->service->updateAdvance($advance, $this->advanceInput($request), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance draft updated.'));
    }

    public function submit(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->submitAdvance((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance submitted for approval.'));
    }

    public function approve(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->approveAdvance((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance approved. It can now be disbursed.'));
    }

    public function reject(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        try {
            $advance = $this->service->rejectAdvance((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id, (string) $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance rejected.'));
    }

    public function returnToDraft(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->returnAdvanceToDraft((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance returned to draft.'));
    }

    public function cancel(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->cancelAdvance((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance cancelled.'));
    }

    public function disburseForm(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $preview = $this->service->previewDisburse((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.advances.disburse', array_merge($preview, [
            'users' => \App\Models\User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name']),
        ]));
    }

    public function disburse(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $result = $this->service->disburseAdvance(
                (int) Auth::user()->creatorId(),
                (int) $advance->id,
                (int) Auth::user()->id,
                $this->postingOptions($request)
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $result['advance'])
            ->with('success', __('Advance disbursed. Reference: ') . $result['operation']->reference);
    }

    public function addLine(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate($this->lineRules());

        try {
            $this->service->addLine(
                (int) Auth::user()->creatorId(),
                (int) $advance->id,
                $request->only(['expense_date', 'counterparty', 'category', 'document', 'amount', 'chart_account_id', 'cost_centre_id', 'evidence']),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Expense line submitted for review.'));
    }

    public function lineForm(TreasuryAdvanceExpenseLine $line)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $line = $this->service->lineForCompany((int) Auth::user()->creatorId(), (int) $line->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if (! $line->isReturned()) {
            return redirect()->route('treasury-advances.show', $line->advance_id)
                ->with('error', __('Only a line returned for correction can be edited.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.advances.line-form', [
            'line' => $line,
            'advance' => $line->advance,
            'chartAccounts' => ChartOfAccount::where('created_by', $creatorId)->orderBy('code')->get(['id', 'code', 'name']),
            'costCentres' => CostCentre::where('created_by', $creatorId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function updateLine(Request $request, TreasuryAdvanceExpenseLine $line)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate($this->lineRules());

        try {
            $line = $this->service->lineForCompany((int) Auth::user()->creatorId(), (int) $line->id);
            $line = $this->service->updateLine(
                $line,
                $request->only(['expense_date', 'counterparty', 'category', 'document', 'amount', 'chart_account_id', 'cost_centre_id', 'evidence']),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $line->advance_id)
            ->with('success', __('Expense line updated. Resubmit it for review.'));
    }

    public function resubmitLine(TreasuryAdvanceExpenseLine $line)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $line = $this->service->resubmitLine((int) Auth::user()->creatorId(), (int) $line->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $line->advance_id)
            ->with('success', __('Expense line resubmitted for review.'));
    }

    public function reviewLine(Request $request, TreasuryAdvanceExpenseLine $line)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'decision' => 'required|in:approve,reject,return',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->service->reviewLine(
                (int) Auth::user()->creatorId(),
                (int) $line->id,
                (string) $request->input('decision'),
                (int) Auth::user()->id,
                $request->input('reason'),
                $this->postingOptions($request)
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $messages = [
            'approve' => __('Expense line approved and posted.'),
            'reject' => __('Expense line rejected.'),
            'return' => __('Expense line returned for correction.'),
        ];

        return redirect()->route('treasury-advances.show', $result['advance'])
            ->with('success', $messages[(string) $request->input('decision')] ?? __('Line reviewed.'));
    }

    public function returnUnspentForm(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->advanceForCompany((int) Auth::user()->creatorId(), (int) $advance->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if (! $advance->isDisbursed()) {
            return redirect()->route('treasury-advances.show', $advance)
                ->with('error', __('Unspent money can only be returned while the advance is disbursed.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.advances.return', [
            'advance' => $advance,
            'users' => \App\Models\User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function returnUnspent(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.0001',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->service->returnUnspent(
                (int) Auth::user()->creatorId(),
                (int) $advance->id,
                (string) $request->input('amount'),
                (int) Auth::user()->id,
                $request->input('reason'),
                $this->postingOptions($request)
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $result['advance'])
            ->with('success', __('Unspent money returned. Reference: ') . $result['operation']->reference);
    }

    public function approveReimbursement(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->approveReimbursement((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id, $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Reimbursement approved. It can now be paid.'));
    }

    public function rejectReimbursement(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        try {
            $advance = $this->service->rejectReimbursement((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id, (string) $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Reimbursement rejected. The excess stays the recipient cost.'));
    }

    public function payReimbursement(Request $request, TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $result = $this->service->payReimbursement(
                (int) Auth::user()->creatorId(),
                (int) $advance->id,
                (int) Auth::user()->id,
                $this->postingOptions($request)
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $result['advance'])
            ->with('success', __('Reimbursement paid. Reference: ') . $result['operation']->reference);
    }

    public function close(TreasuryAdvance $advance)
    {
        if (! Auth::user()->can('manage treasury advances')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $advance = $this->service->closeAdvance((int) Auth::user()->creatorId(), (int) $advance->id, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-advances.show', $advance)
            ->with('success', __('Advance closed.'));
    }

    // ------------------------------------------------------------------
    // Form helpers
    // ------------------------------------------------------------------

    protected function formRules(): array
    {
        return [
            'source_account_id' => 'required|integer',
            'recipient_type' => 'required|in:employee,supplier',
            'employee_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'purpose' => 'nullable|string|max:1000',
            'amount_requested' => 'required|numeric|min:0.0001',
            'exchange_rate' => 'nullable|numeric|min:0.0000000001',
            'rate_source' => 'nullable|string|max:20',
            'rate_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'branch_id' => 'nullable|integer',
            'request_date' => 'nullable|date',
        ];
    }

    protected function lineRules(): array
    {
        return [
            'expense_date' => 'required|date',
            'counterparty' => 'nullable|string|max:150',
            'category' => 'nullable|string|max:100',
            'document' => 'nullable|string|max:150',
            'amount' => 'required|numeric|min:0.0001',
            'chart_account_id' => 'required|integer',
            'cost_centre_id' => 'nullable|integer',
            'evidence' => 'nullable|string|max:1000',
        ];
    }

    /** Resolve the recipient id from the matching select (employee / supplier). */
    protected function advanceInput(Request $request): array
    {
        $input = $request->only([
            'source_account_id', 'recipient_type', 'purpose', 'amount_requested',
            'exchange_rate', 'rate_source', 'rate_date', 'due_date', 'branch_id', 'request_date',
        ]);
        $input['recipient_id'] = $request->input('recipient_type') === 'employee'
            ? (int) $request->input('employee_id', 0)
            : (int) $request->input('supplier_id', 0);

        return $input;
    }

    /** Posting options (CA17): an adjustment needs an authoriser + reason. */
    protected function postingOptions(Request $request): array
    {
        $options = ['posting_date' => $request->input('posting_date')];

        if ($request->filled('adjustment_authorised_by') && $request->filled('adjustment_reason')) {
            $options['adjustment'] = [
                'authorised_by' => (int) $request->input('adjustment_authorised_by'),
                'reason' => (string) $request->input('adjustment_reason'),
            ];
        }

        return $options;
    }

    protected function sourceAccounts(int $creatorId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', 'active')
            ->where('type', '!=', TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT)
            ->orderBy('code')
            ->get();
    }
}
