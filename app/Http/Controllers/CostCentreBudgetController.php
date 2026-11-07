<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Models\ChartOfAccount;
use App\Models\CostCentre;
use App\Models\CostCentreBudget;
use App\Models\CostCentreBudgetApproval;
use App\Models\CostCentreBudgetDelegation;
use App\Models\Project;
use App\Services\BudgetControlService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Cost centre budgets + budget approvals (Cost Centre Phase 5, plan
 * §9, spec §9). Budgets are per company + fiscal year + month +
 * centre (+ optional GL account / project). Only approved budgets
 * limit spending; a draft budget counts as zero.
 */
class CostCentreBudgetController extends Controller
{
    public function __construct(
        protected BudgetControlService $budgets = new BudgetControlService(),
    ) {}

    // ------------------------------------------------------------------
    // Budgets — list / create / edit / approve / reopen
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $fiscalYear = (int) ($request->input('fiscal_year') ?: date('Y'));
        $period = $request->filled('period') ? (int) $request->input('period') : null;
        $centreId = $request->filled('cost_centre_id') ? (int) $request->input('cost_centre_id') : null;

        $budgets = CostCentreBudget::forCompany($creatorId)
            ->where('fiscal_year', $fiscalYear)
            ->when($period !== null, fn ($q) => $q->where('period', $period))
            ->when($centreId !== null, fn ($q) => $q->where('cost_centre_id', $centreId))
            ->orderBy('cost_centre_id')
            ->orderBy('period')
            ->get();

        // One figures() call per row: each is one month-range actuals
        // query plus a commitment sum. Lists are small (one row per
        // budget), so no bulk loader is needed here.
        $rows = [];
        foreach ($budgets as $budget) {
            $figures = $this->budgets->figures(
                $creatorId,
                (int) $budget->cost_centre_id,
                (int) $budget->fiscal_year,
                (int) $budget->period,
                $budget->gl_account_id !== null ? (int) $budget->gl_account_id : null,
                $budget->project_id !== null ? (int) $budget->project_id : null,
            );
            $rows[] = ['budget' => $budget, 'figures' => $figures];
        }

        return view('cost-centres.budgets.index', [
            'rows' => $rows,
            'fiscalYear' => $fiscalYear,
            'selectedPeriod' => $period,
            'selectedCentreId' => $centreId,
            'centres' => $this->centres($creatorId),
        ]);
    }

    public function create()
    {
        if (! Auth::user()->can('manage cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('cost-centres.budgets.form', array_merge($this->formData($creatorId), [
            'budget' => new CostCentreBudget(['fiscal_year' => (int) date('Y'), 'period' => (int) date('n')]),
        ]));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $validated = $this->validateForm($request, $creatorId);

        try {
            $budget = $this->budgets->createBudget($creatorId, $validated, (int) Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cost-centre-budgets.index', ['fiscal_year' => $budget->fiscal_year])
            ->with('success', __('Budget created as a draft. Approve it before it limits spending.'));
    }

    public function edit(CostCentreBudget $budget)
    {
        if (! Auth::user()->can('manage cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->assertCompany($budget, $creatorId);

        return view('cost-centres.budgets.form', array_merge($this->formData($creatorId), [
            'budget' => $budget,
        ]));
    }

    public function update(Request $request, CostCentreBudget $budget)
    {
        if (! Auth::user()->can('manage cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->assertCompany($budget, $creatorId);
        $validated = $this->validateForm($request, $creatorId, true);

        try {
            $this->budgets->updateBudget($budget, $creatorId, $validated, (int) Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cost-centre-budgets.index', ['fiscal_year' => $budget->fiscal_year])
            ->with('success', __('Budget updated.'));
    }

    public function approve(CostCentreBudget $budget)
    {
        if (! Auth::user()->can('approve cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->assertCompany($budget, $creatorId);

        try {
            $this->budgets->approveBudget($budget, $creatorId, (int) Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Budget approved.'));
    }

    public function reopen(CostCentreBudget $budget)
    {
        if (! Auth::user()->can('manage cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->assertCompany($budget, $creatorId);

        try {
            $this->budgets->reopenBudget($budget, $creatorId, (int) Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Budget reopened as a draft.'));
    }

    // ------------------------------------------------------------------
    // Approval requests — list / decide
    // ------------------------------------------------------------------

    public function approvals(Request $request)
    {
        if (! Auth::user()->can('approve cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $status = $request->input('status', 'pending');

        $approvals = CostCentreBudgetApproval::forCompany($creatorId)
            ->when(in_array($status, CostCentreBudgetApproval::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();

        return view('cost-centres.budget-approvals.index', [
            'approvals' => $approvals,
            'selectedStatus' => $status,
            'delegations' => CostCentreBudgetDelegation::forCompany($creatorId)->orderByDesc('id')->get(),
            'users' => $this->companyUsers($creatorId),
        ]);
    }

    public function decide(Request $request, CostCentreBudgetApproval $approval)
    {
        if (! Auth::user()->can('approve cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in([CostCentreBudgetApproval::DECISION_APPROVED, CostCentreBudgetApproval::DECISION_REJECTED])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->budgets->decide($approval, $creatorId, (int) Auth::id(), $validated['decision'], $validated['note'] ?? null);
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $validated['decision'] === CostCentreBudgetApproval::DECISION_APPROVED
            ? __('Request approved.')
            : __('Request rejected.'));
    }

    // ------------------------------------------------------------------
    // Delegations — list lives on the approvals page
    // ------------------------------------------------------------------

    public function storeDelegation(Request $request)
    {
        if (! Auth::user()->can('approve cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $validated = $request->validate([
            'delegator_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })],
            'delegate_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })],
            'role' => ['required', 'string', Rule::in(CostCentreBudgetDelegation::ROLES)],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        try {
            $this->budgets->createDelegation(
                $creatorId,
                (int) $validated['delegator_user_id'],
                (int) $validated['delegate_user_id'],
                $validated['role'],
                ! empty($validated['valid_from']) ? Carbon::parse($validated['valid_from']) : null,
                ! empty($validated['valid_to']) ? Carbon::parse($validated['valid_to']) : null,
                (int) Auth::id(),
            );
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Delegation saved.'));
    }

    public function destroyDelegation(CostCentreBudgetDelegation $delegation)
    {
        if (! Auth::user()->can('approve cost centre budgets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->budgets->deleteDelegation($delegation, $creatorId);
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Delegation removed.'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function centres(int $creatorId)
    {
        return CostCentre::forCompany($creatorId)->active()->orderBy('code')->get(['id', 'code', 'name', 'parent_id']);
    }

    protected function companyUsers(int $creatorId)
    {
        return \App\Models\User::where(function ($q) use ($creatorId) {
            $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get(['id', 'name', 'email']);
    }

    protected function formData(int $creatorId): array
    {
        return [
            'centres' => $this->centres($creatorId),
            'accounts' => ChartOfAccount::where('created_by', $creatorId)->where('code', 'like', '6%')->orderBy('code')->get(['id', 'code', 'name']),
            'projects' => Project::where('created_by', $creatorId)->orderBy('project_name')->get(['id', 'project_name']),
        ];
    }

    protected function validateForm(Request $request, int $creatorId, bool $isUpdate = false): array
    {
        $rules = [
            'budget_amount' => ['required', 'numeric', 'min:0'],
        ];

        if (! $isUpdate) {
            $rules['fiscal_year'] = ['required', 'integer', 'min:2000', 'max:2100'];
            $rules['period'] = ['required', 'integer', 'min:1', 'max:12'];
            $rules['cost_centre_id'] = ['required', 'integer', Rule::exists('cost_centres', 'id')->where('created_by', $creatorId)];
            $rules['gl_account_id'] = ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('created_by', $creatorId)];
            $rules['project_id'] = ['nullable', 'integer', Rule::exists('projects', 'id')->where('created_by', $creatorId)];
        }

        return $request->validate($rules);
    }

    protected function assertCompany(CostCentreBudget $budget, int $creatorId): void
    {
        if ((int) $budget->created_by !== $creatorId) {
            abort(403, __('Permission denied.'));
        }
    }
}
