<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\ChartOfAccount;
use App\Models\CostCentre;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryPettyCashFund;
use App\Models\User;
use App\Services\TreasuryPettyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 5 — petty cash (plan §5).
 *
 * All rules live in TreasuryPettyCashService; this controller only
 * gates permissions, scopes to the company and turns service
 * exceptions into redirects. Expenses themselves are ordinary
 * Expense rows (PENDING → APPROVED → EXECUTED) with the fund and
 * the cost centre on the line.
 */
class TreasuryPettyCashController extends Controller
{
    protected TreasuryPettyCashService $service;

    public function __construct(?TreasuryPettyCashService $service = null)
    {
        $this->service = $service ?? new TreasuryPettyCashService();
    }

    public function index()
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $funds = TreasuryPettyCashFund::where('created_by', $creatorId)
            ->with(['custodian', 'financialAccount'])
            ->orderByDesc('id')
            ->paginate(25);

        $summaries = [];
        foreach ($funds as $fund) {
            $summaries[$fund->id] = $this->service->fundSummary($fund);
        }

        return view('treasury.petty-cash.index', compact('funds', 'summaries'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.petty-cash.form', [
            'fund' => null,
            'accounts' => $this->fundAccounts($creatorId),
            'custodians' => $this->companyUsers($creatorId),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'custodian_user_id' => 'required|integer',
            'financial_account_id' => 'required|integer',
            'branch_id' => 'nullable|integer',
            'limit_amount' => 'nullable|numeric|min:0',
            'replenishment_frequency' => 'nullable|in:weekly,monthly,on_demand',
        ]);

        try {
            $fund = $this->service->createFund(
                (int) Auth::user()->creatorId(),
                $request->only([
                    'custodian_user_id', 'financial_account_id', 'branch_id',
                    'limit_amount', 'replenishment_frequency',
                ]),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $fund)
            ->with('success', __('Petty-cash fund created.'));
    }

    public function show(TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $fund = $this->service->fundForCompany((int) Auth::user()->creatorId(), (int) $fund->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $fund->load(['custodian', 'financialAccount']);
        $summary = $this->service->fundSummary($fund);
        $expenses = Expense::where('treasury_petty_cash_fund_id', $fund->id)
            ->with(['expenseCategory', 'chartAccount'])
            ->orderByDesc('id')
            ->paginate(25);

        return view('treasury.petty-cash.show', compact('fund', 'summary', 'expenses'));
    }

    public function edit(TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $fund = $this->service->fundForCompany((int) Auth::user()->creatorId(), (int) $fund->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.petty-cash.form', [
            'fund' => $fund,
            'accounts' => $this->fundAccounts($creatorId),
            'custodians' => $this->companyUsers($creatorId),
        ]);
    }

    public function update(Request $request, TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'custodian_user_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'limit_amount' => 'nullable|numeric|min:0',
            'replenishment_frequency' => 'nullable|in:weekly,monthly,on_demand',
        ]);

        try {
            $fund = $this->service->fundForCompany((int) Auth::user()->creatorId(), (int) $fund->id);
            $fund = $this->service->updateFund(
                $fund,
                $request->only(['custodian_user_id', 'branch_id', 'limit_amount', 'replenishment_frequency']),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $fund)
            ->with('success', __('Petty-cash fund updated.'));
    }

    public function deactivate(TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $fund = $this->service->fundForCompany((int) Auth::user()->creatorId(), (int) $fund->id);
            $fund = $this->service->deactivateFund($fund, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $fund)
            ->with('success', __('Petty-cash fund deactivated.'));
    }

    public function reactivate(TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $fund = $this->service->fundForCompany((int) Auth::user()->creatorId(), (int) $fund->id);
            $fund = $this->service->reactivateFund($fund, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $fund)
            ->with('success', __('Petty-cash fund reactivated.'));
    }

    public function expenseForm(TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $fund = $this->service->fundForCompany((int) Auth::user()->creatorId(), (int) $fund->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.petty-cash.expense-form', [
            'fund' => $fund,
            'categories' => ExpenseCategory::where('created_by', $creatorId)->orderBy('name')->get(),
            'chartAccounts' => ChartOfAccount::where('created_by', $creatorId)->orderBy('code')->get(),
            'costCentres' => CostCentre::where('created_by', $creatorId)->where('status', 'active')->orderBy('code')->get(),
        ]);
    }

    public function recordExpense(Request $request, TreasuryPettyCashFund $fund)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'description' => 'nullable|string|max:1000',
            'amount' => 'required|numeric|min:0.0001',
            'expense_category_id' => 'required|integer',
            'chart_account_id' => 'required|integer',
            'cost_centre_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
        ]);

        try {
            $this->service->recordExpense(
                (int) Auth::user()->creatorId(),
                (int) $fund->id,
                $request->only([
                    'name', 'date', 'description', 'amount', 'expense_category_id',
                    'chart_account_id', 'cost_centre_id', 'branch_id',
                ]),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $fund)
            ->with('success', __('Petty-cash expense recorded as pending. A different user must approve it.'));
    }

    public function approveExpense(Expense $expense)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $expense = $this->service->approveExpense(
                (int) Auth::user()->creatorId(),
                (int) $expense->id,
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $expense->treasury_petty_cash_fund_id)
            ->with('success', __('Petty-cash expense approved. Execute it to move the money.'));
    }

    public function rejectExpense(Request $request, Expense $expense)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['reason' => 'nullable|string|max:1000']);

        try {
            $expense = $this->service->rejectExpense(
                (int) Auth::user()->creatorId(),
                (int) $expense->id,
                (int) Auth::user()->id,
                $request->input('reason')
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $expense->treasury_petty_cash_fund_id)
            ->with('success', __('Petty-cash expense rejected.'));
    }

    public function executeExpense(Request $request, Expense $expense)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'posting_date' => 'nullable|date',
            'exchange_rate' => 'nullable|numeric|min:0',
            'rate_source' => 'nullable|string|max:20',
            'rate_date' => 'nullable|date',
            'adjustment.authorised_by' => 'nullable|integer',
            'adjustment.reason' => 'nullable|string|max:1000',
        ]);

        $options = [];
        foreach (['posting_date', 'exchange_rate', 'rate_source', 'rate_date'] as $key) {
            if ($request->filled($key)) {
                $options[$key] = $request->input($key);
            }
        }
        if ($request->filled('adjustment.authorised_by') || $request->filled('adjustment.reason')) {
            $options['adjustment'] = [
                'authorised_by' => (int) $request->input('adjustment.authorised_by'),
                'reason' => (string) $request->input('adjustment.reason'),
            ];
        }

        try {
            $expense = $this->service->executeExpense(
                (int) Auth::user()->creatorId(),
                (int) $expense->id,
                (int) Auth::user()->id,
                $options
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $expense->treasury_petty_cash_fund_id)
            ->with('success', __('Petty-cash expense executed — the money left the fund.'));
    }

    public function returnUnspent(Request $request, Expense $expense)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.0001',
            'reason' => 'nullable|string|max:1000',
            'posting_date' => 'nullable|date',
            'adjustment.authorised_by' => 'nullable|integer',
            'adjustment.reason' => 'nullable|string|max:1000',
        ]);

        $options = [];
        if ($request->filled('posting_date')) {
            $options['posting_date'] = $request->input('posting_date');
        }
        if ($request->filled('adjustment.authorised_by') || $request->filled('adjustment.reason')) {
            $options['adjustment'] = [
                'authorised_by' => (int) $request->input('adjustment.authorised_by'),
                'reason' => (string) $request->input('adjustment.reason'),
            ];
        }

        try {
            $expense = $this->service->returnUnspent(
                (int) Auth::user()->creatorId(),
                (int) $expense->id,
                (string) $request->input('amount'),
                (int) Auth::user()->id,
                $request->input('reason'),
                $options
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-petty-cash.show', $expense->treasury_petty_cash_fund_id)
            ->with('success', __('Unspent money returned to the fund.'));
    }

    /** Active accounts a fund can sit on. */
    protected function fundAccounts(int $creatorId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }

    /** Company users who can be a custodian. */
    protected function companyUsers(int $creatorId)
    {
        return User::where(function ($query) use ($creatorId) {
            $query->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get();
    }
}
