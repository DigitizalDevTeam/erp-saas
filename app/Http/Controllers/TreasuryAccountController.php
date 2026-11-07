<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\TreasuryFinancialAccount;
use App\Models\User;
use App\Services\TreasuryAccountService;
use App\Services\TreasurySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 0 — financial accounts (plan §5).
 *
 * The account master extends the existing `bank_accounts` table: the
 * bank account row holds holder/institution/account number/chart
 * account, the treasury row holds type, currency, branch, limits,
 * negative-balance policy and status. All rules live in
 * TreasuryAccountService; this controller only gates permissions,
 * scopes to the company and turns service exceptions into redirects.
 */
class TreasuryAccountController extends Controller
{
    public const CURRENCIES = ['MZN', 'USD', 'EUR', 'ZAR', 'GBP', 'AED', 'INR', 'CNY'];

    protected TreasuryAccountService $service;

    public function __construct(TreasuryAccountService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $accounts = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->with(['bankAccount', 'branch'])
            ->orderBy('code')
            ->get();

        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->id] = $this->service->postedBalance($account);
        }

        return view('treasury.accounts.index', compact('accounts', 'balances'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('treasury.accounts.form', [
            'account' => null,
            'branches' => $this->branches(),
            'chartAccounts' => $this->chartAccounts(),
            'users' => $this->companyUsers(),
            'currencies' => self::CURRENCIES,
            'baseCurrency' => TreasurySettings::baseCurrency((int) Auth::user()->creatorId()),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $validator = \Validator::make($request->all(), [
            'code' => 'required|max:50',
            'type' => 'required|in:' . implode(',', TreasuryFinancialAccount::TYPES),
            'currency' => 'required|size:3',
            'account_holder' => 'required',
            'institution' => 'required',
            'account_number' => 'required',
            'negative_balance_policy' => 'nullable|in:' . implode(',', TreasuryFinancialAccount::NEGATIVE_BALANCE_POLICIES),
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->errors()->first());
        }

        try {
            $account = $this->service->createAccount($creatorId, $request->all(), (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-accounts.index')
            ->with('success', __('Financial account :code created.', ['code' => $account->code]));
    }

    public function edit(TreasuryFinancialAccount $account)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        if ((int) $account->created_by !== $creatorId) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('treasury.accounts.form', [
            'account' => $account->load(['bankAccount', 'branch']),
            'branches' => $this->branches(),
            'chartAccounts' => $this->chartAccounts(),
            'users' => $this->companyUsers(),
            'currencies' => self::CURRENCIES,
            'baseCurrency' => TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function update(Request $request, TreasuryFinancialAccount $account)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        if ((int) $account->created_by !== $creatorId) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'code' => 'required|max:50',
            'type' => 'required|in:' . implode(',', TreasuryFinancialAccount::TYPES),
            'currency' => 'required|size:3',
            'account_holder' => 'required',
            'institution' => 'required',
            'account_number' => 'required',
            'negative_balance_policy' => 'nullable|in:' . implode(',', TreasuryFinancialAccount::NEGATIVE_BALANCE_POLICIES),
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->errors()->first());
        }

        try {
            $this->service->updateAccount($account, $request->all(), (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-accounts.index')
            ->with('success', __('Financial account updated.'));
    }

    public function deactivate(TreasuryFinancialAccount $account)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $account->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->deactivateAccount($account, (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-accounts.index')
            ->with('success', __('Financial account deactivated. New activity is blocked; reporting is kept.'));
    }

    public function reactivate(TreasuryFinancialAccount $account)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $account->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->service->reactivateAccount($account, (int) Auth::id());

        return redirect()->route('treasury-accounts.index')
            ->with('success', __('Financial account reactivated.'));
    }

    /**
     * Account statement: pre-period balance + running balance.
     * Phase 0 movements are the confirmed opening balances only.
     */
    public function statement(Request $request, TreasuryFinancialAccount $account)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $account->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $from = $request->input('from');
        $to = $request->input('to');
        $statement = $this->service->statement($account->load('bankAccount'), $from ?: null, $to ?: null);

        return view('treasury.accounts.statement', compact('account', 'statement', 'from', 'to'));
    }

    protected function branches()
    {
        return Branch::where('created_by', (int) Auth::user()->creatorId())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function chartAccounts()
    {
        return ChartOfAccount::where('created_by', (int) Auth::user()->creatorId())
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($account) => [$account->id => $account->code . ' — ' . $account->name]);
    }

    protected function companyUsers()
    {
        $creatorId = (int) Auth::user()->creatorId();

        return User::where(function ($q) use ($creatorId) {
            $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get(['id', 'name', 'email']);
    }
}
