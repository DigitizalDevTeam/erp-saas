<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\Transaction;
use App\Services\Accounting\BankReconciliation\ReconciliationMatcher;
use App\Services\Accounting\BankReconciliation\StatementImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function index()
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $sessions = BankReconciliationSession::query()
            ->where('created_by', $creatorId)
            ->with('bankAccount')
            ->orderByDesc('id')
            ->paginate(15);

        return view('bankReconciliation.index', compact('sessions'));
    }

    public function create()
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $bankAccountOptions = BankAccount::where('created_by', \Auth::user()->creatorId())
            ->orderBy('bank_name')
            ->get()
            ->mapWithKeys(function ($b) {
                $n = $b->account_number ? ' ('.$b->account_number.')' : '';

                return [$b->id => $b->bank_name.' — '.$b->holder_name.$n];
            })
            ->prepend(__('Select'), '');

        $presets = collect(config('bank_reconciliation.presets', []))
            ->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'] ?? $key])
            ->values();

        $defaultTolerance = (int) config('bank_reconciliation.date_tolerance_days', 3);

        return view('bankReconciliation.create', compact('bankAccountOptions', 'presets', 'defaultTolerance'));
    }

    public function store(Request $request, StatementImportService $importService, ReconciliationMatcher $matcher)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $presetKey = $request->input('preset_key', 'bci_mozambique');

        $creatorId = \Auth::user()->creatorId();

        $rules = [
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('created_by', $creatorId)],
            'preset_key' => 'required|string|max:64',
            'statement' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'date_tolerance_days' => 'nullable|integer|min:0|max:30',
        ];

        if ($presetKey === 'custom') {
            $rules = array_merge($rules, [
                'first_data_row' => 'required|integer|min:1|max:10000',
                'date_col' => 'required|integer|min:0|max:255',
                'description_col' => 'required|integer|min:0|max:255',
                'amount_mode' => 'required|in:signed_column,debit_credit',
                'amount_col' => 'required_if:amount_mode,signed_column|nullable|integer|min:0|max:255',
                'debit_col' => 'required_if:amount_mode,debit_credit|nullable|integer|min:0|max:255',
                'credit_col' => 'required_if:amount_mode,debit_credit|nullable|integer|min:0|max:255',
                'balance_col' => 'nullable|integer|min:0|max:255',
            ]);
        }

        $validated = $request->validate($rules);

        $bank = BankAccount::findOrFail($validated['bank_account_id']);

        if (! array_key_exists($presetKey, config('bank_reconciliation.presets', []))) {
            return redirect()->back()->with('error', __('Invalid preset.'))->withInput();
        }

        $customMapping = null;
        if ($presetKey === 'custom') {
            $customMapping = [
                'data_start_row' => (int) $validated['first_data_row'] - 1,
                'header_row' => max(0, (int) $validated['first_data_row'] - 2),
                'date_col' => (int) $validated['date_col'],
                'description_col' => (int) $validated['description_col'],
                'amount_mode' => $validated['amount_mode'],
                'amount_col' => $validated['amount_col'] ?? null,
                'debit_col' => $validated['debit_col'] ?? null,
                'credit_col' => $validated['credit_col'] ?? null,
                'balance_col' => $validated['balance_col'] ?? null,
            ];
        }

        $path = $request->file('statement')->store('bank-statements/'.$creatorId, 'local');
        if ($path === false) {
            return redirect()->back()
                ->with('error', __('Could not save the upload. Check permissions on the storage folder.'))
                ->withInput();
        }

        $session = new BankReconciliationSession;
        $session->bank_account_id = $bank->id;
        $session->preset_key = $presetKey;
        $session->status = 'draft';
        $session->original_filename = $request->file('statement')->getClientOriginalName();
        $session->stored_path = $path;
        $session->date_tolerance_days = $validated['date_tolerance_days'] ?? (int) config('bank_reconciliation.date_tolerance_days', 3);
        $session->created_by = $creatorId;
        $session->user_id = \Auth::id();
        $session->save();

        $absolute = Storage::disk('local')->path($path);

        if (! Storage::disk('local')->exists($path)) {
            $session->delete();

            return redirect()->back()
                ->with('error', __('The uploaded file could not be saved. Check that :path is writable.', ['path' => storage_path('app')]))
                ->withInput();
        }

        try {
            $importService->import($session, $absolute, $presetKey, $customMapping);
        } catch (\Throwable $e) {
            $session->delete();
            Storage::disk('local')->delete($path);

            return redirect()->back()
                ->with('error', $e->getMessage())
                ->withInput();
        }

        $matcher->runAutoMatch($session->fresh());

        return redirect()
            ->route('bank-reconciliation.show', $session)
            ->with('success', __('Statement imported and auto-matching completed.'));
    }

    public function show(BankReconciliationSession $bankReconciliationSession)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $session = $bankReconciliationSession->load('bankAccount');

        $lines = BankStatementLine::where('session_id', $session->id)
            ->with(['reconciliationMatch.erpTransaction'])
            ->orderBy('row_index')
            ->get();

        $matched = $lines->filter(fn ($l) => $l->reconciliationMatch !== null);
        $unmatchedStatement = $lines->filter(fn ($l) => $l->reconciliationMatch === null);

        $usedTxIds = BankReconciliationMatch::where('session_id', $session->id)
            ->pluck('transaction_id')
            ->filter()
            ->all();

        $tol = (int) $session->date_tolerance_days;
        $from = $session->period_from ? \Carbon\Carbon::parse($session->period_from)->subDays($tol)->toDateString() : null;
        $to = $session->period_to ? \Carbon\Carbon::parse($session->period_to)->addDays($tol)->toDateString() : null;

        $unmatchedErp = collect();
        if ($from && $to) {
            $unmatchedErp = Transaction::query()
                ->where('created_by', $session->created_by)
                ->where('account', $session->bank_account_id)
                ->whereBetween('date', [$from, $to])
                ->whereNotIn('id', $usedTxIds)
                ->orderByDesc('date')
                ->limit(500)
                ->get();
        }

        $stats = [
            'lines' => $lines->count(),
            'matched' => $matched->count(),
            'unmatched_statement' => $unmatchedStatement->count(),
            'unmatched_erp' => $unmatchedErp->count(),
        ];

        return view('bankReconciliation.show', compact('session', 'matched', 'unmatchedStatement', 'unmatchedErp', 'stats'));
    }

    public function autoMatch(BankReconciliationSession $bankReconciliationSession, ReconciliationMatcher $matcher)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $result = $matcher->runAutoMatch($bankReconciliationSession);

        return redirect()
            ->route('bank-reconciliation.show', $bankReconciliationSession)
            ->with('success', __('Auto-matched :n new row(s).', ['n' => $result['auto_matched'] ?? 0]));
    }

    public function manualMatch(Request $request, BankReconciliationSession $bankReconciliationSession)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $data = $request->validate([
            'statement_line_id' => [
                'required',
                Rule::exists('bank_statement_lines', 'id')->where('session_id', $bankReconciliationSession->id),
            ],
            'transaction_id' => 'required|exists:transactions,id',
        ]);

        $line = BankStatementLine::where('id', $data['statement_line_id'])
            ->where('session_id', $bankReconciliationSession->id)
            ->firstOrFail();

        $tx = Transaction::where('id', $data['transaction_id'])
            ->where('created_by', $bankReconciliationSession->created_by)
            ->where('account', $bankReconciliationSession->bank_account_id)
            ->firstOrFail();

        $existing = BankReconciliationMatch::where('session_id', $bankReconciliationSession->id)
            ->where('transaction_id', $tx->id)
            ->first();

        if ($existing && (int) $existing->statement_line_id !== (int) $line->id) {
            return redirect()->back()->with('error', __('This ERP transaction is already linked to another line in this session.'));
        }

        BankReconciliationMatch::updateOrCreate(
            [
                'session_id' => $bankReconciliationSession->id,
                'statement_line_id' => $line->id,
            ],
            [
                'transaction_id' => $tx->id,
                'match_type' => 'manual',
            ]
        );

        return redirect()->back()->with('success', __('Manual match saved.'));
    }

    public function unmatch(Request $request, BankReconciliationSession $bankReconciliationSession)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $data = $request->validate([
            'statement_line_id' => [
                'required',
                Rule::exists('bank_statement_lines', 'id')->where('session_id', $bankReconciliationSession->id),
            ],
        ]);

        BankReconciliationMatch::where('session_id', $bankReconciliationSession->id)
            ->where('statement_line_id', $data['statement_line_id'])
            ->delete();

        return redirect()->back()->with('success', __('Match removed.'));
    }

    protected function authorizeSession(BankReconciliationSession $session): void
    {
        if ((int) $session->created_by !== (int) \Auth::user()->creatorId()) {
            abort(403);
        }
    }
}
