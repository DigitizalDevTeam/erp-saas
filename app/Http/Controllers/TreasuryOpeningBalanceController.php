<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOpeningBalance;
use App\Services\TreasuryAccountService;
use App\Services\TreasurySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 0 — opening balances (plan §5).
 *
 * Draft → approved. A confirmed row is never edited: it is corrected
 * only by an authorised reversal (new row, negated amounts, linked to
 * the original). Import is a CSV file; every row becomes a draft under
 * one batch reference, and the batch is approved row by row.
 */
class TreasuryOpeningBalanceController extends Controller
{
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

        $balances = TreasuryOpeningBalance::where('created_by', $creatorId)
            ->with(['financialAccount.bankAccount'])
            ->orderByDesc('id')
            ->paginate(50);

        return view('treasury.opening-balances.index', compact('balances'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.opening-balances.form', [
            'balance' => null,
            'accounts' => $this->accounts($creatorId),
            'baseCurrency' => TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $validator = \Validator::make($request->all(), [
            'financial_account_id' => 'required|integer',
            'amount' => 'required|numeric',
            'cut_off_date' => 'required|date',
            'rate_source' => 'nullable|in:' . implode(',', TreasuryOpeningBalance::RATE_SOURCES),
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->errors()->first());
        }

        try {
            $balance = $this->service->createOpeningBalance(
                $creatorId,
                (int) $request->input('financial_account_id'),
                $request->all(),
                (int) Auth::id()
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-opening-balances.index')
            ->with('success', __('Opening balance :ref saved as draft.', ['ref' => $balance->batch_reference]));
    }

    public function edit(TreasuryOpeningBalance $balance)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $balance->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $balance->isDraft()) {
            return redirect()->route('treasury-opening-balances.index')
                ->with('error', __('A confirmed opening balance cannot be edited. Reverse it and create a corrected one instead.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.opening-balances.form', [
            'balance' => $balance->load('financialAccount.bankAccount'),
            'accounts' => $this->accounts($creatorId),
            'baseCurrency' => TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function update(Request $request, TreasuryOpeningBalance $balance)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $balance->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'cut_off_date' => 'required|date',
            'rate_source' => 'nullable|in:' . implode(',', TreasuryOpeningBalance::RATE_SOURCES),
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->errors()->first());
        }

        try {
            $this->service->updateOpeningBalance($balance, $request->all());
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-opening-balances.index')
            ->with('success', __('Opening balance draft updated.'));
    }

    public function destroy(TreasuryOpeningBalance $balance)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $balance->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->deleteOpeningBalance($balance);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-opening-balances.index')
            ->with('success', __('Draft opening balance deleted.'));
    }

    public function approve(TreasuryOpeningBalance $balance)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $balance->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->approveOpeningBalance($balance, (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-opening-balances.index')
            ->with('success', __('Opening balance approved. The account currency is now locked.'));
    }

    public function reverse(Request $request, TreasuryOpeningBalance $balance)
    {
        if (! Auth::user()->can('reverse treasury operations')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $balance->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'reversal_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first());
        }

        try {
            $this->service->reverseOpeningBalance($balance, (string) $request->input('reversal_reason'), (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-opening-balances.index')
            ->with('success', __('Opening balance reversed. The original is kept; the reversal nets it to zero.'));
    }

    public function importForm()
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('treasury.opening-balances.import');
    }

    /**
     * CSV import. Columns: code, amount, cut_off_date, currency,
     * exchange_rate, rate_source, rate_date, includes_liabilities,
     * includes_receivables. Every row becomes a draft under one batch
     * reference; nothing is created when any row fails.
     */
    public function importStore(Request $request)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first());
        }

        $rows = $this->parseCsv($request->file('file')->getRealPath());

        if ($rows === null) {
            return redirect()->back()->with('error', __('The CSV file needs a header row with code, amount and cut_off_date.'));
        }

        try {
            $created = $this->service->importOpeningBalances(
                (int) Auth::user()->creatorId(),
                $rows,
                (int) Auth::id(),
                $request->input('batch_reference') ?: null
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-opening-balances.index')
            ->with('success', __('Imported :count opening balance drafts.', ['count' => count($created)]));
    }

    /** @return array<int, array<string, mixed>>|null */
    protected function parseCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return null;
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        foreach (['code', 'amount', 'cut_off_date'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);

                return null;
            }
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || count(array_filter($line, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $line[$i] ?? null;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    protected function accounts(int $creatorId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }
}
