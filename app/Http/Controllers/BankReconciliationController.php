<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\Transaction;
use App\Models\TreasuryOperation;
use App\Services\Accounting\BankReconciliation\ReconciliationMatcher;
use App\Services\Accounting\BankReconciliation\StatementImportService;
use App\Services\TreasuryReconciliationService;
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
            ->withCount('statementLines')
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

        $defaultTolerance = (int) config('bank_reconciliation.date_tolerance_days', 3);

        return view('bankReconciliation.create', compact('bankAccountOptions', 'defaultTolerance'));
    }

    /**
     * Step 1: store file only; user completes column mapping on the next screen.
     */
    public function store(Request $request)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $validated = $request->validate([
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('created_by', $creatorId)],
            'statement' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'date_tolerance_days' => 'nullable|integer|min:0|max:30',
        ]);

        $bank = BankAccount::findOrFail($validated['bank_account_id']);

        // Phase 7 (CA11): the same file for the same bank account
        // is a repeat import — refuse it before saving anything.
        $fileHash = hash_file('sha256', $request->file('statement')->getRealPath());
        if ($fileHash !== false) {
            try {
                (new TreasuryReconciliationService())->assertFileHashAvailable($creatorId, $bank->id, $fileHash);
            } catch (\App\Exceptions\TreasuryException $e) {
                return redirect()->back()->with('error', $e->getMessage())->withInput();
            }
        }

        $path = $request->file('statement')->store('bank-statements/'.$creatorId, 'local');
        if ($path === false) {
            return redirect()->back()
                ->with('error', __('Could not save the upload. Check permissions on the storage folder.'))
                ->withInput();
        }

        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);

            return redirect()->back()
                ->with('error', __('The uploaded file could not be saved. Check that :path is writable.', ['path' => storage_path('app')]))
                ->withInput();
        }

        $session = new BankReconciliationSession;
        $session->bank_account_id = $bank->id;
        $session->preset_key = 'flexible';
        $session->status = 'draft';
        $session->original_filename = $request->file('statement')->getClientOriginalName();
        $session->stored_path = $path;
        $session->file_hash = $fileHash !== false ? $fileHash : null;
        $session->column_mapping = null;
        $session->date_tolerance_days = $validated['date_tolerance_days'] ?? (int) config('bank_reconciliation.date_tolerance_days', 3);
        $session->created_by = $creatorId;
        $session->user_id = \Auth::id();
        $session->save();

        return redirect()
            ->route('bank-reconciliation.map', $session)
            ->with('success', __('File uploaded. Map columns below, then import.'));
    }

    /**
     * Step 2: preview rows + column mapping (works for any bank).
     */
    public function map(Request $request, BankReconciliationSession $bankReconciliationSession, StatementImportService $importService)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        if ($bankReconciliationSession->statementLines()->exists()) {
            return redirect()->route('bank-reconciliation.show', $bankReconciliationSession);
        }

        $path = $bankReconciliationSession->stored_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return redirect()->route('bank-reconciliation.index')
                ->with('error', __('The uploaded file is missing. Start a new import.'));
        }

        $absolute = Storage::disk('local')->path($path);
        $rows = $importService->loadRows($absolute);
        $previewRows = array_slice($rows, 0, 35);

        $suggestionKeys = collect(config('bank_reconciliation.presets', []))
            ->filter(fn ($p) => ! empty($p['detector']))
            ->keys()
            ->values();

        $suggested = null;
        $suggestKey = $request->query('suggest');
        if ($suggestKey && $suggestionKeys->contains($suggestKey)) {
            $suggested = $importService->suggestMappingForRows($rows, $suggestKey);
            if ($suggested === null) {
                return redirect()->route('bank-reconciliation.map', $bankReconciliationSession)
                    ->with('warning', __('Could not auto-detect columns for that layout. Map columns manually below.'));
            }
        }

        $defaults = $this->mappingDefaults($suggested);

        return view('bankReconciliation.map', [
            'session' => $bankReconciliationSession->load('bankAccount'),
            'previewRows' => $previewRows,
            'suggestionKeys' => $suggestionKeys,
            'suggestKey' => $suggestKey,
            'suggestedFound' => $suggested !== null,
            'defaults' => $defaults,
        ]);
    }

    /**
     * Step 3: apply mapping, normalize, import, auto-match.
     */
    public function applyMapping(Request $request, BankReconciliationSession $bankReconciliationSession, StatementImportService $importService, ReconciliationMatcher $matcher)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        if ($bankReconciliationSession->statementLines()->exists()) {
            return redirect()->route('bank-reconciliation.show', $bankReconciliationSession);
        }

        $validated = $request->validate([
            'first_data_row' => 'required|integer|min:1|max:10000',
            'date_col' => 'required|integer|min:0|max:255',
            'description_col' => 'required|integer|min:0|max:255',
            'amount_mode' => 'required|in:signed_column,debit_credit',
            'amount_col' => 'required_if:amount_mode,signed_column|nullable|integer|min:0|max:255',
            'debit_col' => 'required_if:amount_mode,debit_credit|nullable|integer|min:0|max:255',
            'credit_col' => 'required_if:amount_mode,debit_credit|nullable|integer|min:0|max:255',
            'balance_col' => 'nullable|integer|min:0|max:255',
            'source_preset' => 'nullable|string|max:64',
        ]);

        $path = $bankReconciliationSession->stored_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return redirect()->route('bank-reconciliation.index')
                ->with('error', __('The uploaded file is missing. Start a new import.'));
        }

        $absolute = Storage::disk('local')->path($path);

        $dataStart = (int) $validated['first_data_row'] - 1;

        $mapping = [
            'data_start_row' => $dataStart,
            'header_row' => max(0, $dataStart - 1),
            'date_col' => (int) $validated['date_col'],
            'description_col' => (int) $validated['description_col'],
            'amount_mode' => $validated['amount_mode'],
            'amount_col' => $validated['amount_col'] ?? null,
            'debit_col' => $validated['debit_col'] ?? null,
            'credit_col' => $validated['credit_col'] ?? null,
            'balance_col' => $validated['balance_col'] ?? null,
        ];

        $source = $validated['source_preset'] ?? '';
        if ($source !== '' && array_key_exists($source, config('bank_reconciliation.presets', []))) {
            $bankReconciliationSession->preset_key = $source;
        } else {
            $bankReconciliationSession->preset_key = 'flexible';
        }
        $bankReconciliationSession->save();

        try {
            $importService->importFromColumnMapping($bankReconciliationSession, $absolute, $mapping);
        } catch (\Throwable $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        // Phase 7 (CA11): drop lines this company already holds
        // for the same bank account (same date + amount +
        // description) and keep only the legitimate new ones.
        $recon = new TreasuryReconciliationService();
        $dedupe = $recon->dedupeSessionLines($bankReconciliationSession->fresh());

        $matcher->runAutoMatch($bankReconciliationSession->fresh());

        $notice = __('Statement imported and auto-matching completed.');
        if ($dedupe['removed'] > 0) {
            $notice .= ' ' . __(':n duplicate line(s) already on file for this account were skipped.', ['n' => $dedupe['removed']]);
        }

        return redirect()
            ->route('bank-reconciliation.show', $bankReconciliationSession)
            ->with('success', $notice);
    }

    public function show(BankReconciliationSession $bankReconciliationSession)
    {
        if (! \Auth::user()->can('manage transaction')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $session = $bankReconciliationSession->load('bankAccount');

        if (! $session->statementLines()->exists()) {
            if ($session->stored_path && Storage::disk('local')->exists($session->stored_path)) {
                return redirect()->route('bank-reconciliation.map', $session)
                    ->with('info', __('Finish column mapping to import this file.'));
            }

            return redirect()->route('bank-reconciliation.index')
                ->with('error', __('This import has no data and the file is missing. Start a new import.'));
        }

        $lines = BankStatementLine::where('session_id', $session->id)
            ->with(['matches.erpTransaction', 'matches.treasuryOperation'])
            ->orderBy('row_index')
            ->get();

        // Phase 7: one line may now carry several matches
        // (one-to-many / many-to-one, CA12), so the matched table
        // lists match rows, not lines.
        $matchRows = $lines->flatMap(fn ($l) => $l->matches)->values();
        $matched = $lines->filter(fn ($l) => $l->matches->isNotEmpty());
        $unmatchedStatement = $lines->filter(fn ($l) => $l->matches->isEmpty());

        $usedTxIds = BankReconciliationMatch::where('session_id', $session->id)
            ->whereNotNull('transaction_id')
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

        // Phase 7: close figures + missing movements + confirmed
        // Treasury operations with a remaining residual on this
        // bank account, for the manual Treasury-match form.
        $recon = new TreasuryReconciliationService();
        $summary = $recon->sessionSummary($session);
        $missingMovements = $session->missingMovements()->with('statementLine')->orderByDesc('id')->get();

        $unmatchedOperations = collect();
        $treasuryAccountIds = \App\Models\TreasuryFinancialAccount::where('bank_account_id', $session->bank_account_id)
            ->where('created_by', $session->created_by)
            ->pluck('id')
            ->all();
        if ($treasuryAccountIds !== []) {
            $operationIds = \App\Models\TreasuryEntry::whereIn('financial_account_id', $treasuryAccountIds)
                ->distinct()
                ->pluck('operation_id')
                ->all();
            $unmatchedOperations = TreasuryOperation::whereIn('id', $operationIds)
                ->where('created_by', $session->created_by)
                ->where('state', TreasuryOperation::STATE_CONFIRMED)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(function ($op) use ($recon, $session) {
                    $op->residual_amount = $recon->operationResidual($op, $session);

                    return $op;
                })
                ->filter(fn ($op) => bccomp((string) $op->residual_amount, '0', 4) > 0)
                ->values();
        }

        return view('bankReconciliation.show', compact('session', 'matched', 'matchRows', 'unmatchedStatement', 'unmatchedErp', 'unmatchedOperations', 'missingMovements', 'summary', 'stats'));
    }

    /**
     * Phase 7 — close a session. Needs the approve permission;
     * the service refuses an unexplained difference.
     */
    public function close(Request $request, BankReconciliationSession $bankReconciliationSession)
    {
        if (! \Auth::user()->can(TreasuryReconciliationService::PERMISSION_APPROVE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $data = $request->validate([
            'statement_balance' => 'nullable|numeric',
            'book_balance' => 'nullable|numeric',
        ]);

        try {
            (new TreasuryReconciliationService())->closeSession(
                \Auth::user()->creatorId(),
                $bankReconciliationSession->id,
                \Auth::id(),
                $data
            );
        } catch (\App\Exceptions\TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('bank-reconciliation.show', $bankReconciliationSession)
            ->with('success', __('Statement closed. Statement, book, outstanding and explained differences reconcile.'));
    }

    /**
     * Phase 7 — reopen a closed session. Needs the approve
     * permission and a reason.
     */
    public function reopen(Request $request, BankReconciliationSession $bankReconciliationSession)
    {
        if (! \Auth::user()->can(TreasuryReconciliationService::PERMISSION_APPROVE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $data = $request->validate([
            'reopen_reason' => 'required|string|max:1000',
        ]);

        try {
            (new TreasuryReconciliationService())->reopenSession(
                \Auth::user()->creatorId(),
                $bankReconciliationSession->id,
                \Auth::id(),
                $data['reopen_reason']
            );
        } catch (\App\Exceptions\TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('bank-reconciliation.show', $bankReconciliationSession)
            ->with('success', __('Statement reopened.'));
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

        // Phase 7: only ever touch the Transaction match row for
        // this line — a line may also carry Treasury matches.
        BankReconciliationMatch::updateOrCreate(
            [
                'session_id' => $bankReconciliationSession->id,
                'statement_line_id' => $line->id,
                'transaction_id' => $tx->id,
            ],
            [
                'treasury_operation_id' => null,
                'matched_amount' => $line->amount !== null ? number_format(abs((float) $line->amount), 4, '.', '') : null,
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

        // Phase 7: only the Transaction match is removed here —
        // Treasury matches have their own unmatch path.
        BankReconciliationMatch::where('session_id', $bankReconciliationSession->id)
            ->where('statement_line_id', $data['statement_line_id'])
            ->whereNotNull('transaction_id')
            ->delete();

        return redirect()->back()->with('success', __('Match removed.'));
    }

    /**
     * @param  array<string, mixed>|null  $suggested
     * @return array<string, mixed>
     */
    protected function mappingDefaults(?array $suggested): array
    {
        if ($suggested !== null) {
            $dataStart = (int) ($suggested['data_start_row'] ?? 0);

            return [
                'first_data_row' => $dataStart + 1,
                'date_col' => $suggested['date_col'] ?? 0,
                'description_col' => $suggested['description_col'] ?? 1,
                'amount_mode' => $suggested['amount_mode'] ?? 'signed_column',
                'amount_col' => $suggested['amount_col'] ?? 2,
                'debit_col' => $suggested['debit_col'] ?? 2,
                'credit_col' => $suggested['credit_col'] ?? 3,
                'balance_col' => $suggested['balance_col'] ?? '',
            ];
        }

        return [
            'first_data_row' => 2,
            'date_col' => 0,
            'description_col' => 1,
            'amount_mode' => 'signed_column',
            'amount_col' => 2,
            'debit_col' => 2,
            'credit_col' => 3,
            'balance_col' => '',
        ];
    }

    protected function authorizeSession(BankReconciliationSession $session): void
    {
        if ((int) $session->created_by !== (int) \Auth::user()->creatorId()) {
            abort(403);
        }
    }
}
