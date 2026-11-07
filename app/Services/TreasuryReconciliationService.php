<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\BankAccount;
use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\DimensionAuditEvent;
use App\Models\Transaction;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryMissingMovement;
use App\Models\TreasuryOperation;
use App\Models\TreasuryStatementSyncCursor;
use App\Models\User;
use App\Services\Accounting\BankReconciliation\ReconciliationMatcher;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §6, §7, §10).
 *
 * Built ON the existing BankReconciliation flow (import, auto-match,
 * manual match, show) — it does not replace it. This service adds:
 *
 *  - CA11 — file-hash duplicate blocking + overlapping-import
 *    dedupe (repeat files are refused; overlapping files keep only
 *    their legitimate, not-yet-seen lines).
 *  - CA12 — matches to Treasury operations with amounts: partial
 *    and multiple matches stay within the statement-line residual
 *    and the operation residual; over-allocation is refused.
 *  - Statement close by account and period: statement balance vs
 *    book balance vs outstanding items vs explained differences,
 *    with reopen only on authorisation + reason.
 *  - Missing-movement proposals (fees, interest, other items on
 *    the statement but not in the books): propose → confirm (one
 *    journal through the poster, linked to the statement line so
 *    it can never be created twice) → reverse only.
 *  - API import with a sync cursor per (bank account, source):
 *    a re-run resumes after the cursor and never re-imports a line.
 *
 * Close math, stated plainly. S = statement balance (last line
 * balance by default). B = book balance at period end. For a bank
 * account with a Treasury financial account, B is the Treasury
 * posted balance (approved opening balances + confirmed entries —
 * which already include confirmed missing movements) plus any
 * legacy signed Transaction amounts for the account dated on/before
 * period end. The legacy BankAccount.opening_balance is deliberately
 * NOT added there: Treasury accounts are created with it at 0 and
 * the poster drifts that field on every Treasury journal (the known
 * legacy-balance issue), so adding it would inject the drift into
 * the close. For a legacy-only bank (no Treasury account), B is the
 * bank account's opening balance + signed Transaction amounts for
 * the account dated on/before period end + the signed amounts of
 * this session's confirmed missing movements, which the legacy
 * Transaction universe does not see. U = signed amounts of the
 * session's unmatched statement lines. Close is allowed only when
 * S = B + U (within 0.01). E = explained differences = the signed
 * total of this session's confirmed missing movements, shown for
 * the record — those lines are matched, so they no longer sit in U.
 * A close with an unexplained difference is refused with the
 * difference stated. The close form accepts an explicit book
 * balance when the books hold postings the default cannot see
 * (for example a cash-register variance journal).
 *
 * Money is DECIMAL(20,4), rates DECIMAL(20,10), bcmath only —
 * never float (RF04). All Treasury journals go through the
 * JournalEntryPoster (RF01/RF02). Confirmed movements are
 * immutable — reversal only, linked to the original (RF03).
 */
class TreasuryReconciliationService
{
    public const PERMISSION_MANAGE = 'manage treasury reconciliation';
    public const PERMISSION_APPROVE = 'approve treasury reconciliation';

    public const AUDIT_ENTITY = 'treasury_reconciliation_session';
    public const AUDIT_MOVEMENT_ENTITY = 'treasury_missing_movement';

    public function __construct(
        protected ?JournalEntryPoster $poster = null,
        protected ?ChartAccountResolver $resolver = null,
        protected ?ReconciliationMatcher $matcher = null,
        protected ?TreasuryAccountService $treasuryAccounts = null
    ) {
        $this->poster = $poster ?? new JournalEntryPoster();
        $this->resolver = $resolver ?? new ChartAccountResolver();
        $this->matcher = $matcher ?? new ReconciliationMatcher();
        $this->treasuryAccounts = $treasuryAccounts ?? new TreasuryAccountService();
    }

    // ------------------------------------------------------------------
    // CA11 — duplicate-file blocking + overlapping-import dedupe
    // ------------------------------------------------------------------

    /**
     * Refuse when another session for the same bank account already
     * carries this file hash. The unique DB index is the backstop;
     * this check turns it into a clean TreasuryException.
     */
    public function assertFileHashAvailable(int $creatorId, int $bankAccountId, string $hash, ?int $exceptSessionId = null): void
    {
        $query = BankReconciliationSession::where('bank_account_id', $bankAccountId)
            ->where('created_by', $creatorId)
            ->where('file_hash', $hash);
        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        if ($query->exists()) {
            throw new TreasuryException('This statement file was already imported for this bank account (same file hash). A repeat import is blocked — open the existing session instead (CA11).');
        }
    }

    /**
     * Remove lines of this session that duplicate a line the same
     * company already holds for the same bank account — same
     * transaction date, same amount, same description — keeping the
     * earliest copy. Lines without a date or an amount cannot be
     * compared and are always kept. Returns the counts.
     *
     * @return array{removed: int, kept: int}
     */
    public function dedupeSessionLines(BankReconciliationSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $removed = 0;
            $kept = 0;

            $lines = BankStatementLine::where('session_id', $session->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                if ($line->txn_date === null || $line->amount === null) {
                    $kept++;

                    continue;
                }

                $duplicate = BankStatementLine::query()
                    ->join('bank_reconciliation_sessions', 'bank_reconciliation_sessions.id', '=', 'bank_statement_lines.session_id')
                    ->where('bank_reconciliation_sessions.bank_account_id', $session->bank_account_id)
                    ->where('bank_reconciliation_sessions.created_by', $session->created_by)
                    // whereDate, not a plain equality: a date-cast value
                    // is stored as 'Y-m-d H:i:s' on SQLite, so comparing
                    // to 'Y-m-d' would never match there (MySQL coerces).
                    ->whereDate('bank_statement_lines.txn_date', $line->txn_date->format('Y-m-d'))
                    ->where('bank_statement_lines.amount', (string) $line->amount)
                    ->where('bank_statement_lines.description', (string) ($line->description ?? ''))
                    ->where('bank_statement_lines.id', '<', $line->id)
                    ->exists();

                if ($duplicate) {
                    $line->delete();
                    $removed++;
                } else {
                    $kept++;
                }
            }

            return ['removed' => $removed, 'kept' => $kept];
        });
    }

    // ------------------------------------------------------------------
    // CA12 — matches to Treasury operations, with residuals
    // ------------------------------------------------------------------

    /**
     * What is still unmatched on a statement line, as a positive
     * 4-decimal amount. A legacy one-to-one Transaction match counts
     * as the full line amount; Treasury matches count their
     * matched_amount.
     */
    public function lineResidual(BankStatementLine $line): string
    {
        $amount = bccomp((string) ($line->amount ?? '0'), '0', 4) === 0
            ? '0.0000'
            : $this->abs4((string) $line->amount);

        $matched = '0.0000';
        foreach ($line->matches()->get() as $match) {
            if ($match->transaction_id !== null) {
                return '0.0000';
            }
            $matched = bcadd($matched, (string) ($match->matched_amount ?? '0'), 4);
        }

        $residual = bcsub($amount, $matched, 4);

        return bccomp($residual, '0', 4) < 0 ? '0.0000' : $residual;
    }

    /**
     * The confirmed Treasury entry of this operation that sits on
     * the session's bank account, if any. A transfer has two legs;
     * only the leg on this account can match this statement.
     */
    public function operationEntryForSession(TreasuryOperation $operation, BankReconciliationSession $session): ?TreasuryEntry
    {
        $treasuryAccount = $this->treasuryAccountForBankAccount((int) $session->bank_account_id, (int) $session->created_by);
        if ($treasuryAccount === null) {
            return null;
        }

        return TreasuryEntry::where('operation_id', $operation->id)
            ->where('financial_account_id', $treasuryAccount->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * What is still unmatched on a Treasury operation's entry for
     * this session's bank account, as a positive 4-decimal amount.
     * Matches from every session of the company count — an operation
     * can never be matched twice over (CA12).
     */
    public function operationResidual(TreasuryOperation $operation, BankReconciliationSession $session): string
    {
        $entry = $this->operationEntryForSession($operation, $session);
        if ($entry === null) {
            return '0.0000';
        }

        $total = $this->abs4((string) $entry->amount_original);
        $matched = (string) BankReconciliationMatch::where('treasury_operation_id', $operation->id)
            ->sum('matched_amount');

        $residual = bcsub($total, $matched === '' ? '0' : $matched, 4);

        return bccomp($residual, '0', 4) < 0 ? '0.0000' : $residual;
    }

    /**
     * Match a statement line to a confirmed Treasury operation, in
     * full or in part. The amount defaults to the whole line
     * residual. Refuses when the amount exceeds either residual.
     */
    public function matchToTreasuryOperation(
        int $creatorId,
        int $sessionId,
        int $statementLineId,
        int $operationId,
        int $actorId,
        ?string $amount = null,
        string $matchType = 'manual'
    ): BankReconciliationMatch {
        return DB::transaction(function () use ($creatorId, $sessionId, $statementLineId, $operationId, $actorId, $amount, $matchType) {
            $session = $this->sessionForCompany($creatorId, $sessionId);
            if ($session->isClosed()) {
                throw new TreasuryException('This session is closed. Reopen it before changing matches.');
            }

            $line = BankStatementLine::lockForUpdate()->findOrFail($statementLineId);
            if ((int) $line->session_id !== (int) $session->id) {
                throw new TreasuryException('The statement line does not belong to this session.');
            }

            $operation = TreasuryOperation::lockForUpdate()->findOrFail($operationId);
            if ((int) $operation->created_by !== $creatorId || ! $operation->isConfirmed()) {
                throw new TreasuryException('Only a confirmed Treasury operation of this company can be matched.');
            }

            $entry = $this->operationEntryForSession($operation, $session);
            if ($entry === null) {
                throw new TreasuryException('This operation has no movement on the session bank account, so it cannot match this statement.');
            }

            $lineResidual = $this->lineResidual($line);
            $operationResidual = $this->operationResidual($operation, $session);
            $wanted = $amount === null ? $lineResidual : $this->decimalString($amount, 'Matched amount');

            if (bccomp($wanted, '0', 4) <= 0) {
                throw new TreasuryException('The matched amount must be greater than zero.');
            }
            if (bccomp($wanted, $lineResidual, 4) > 0) {
                throw new TreasuryException('The matched amount ' . $wanted . ' exceeds the statement line residual ' . $lineResidual . ' (CA12).');
            }
            if (bccomp($wanted, $operationResidual, 4) > 0) {
                throw new TreasuryException('The matched amount ' . $wanted . ' exceeds the operation residual ' . $operationResidual . ' (CA12).');
            }

            $match = BankReconciliationMatch::create([
                'session_id' => $session->id,
                'statement_line_id' => $line->id,
                'transaction_id' => null,
                'treasury_operation_id' => $operation->id,
                'matched_amount' => $wanted,
                'match_type' => $matchType,
            ]);

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'treasury_matched', null, [
                'statement_line_id' => $line->id,
                'treasury_operation_id' => $operation->id,
                'operation_reference' => $operation->reference,
                'matched_amount' => $wanted,
                'match_type' => $matchType,
            ], $actorId);

            return $match;
        });
    }

    /**
     * Remove Treasury-operation matches of one statement line (all
     * of them, or a single match row). Legacy Transaction matches
     * are untouched — they have their own unmatch path.
     */
    public function unmatchTreasuryOperation(
        int $creatorId,
        int $sessionId,
        int $statementLineId,
        int $actorId,
        ?int $matchId = null
    ): int {
        return DB::transaction(function () use ($creatorId, $sessionId, $statementLineId, $actorId, $matchId) {
            $session = $this->sessionForCompany($creatorId, $sessionId);
            if ($session->isClosed()) {
                throw new TreasuryException('This session is closed. Reopen it before changing matches.');
            }

            $query = BankReconciliationMatch::where('session_id', $session->id)
                ->where('statement_line_id', $statementLineId)
                ->whereNotNull('treasury_operation_id');
            if ($matchId !== null) {
                $query->where('id', $matchId);
            }

            $deleted = $query->delete();

            if ($deleted > 0) {
                $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'treasury_unmatched', null, [
                    'statement_line_id' => $statementLineId,
                    'removed' => $deleted,
                ], $actorId);
            }

            return $deleted;
        });
    }

    // ------------------------------------------------------------------
    // Close / reopen
    // ------------------------------------------------------------------

    /**
     * Figures for the close panel. All amounts are 4-decimal
     * strings; difference = statement − (book + outstanding).
     *
     * @return array{statement_balance: string, book_balance: string, outstanding_items: string, explained_differences: string, difference: string, unmatched_lines: int}
     */
    public function sessionSummary(BankReconciliationSession $session): array
    {
        $statement = $this->statementBalanceDefault($session);
        $book = $this->bookBalanceDefault($session);
        $outstanding = $this->unmatchedSignedTotal($session);
        $explained = $this->confirmedMissingTotal($session);

        return [
            'statement_balance' => $statement,
            'book_balance' => $book,
            'outstanding_items' => $outstanding,
            'explained_differences' => $explained,
            'difference' => bcsub(bcsub($statement, $book, 4), $outstanding, 4),
            'unmatched_lines' => $this->unmatchedLineCount($session),
        ];
    }

    /**
     * Close a session. Options: statement_balance and book_balance
     * (both default as described in the class docblock). The close
     * is refused when the difference is not zero — the refusal
     * states the difference so the user can see what is unexplained.
     *
     * @return array{session: BankReconciliationSession, summary: array}
     */
    public function closeSession(int $creatorId, int $sessionId, int $actorId, array $options = []): array
    {
        return DB::transaction(function () use ($creatorId, $sessionId, $actorId, $options) {
            if (! $this->userHasPermission($creatorId, $actorId, self::PERMISSION_APPROVE)) {
                throw new TreasuryException('Closing a statement needs the approve treasury reconciliation permission.');
            }

            $session = BankReconciliationSession::lockForUpdate()->findOrFail(
                $this->sessionForCompany($creatorId, $sessionId)->id
            );
            if ($session->isClosed()) {
                throw new TreasuryException('This session is already closed.');
            }
            if (! $session->statementLines()->exists()) {
                throw new TreasuryException('This session has no statement lines to close.');
            }

            $statement = isset($options['statement_balance']) && $options['statement_balance'] !== ''
                ? $this->decimalString($options['statement_balance'], 'Statement balance')
                : $this->statementBalanceDefault($session);
            $book = isset($options['book_balance']) && $options['book_balance'] !== ''
                ? $this->decimalString($options['book_balance'], 'Book balance')
                : $this->bookBalanceDefault($session);
            $outstanding = $this->unmatchedSignedTotal($session);
            $explained = $this->confirmedMissingTotal($session);
            $difference = bcsub(bcsub($statement, $book, 4), $outstanding, 4);

            if (bccomp($this->abs4($difference), '0.01', 4) > 0) {
                throw new TreasuryException(
                    'The statement does not reconcile: statement ' . $statement . ' − (book ' . $book . ' + outstanding ' . $outstanding . ') = ' . $difference . '. Match the remaining lines or explain the difference before closing.'
                );
            }

            $session->update([
                'status' => BankReconciliationSession::STATUS_CLOSED,
                'statement_balance' => $statement,
                'book_balance' => $book,
                'outstanding_items' => $outstanding,
                'explained_differences' => $explained,
                'closed_at' => now(),
                'closed_by' => $actorId,
                'reopened_at' => null,
                'reopen_reason' => null,
            ]);

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'closed', null, [
                'statement_balance' => $statement,
                'book_balance' => $book,
                'outstanding_items' => $outstanding,
                'explained_differences' => $explained,
            ], $actorId);

            return ['session' => $session->fresh(), 'summary' => $this->sessionSummary($session->fresh())];
        });
    }

    /**
     * Reopen a closed session. Needs the approve permission, a
     * different authoriser than the closer is NOT required (the
     * permission is the control), but a reason always is.
     */
    public function reopenSession(int $creatorId, int $sessionId, int $actorId, string $reason): BankReconciliationSession
    {
        return DB::transaction(function () use ($creatorId, $sessionId, $actorId, $reason) {
            if (! $this->userHasPermission($creatorId, $actorId, self::PERMISSION_APPROVE)) {
                throw new TreasuryException('Reopening a statement needs the approve treasury reconciliation permission.');
            }
            if (trim($reason) === '') {
                throw new TreasuryException('A reason is required to reopen a closed statement.');
            }

            $session = BankReconciliationSession::lockForUpdate()->findOrFail(
                $this->sessionForCompany($creatorId, $sessionId)->id
            );
            if (! $session->isClosed()) {
                throw new TreasuryException('Only a closed session can be reopened.');
            }

            $session->update([
                'status' => BankReconciliationSession::STATUS_DRAFT,
                'closed_at' => null,
                'closed_by' => null,
                'reopened_at' => now(),
                'reopen_reason' => trim($reason),
            ]);

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'reopened', [
                'statement_balance' => (string) ($session->statement_balance ?? ''),
                'book_balance' => (string) ($session->book_balance ?? ''),
            ], ['reason' => trim($reason)], $actorId);

            return $session->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Missing movements
    // ------------------------------------------------------------------

    /**
     * Propose a fee / interest / other item found on the statement
     * but not in the books. The amount defaults to the statement
     * line amount. One live proposal per line — a second proposal
     * is refused while a proposed or confirmed one exists.
     */
    public function proposeMissingMovement(
        int $creatorId,
        int $sessionId,
        int $statementLineId,
        int $actorId,
        array $data
    ): TreasuryMissingMovement {
        return DB::transaction(function () use ($creatorId, $sessionId, $statementLineId, $actorId, $data) {
            $session = $this->sessionForCompany($creatorId, $sessionId);
            if ($session->isClosed()) {
                throw new TreasuryException('This session is closed. Reopen it before proposing a missing movement.');
            }

            $line = BankStatementLine::lockForUpdate()->findOrFail($statementLineId);
            if ((int) $line->session_id !== (int) $session->id) {
                throw new TreasuryException('The statement line does not belong to this session.');
            }
            if ($line->amount === null || bccomp((string) $line->amount, '0', 4) === 0) {
                throw new TreasuryException('A missing movement needs a statement line with a non-zero amount.');
            }

            $category = (string) ($data['category'] ?? '');
            if (! in_array($category, TreasuryMissingMovement::CATEGORIES, true)) {
                throw new TreasuryException('Category must be fee, interest or other.');
            }

            $offsetAccountId = (int) ($data['offset_account_id'] ?? 0);
            $offset = ChartOfAccount::where('id', $offsetAccountId)
                ->where('created_by', $creatorId)
                ->first();
            if ($offset === null) {
                throw new TreasuryException('The offset chart account does not belong to this company.');
            }

            $existing = TreasuryMissingMovement::where('statement_line_id', $line->id)
                ->whereIn('state', [TreasuryMissingMovement::STATE_PROPOSED, TreasuryMissingMovement::STATE_CONFIRMED])
                ->exists();
            if ($existing) {
                throw new TreasuryException('This statement line already has a live missing-movement proposal. Confirm or reject it first — one item per line, so it can never be posted twice.');
            }

            $treasuryAccount = $this->treasuryAccountForBankAccount((int) $session->bank_account_id, $creatorId);
            if ($treasuryAccount === null) {
                throw new TreasuryException('This bank account has no Treasury financial account yet. Set one up before proposing missing movements.');
            }

            $currency = (string) $treasuryAccount->currency;
            $amountOriginal = isset($data['amount']) && $data['amount'] !== ''
                ? $this->decimalString($data['amount'], 'Amount')
                : $this->decimalString((string) $line->amount, 'Amount');
            if (bccomp($amountOriginal, '0', 4) === 0) {
                throw new TreasuryException('The missing movement amount must not be zero.');
            }

            $baseCurrency = TreasurySettings::baseCurrency($creatorId);
            $rate = '1.0000000000';
            if ($currency !== $baseCurrency) {
                if (! isset($data['exchange_rate']) || ! is_numeric($data['exchange_rate']) || bccomp((string) $data['exchange_rate'], '0', 10) <= 0) {
                    throw new TreasuryException('No exchange rate is stored for this movement — confirmation is blocked, never guess a rate (D2).');
                }
                $rate = $this->rateString($data['exchange_rate']);
            }
            $amountBase = bcmul($amountOriginal, $rate, 4);

            $movement = TreasuryMissingMovement::create([
                'session_id' => $session->id,
                'statement_line_id' => $line->id,
                'category' => $category,
                'offset_account_id' => $offsetAccountId,
                'currency' => $currency,
                'amount_original' => $amountOriginal,
                'exchange_rate' => $rate,
                'amount_base' => $amountBase,
                'state' => TreasuryMissingMovement::STATE_PROPOSED,
                'reason' => isset($data['reason']) ? trim((string) $data['reason']) : null,
                'created_by' => $creatorId,
            ]);

            $this->audit($creatorId, self::AUDIT_MOVEMENT_ENTITY, $movement->id, 'proposed', null, [
                'session_id' => $session->id,
                'statement_line_id' => $line->id,
                'category' => $category,
                'amount_original' => $amountOriginal,
                'amount_base' => $amountBase,
            ], $actorId);

            return $movement;
        });
    }

    /**
     * Confirm a proposed missing movement: posts exactly one journal
     * through the poster (Dr offset / Cr bank for money out, Dr bank
     * / Cr offset for money in), creates the adjustment operation +
     * entry, and marks the statement line matched so the item is
     * explained. Confirming needs the approve permission — the
     * proposer and the confirmer are segregated by permission.
     *
     * @return array{movement: TreasuryMissingMovement, operation: TreasuryOperation, journal: \App\Models\JournalEntry, entry: TreasuryEntry}
     */
    public function confirmMissingMovement(
        int $creatorId,
        int $movementId,
        int $actorId,
        array $options = []
    ): array {
        return DB::transaction(function () use ($creatorId, $movementId, $actorId, $options) {
            if (! $this->userHasPermission($creatorId, $actorId, self::PERMISSION_APPROVE)) {
                throw new TreasuryException('Confirming a missing movement needs the approve treasury reconciliation permission.');
            }

            $movement = TreasuryMissingMovement::lockForUpdate()->findOrFail($movementId);
            if ((int) $movement->created_by !== $creatorId) {
                throw new TreasuryException('This missing movement does not belong to this company.');
            }
            if (! $movement->isProposed()) {
                throw new TreasuryException('Only a proposed missing movement can be confirmed (state: ' . $movement->state . ').');
            }

            $session = BankReconciliationSession::lockForUpdate()->findOrFail((int) $movement->session_id);
            if ($session->isClosed()) {
                throw new TreasuryException('This session is closed. Reopen it before confirming a missing movement.');
            }

            $line = BankStatementLine::lockForUpdate()->findOrFail((int) $movement->statement_line_id);

            // A second confirmed movement for the same line would
            // post the same item twice — refuse, even if the first
            // one was created through another path.
            $alreadyConfirmed = TreasuryMissingMovement::where('statement_line_id', $line->id)
                ->where('state', TreasuryMissingMovement::STATE_CONFIRMED)
                ->where('id', '!=', $movement->id)
                ->exists();
            if ($alreadyConfirmed) {
                throw new TreasuryException('This statement line already has a confirmed missing movement. One item per line, so it can never be posted twice.');
            }

            $posting = $this->resolvePostingDate(array_merge($options, ['created_by' => $creatorId, 'executor_user_id' => $actorId]), $actorId);
            $postingDate = $posting['posting_date'];
            if (! $this->isPeriodOpen($creatorId, Carbon::parse($postingDate)) && $posting['adjustment'] === null) {
                throw new TreasuryException('Fiscal year ' . Carbon::parse($postingDate)->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
            }

            $treasuryAccount = $this->treasuryAccountForBankAccount((int) $session->bank_account_id, $creatorId);
            if ($treasuryAccount === null) {
                throw new TreasuryException('This bank account has no Treasury financial account yet.');
            }
            $bankChartId = $this->chartAccountId($treasuryAccount);
            // Journal lines take the ABSOLUTE amount: the poster normalises
            // each line by (debit - credit), so a signed -50.00 passed as
            // a debit would silently flip to the credit side and post
            // the journal backwards. The direction is carried by which
            // account is debited, never by the sign.
            $amountBase2 = $this->round2($this->abs4((string) $movement->amount_base));
            $moneyOut = bccomp((string) $movement->amount_original, '0', 4) < 0;

            $operation = new TreasuryOperation([
                'type' => TreasuryOperation::TYPE_ADJUSTMENT,
                'company_id' => $creatorId,
                'financial_account_id' => $treasuryAccount->id,
                'currency' => $movement->currency,
                'amount_original' => $this->abs4((string) $movement->amount_original),
                'exchange_rate' => (string) $movement->exchange_rate,
                'rate_source' => 'statement',
                'amount_base' => $this->abs4((string) $movement->amount_base),
                'posting_date' => $postingDate,
                'effective_date' => $postingDate,
                'value_date' => $line->txn_date ? $line->txn_date->format('Y-m-d') : $postingDate,
                'state' => TreasuryOperation::STATE_DRAFT,
                'source_type' => 'treasury_missing_movement',
                'source_id' => $movement->id,
                'created_by' => $creatorId,
            ]);
            $operation->save();
            $operation->reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADJUSTMENT, $postingDate);
            $operation->state = TreasuryOperation::STATE_CONFIRMED;
            $operation->save();

            $reference = (string) $operation->reference;
            $label = 'Missing movement ' . $movement->category . ' ' . $reference;

            if ($moneyOut) {
                // Bank fee / charge: money left the bank.
                $lines = [
                    ['account' => (int) $movement->offset_account_id, 'debit' => $amountBase2, 'description' => $label],
                    ['account' => $bankChartId, 'credit' => $amountBase2, 'description' => $label],
                ];
                $direction = TreasuryEntry::DIRECTION_OUTFLOW;
            } else {
                // Interest / credit: money entered the bank.
                $lines = [
                    ['account' => $bankChartId, 'debit' => $amountBase2, 'description' => $label],
                    ['account' => (int) $movement->offset_account_id, 'credit' => $amountBase2, 'description' => $label],
                ];
                $direction = TreasuryEntry::DIRECTION_INFLOW;
            }

            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                $label . ' — statement line ' . $line->id,
                $lines
            );

            $entry = $this->recordEntry(
                $operation,
                $treasuryAccount,
                $direction,
                $this->abs4((string) $movement->amount_original),
                $this->abs4((string) $movement->amount_base),
                (string) $movement->exchange_rate,
                $journal->id,
                $creatorId
            );

            // The line is now explained: mark it matched so it
            // leaves the outstanding total at close.
            BankReconciliationMatch::create([
                'session_id' => $session->id,
                'statement_line_id' => $line->id,
                'transaction_id' => null,
                'treasury_operation_id' => $operation->id,
                'matched_amount' => $this->abs4((string) $movement->amount_original),
                'match_type' => 'missing_movement',
            ]);

            $movement->update([
                'state' => TreasuryMissingMovement::STATE_CONFIRMED,
                'operation_id' => $operation->id,
                'journal_id' => $journal->id,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ]);

            $this->audit($creatorId, self::AUDIT_MOVEMENT_ENTITY, $movement->id, 'confirmed', [
                'state' => TreasuryMissingMovement::STATE_PROPOSED,
            ], [
                'state' => TreasuryMissingMovement::STATE_CONFIRMED,
                'operation_id' => $operation->id,
                'journal_id' => $journal->id,
            ], $actorId);

            return [
                'movement' => $movement->fresh(),
                'operation' => $operation->fresh(),
                'journal' => $journal,
                'entry' => $entry,
            ];
        });
    }

    public function rejectMissingMovement(int $creatorId, int $movementId, int $actorId, ?string $reason = null): TreasuryMissingMovement
    {
        return DB::transaction(function () use ($creatorId, $movementId, $actorId, $reason) {
            $movement = TreasuryMissingMovement::lockForUpdate()->findOrFail($movementId);
            if ((int) $movement->created_by !== $creatorId) {
                throw new TreasuryException('This missing movement does not belong to this company.');
            }
            if (! $movement->isProposed()) {
                throw new TreasuryException('Only a proposed missing movement can be rejected (state: ' . $movement->state . ').');
            }

            $movement->update([
                'state' => TreasuryMissingMovement::STATE_REJECTED,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : $movement->reason,
            ]);

            $this->audit($creatorId, self::AUDIT_MOVEMENT_ENTITY, $movement->id, 'rejected', [
                'state' => TreasuryMissingMovement::STATE_PROPOSED,
            ], ['state' => TreasuryMissingMovement::STATE_REJECTED], $actorId);

            return $movement->fresh();
        });
    }

    /**
     * Reverse a confirmed missing movement: a new operation with
     * negated amounts, linked to the original (RF03). The original
     * movement and its journal stay.
     *
     * @return array{movement: TreasuryMissingMovement, operation: TreasuryOperation, journal: \App\Models\JournalEntry, entry: TreasuryEntry}
     */
    public function reverseMissingMovement(
        int $creatorId,
        int $movementId,
        int $actorId,
        array $options = []
    ): array {
        return DB::transaction(function () use ($creatorId, $movementId, $actorId, $options) {
            if (! $this->userHasPermission($creatorId, $actorId, self::PERMISSION_APPROVE)) {
                throw new TreasuryException('Reversing a missing movement needs the approve treasury reconciliation permission.');
            }

            $movement = TreasuryMissingMovement::lockForUpdate()->findOrFail($movementId);
            if ((int) $movement->created_by !== $creatorId) {
                throw new TreasuryException('This missing movement does not belong to this company.');
            }
            if (! $movement->isConfirmed()) {
                throw new TreasuryException('Only a confirmed missing movement can be reversed (state: ' . $movement->state . ').');
            }

            $session = BankReconciliationSession::lockForUpdate()->findOrFail((int) $movement->session_id);
            $originalOperation = TreasuryOperation::findOrFail((int) $movement->operation_id);
            $treasuryAccount = $this->treasuryAccountForBankAccount((int) $session->bank_account_id, $creatorId);
            if ($treasuryAccount === null) {
                throw new TreasuryException('This bank account has no Treasury financial account yet.');
            }

            $posting = $this->resolvePostingDate(array_merge($options, ['created_by' => $creatorId, 'executor_user_id' => $actorId]), $actorId);
            $postingDate = $posting['posting_date'];
            if (! $this->isPeriodOpen($creatorId, Carbon::parse($postingDate)) && $posting['adjustment'] === null) {
                throw new TreasuryException('Fiscal year ' . Carbon::parse($postingDate)->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
            }

            $bankChartId = $this->chartAccountId($treasuryAccount);
            // Journal lines take the ABSOLUTE amount: the poster normalises
            // each line by (debit - credit), so a signed -50.00 passed as
            // a debit would silently flip to the credit side and post
            // the journal backwards. The direction is carried by which
            // account is debited, never by the sign.
            $amountBase2 = $this->round2($this->abs4((string) $movement->amount_base));
            $moneyOut = bccomp((string) $movement->amount_original, '0', 4) < 0;

            $operation = new TreasuryOperation([
                'type' => TreasuryOperation::TYPE_ADJUSTMENT,
                'company_id' => $creatorId,
                'financial_account_id' => $treasuryAccount->id,
                'currency' => $movement->currency,
                'amount_original' => $this->abs4((string) $movement->amount_original),
                'exchange_rate' => (string) $movement->exchange_rate,
                'rate_source' => 'statement',
                'amount_base' => $this->abs4((string) $movement->amount_base),
                'posting_date' => $postingDate,
                'effective_date' => $postingDate,
                'value_date' => $postingDate,
                'state' => TreasuryOperation::STATE_DRAFT,
                'source_type' => 'treasury_missing_movement',
                'source_id' => $movement->id,
                'reversal_of_id' => $originalOperation->id,
                'reversal_reason' => isset($options['reason']) ? trim((string) $options['reason']) : 'Reversal of missing movement ' . $movement->id,
                'created_by' => $creatorId,
            ]);
            $operation->save();
            $operation->reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADJUSTMENT, $postingDate);
            $operation->state = TreasuryOperation::STATE_CONFIRMED;
            $operation->save();

            $reference = (string) $operation->reference;
            $label = 'Reversal of missing movement ' . $movement->category . ' ' . $reference;

            // Equal and opposite of the original journal.
            if ($moneyOut) {
                $lines = [
                    ['account' => $bankChartId, 'debit' => $amountBase2, 'description' => $label],
                    ['account' => (int) $movement->offset_account_id, 'credit' => $amountBase2, 'description' => $label],
                ];
                $direction = TreasuryEntry::DIRECTION_INFLOW;
            } else {
                $lines = [
                    ['account' => (int) $movement->offset_account_id, 'debit' => $amountBase2, 'description' => $label],
                    ['account' => $bankChartId, 'credit' => $amountBase2, 'description' => $label],
                ];
                $direction = TreasuryEntry::DIRECTION_OUTFLOW;
            }

            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                $label,
                $lines
            );

            $entry = $this->recordEntry(
                $operation,
                $treasuryAccount,
                $direction,
                $this->abs4((string) $movement->amount_original),
                $this->abs4((string) $movement->amount_base),
                (string) $movement->exchange_rate,
                $journal->id,
                $creatorId
            );

            // The reversal entry carries the link to the original
            // entry; the original match row stays as history.
            $originalEntry = TreasuryEntry::where('operation_id', $originalOperation->id)->first();
            if ($originalEntry !== null) {
                $entry->reversal_of_id = $originalEntry->id;
                $entry->save();
            }

            $movement->update([
                'state' => TreasuryMissingMovement::STATE_REVERSED,
                'reversal_of_id' => $originalOperation->id,
                'reversal_operation_id' => $operation->id,
            ]);

            $this->audit($creatorId, self::AUDIT_MOVEMENT_ENTITY, $movement->id, 'reversed', [
                'state' => TreasuryMissingMovement::STATE_CONFIRMED,
            ], [
                'state' => TreasuryMissingMovement::STATE_REVERSED,
                'reversal_operation_id' => $operation->id,
            ], $actorId);

            return [
                'movement' => $movement->fresh(),
                'operation' => $operation->fresh(),
                'journal' => $journal,
                'entry' => $entry,
            ];
        });
    }

    // ------------------------------------------------------------------
    // API import with sync cursor
    // ------------------------------------------------------------------

    /**
     * Import statement rows fetched from a provider API (or any
     * external source). Each row: external_id (monotonic per
     * source), txn_date (Y-m-d), description, amount (signed, in
     * the bank account currency), balance (optional).
     *
     * Rows at or below the stored cursor are skipped, and so is any
     * row whose external_id was already imported for this account
     * — a re-run resumes and never re-imports a line. A new draft
     * session is created for the run, the cursor advances to the
     * greatest imported external_id (or to $nextCursor when the
     * provider hands one out), and auto-match runs on the session.
     *
     * @param  array<int, array{external_id: string, txn_date: string, description?: string|null, amount: float|int|string, balance?: float|int|string|null}>  $rows
     * @return array{session: BankReconciliationSession, imported: int, skipped: int, cursor: string|null}
     */
    public function importFromApi(
        int $creatorId,
        int $bankAccountId,
        string $sourceKey,
        array $rows,
        ?string $nextCursor = null
    ): array {
        return DB::transaction(function () use ($creatorId, $bankAccountId, $sourceKey, $rows, $nextCursor) {
            $bank = BankAccount::where('id', $bankAccountId)
                ->where('created_by', $creatorId)
                ->firstOrFail();

            $sourceKey = trim($sourceKey) === '' ? 'default' : trim($sourceKey);

            $cursor = TreasuryStatementSyncCursor::lockForUpdate()->firstOrNew([
                'bank_account_id' => $bank->id,
                'source_key' => $sourceKey,
            ]);
            $storedCursor = $cursor->exists ? (string) ($cursor->cursor_value ?? '') : '';

            $imported = 0;
            $skipped = 0;
            $maxExternalId = $storedCursor;
            $minDate = null;
            $maxDate = null;

            $session = new BankReconciliationSession([
                'bank_account_id' => $bank->id,
                'preset_key' => 'api',
                'status' => BankReconciliationSession::STATUS_DRAFT,
                'original_filename' => 'api:' . $sourceKey,
                'stored_path' => 'api:' . $sourceKey,
                'file_hash' => null,
                'created_by' => $creatorId,
            ]);
            $session->save();

            $rowIndex = 0;
            foreach ($rows as $row) {
                $rowIndex++;
                $externalId = trim((string) ($row['external_id'] ?? ''));
                if ($externalId === '') {
                    $skipped++;

                    continue;
                }

                // Resume: at or below the stored cursor means seen.
                if ($storedCursor !== '' && strcmp($externalId, $storedCursor) <= 0) {
                    $skipped++;

                    continue;
                }

                // Belt and braces: the same external id must never
                // appear twice for this account, in any session.
                $already = BankStatementLine::query()
                    ->join('bank_reconciliation_sessions', 'bank_reconciliation_sessions.id', '=', 'bank_statement_lines.session_id')
                    ->where('bank_reconciliation_sessions.bank_account_id', $bank->id)
                    ->where('bank_reconciliation_sessions.created_by', $creatorId)
                    ->where('bank_statement_lines.external_id', $externalId)
                    ->exists();
                if ($already) {
                    $skipped++;

                    continue;
                }

                $amount = $this->decimalString($row['amount'] ?? '0', 'Amount');
                $txnDate = $this->nullableDate($row['txn_date'] ?? null, 'Transaction date');
                $balance = isset($row['balance']) && $row['balance'] !== '' && $row['balance'] !== null
                    ? $this->decimalString($row['balance'], 'Balance')
                    : null;

                BankStatementLine::create([
                    'session_id' => $session->id,
                    'row_index' => $rowIndex,
                    'external_id' => $externalId,
                    'txn_date' => $txnDate,
                    'description' => isset($row['description']) ? trim((string) $row['description']) : null,
                    'amount' => $amount,
                    'balance' => $balance,
                    'raw_row' => $row,
                ]);
                $imported++;

                if (strcmp($externalId, $maxExternalId) > 0) {
                    $maxExternalId = $externalId;
                }
                if ($txnDate !== null) {
                    $d = Carbon::parse($txnDate);
                    $minDate = $minDate === null || $d->lt($minDate) ? $d : $minDate;
                    $maxDate = $maxDate === null || $d->gt($maxDate) ? $d : $maxDate;
                }
            }

            $session->period_from = $minDate?->format('Y-m-d');
            $session->period_to = $maxDate?->format('Y-m-d');
            $session->save();

            $cursor->created_by = $creatorId;
            $cursor->cursor_value = $nextCursor !== null && trim($nextCursor) !== '' ? trim($nextCursor) : ($maxExternalId !== '' ? $maxExternalId : null);
            $cursor->last_synced_at = now();
            $cursor->save();

            if ($imported > 0) {
                $this->matcher->runAutoMatch($session->fresh());
            }

            return [
                'session' => $session->fresh(),
                'imported' => $imported,
                'skipped' => $skipped,
                'cursor' => $cursor->cursor_value,
            ];
        });
    }

    // ------------------------------------------------------------------
    // Close-figure helpers
    // ------------------------------------------------------------------

    protected function statementBalanceDefault(BankReconciliationSession $session): string
    {
        $last = BankStatementLine::where('session_id', $session->id)
            ->whereNotNull('balance')
            ->orderByDesc('txn_date')
            ->orderByDesc('row_index')
            ->first();

        return $last !== null ? $this->decimalString((string) $last->balance, 'Statement balance') : '0.0000';
    }

    protected function bookBalanceDefault(BankReconciliationSession $session): string
    {
        $periodTo = $session->period_to ? $session->period_to->format('Y-m-d') : date('Y-m-d');
        $transactions = (string) Transaction::where('created_by', $session->created_by)
            ->where('account', $session->bank_account_id)
            ->where('date', '<=', $periodTo)
            ->sum('amount');
        $legacyTotal = $transactions === '' ? '0' : $transactions;

        $treasuryAccount = TreasuryFinancialAccount::where('bank_account_id', (int) $session->bank_account_id)
            ->where('created_by', (int) $session->created_by)
            ->first();

        if ($treasuryAccount !== null) {
            // Treasury-linked bank: the book is the Treasury posted
            // balance (approved opening balances + confirmed entries,
            // which already include confirmed missing movements) plus
            // any legacy Transaction rows on the account. The legacy
            // BankAccount.opening_balance is NOT added: Treasury
            // accounts are created with opening_balance 0 and the
            // poster drifts that field on every Treasury journal (the
            // known legacy-balance issue), so adding it would inject
            // the drift into the close.
            return bcadd(
                $this->treasuryAccounts->postedBalance($treasuryAccount),
                $this->decimalString($legacyTotal, 'Legacy transactions'),
                4
            );
        }

        $bank = BankAccount::find((int) $session->bank_account_id);
        $opening = $bank !== null ? (string) ($bank->opening_balance ?? '0') : '0';

        $book = bcadd($this->decimalString($opening, 'Opening balance'), $legacyTotal, 4);

        // Legacy-only bank: confirmed missing movements post journals
        // the legacy Transaction universe never sees — add their signed
        // effect so the close identity holds after a fee/interest is
        // posted. (A missing movement always needs a Treasury account,
        // so this is normally zero here; kept so the identity holds if
        // one ever lands on a legacy-only bank.)
        $book = bcadd($book, $this->confirmedMissingTotal($session), 4);

        return $book;
    }

    protected function unmatchedSignedTotal(BankReconciliationSession $session): string
    {
        $total = '0.0000';
        $lines = BankStatementLine::where('session_id', $session->id)->get();
        foreach ($lines as $line) {
            if ($line->matches()->exists()) {
                continue;
            }
            $total = bcadd($total, (string) ($line->amount ?? '0'), 4);
        }

        return $total;
    }

    protected function unmatchedLineCount(BankReconciliationSession $session): int
    {
        return BankStatementLine::where('session_id', $session->id)
            ->whereDoesntHave('matches')
            ->count();
    }

    protected function confirmedMissingTotal(BankReconciliationSession $session): string
    {
        $total = (string) TreasuryMissingMovement::where('session_id', $session->id)
            ->where('state', TreasuryMissingMovement::STATE_CONFIRMED)
            ->sum('amount_base');

        return $total === '' ? '0.0000' : $this->decimalString($total, 'Explained differences');
    }

    // ------------------------------------------------------------------
    // Lookups and guards
    // ------------------------------------------------------------------

    protected function sessionForCompany(int $creatorId, int $sessionId): BankReconciliationSession
    {
        $session = BankReconciliationSession::findOrFail($sessionId);
        if ((int) $session->created_by !== $creatorId) {
            throw new TreasuryException('This reconciliation session does not belong to this company.');
        }

        return $session;
    }

    protected function treasuryAccountForBankAccount(int $bankAccountId, int $creatorId): ?TreasuryFinancialAccount
    {
        return TreasuryFinancialAccount::where('bank_account_id', $bankAccountId)
            ->where('created_by', $creatorId)
            ->first();
    }

    protected function chartAccountId(?TreasuryFinancialAccount $account): int
    {
        $id = $account && $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
        if ($id <= 0) {
            throw new TreasuryException('The financial account has no chart account. Set one on the account before confirming.');
        }

        return $id;
    }

    /** Next reference for the company + type + year, serialised (Phase 1/2 pattern). */
    protected function nextReference(int $creatorId, string $type, string $date): string
    {
        $year = Carbon::parse($date)->year;
        $code = TreasuryOperation::TYPE_CODES[$type] ?? 'OPR';
        $prefix = $this->companyReferencePrefix($creatorId);

        $head = $prefix . '-' . $code . '-' . $year . '-';

        $max = 0;
        $existing = TreasuryOperation::where('created_by', $creatorId)
            ->where('type', $type)
            ->where('reference', 'like', $head . '%')
            ->lockForUpdate()
            ->pluck('reference');

        foreach ($existing as $reference) {
            $suffix = substr((string) $reference, strlen($head));
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $head . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Company code for references: configured prefix, else the company name. */
    protected function companyReferencePrefix(int $creatorId): string
    {
        $configured = strtoupper(trim((string) (TreasurySettings::get($creatorId, TreasurySettings::KEY_REFERENCE_PREFIX) ?? '')));

        if ($configured !== '') {
            return preg_replace('/[^A-Z0-9]/', '', $configured) ?: 'CO';
        }

        $user = User::find($creatorId);
        $letters = $user ? preg_replace('/[^A-Za-z]/', '', (string) $user->name) : '';

        return $letters !== '' ? strtoupper(substr($letters, 0, 4)) : 'CO';
    }

    protected function recordEntry(TreasuryOperation $operation, TreasuryFinancialAccount $account, string $direction, string $amountOriginal, string $amountBase, string $rate, int $journalId, int $creatorId): TreasuryEntry
    {
        return TreasuryEntry::create([
            'operation_id' => $operation->id,
            'financial_account_id' => $account->id,
            'direction' => $direction,
            'amount_original' => $amountOriginal,
            'amount_base' => $amountBase,
            'exchange_rate' => $rate,
            'confirmed_at' => now(),
            'journal_id' => $journalId,
            'created_by' => $creatorId,
        ]);
    }

    /**
     * @return array{posting_date: string, adjustment: array|null}
     */
    protected function resolvePostingDate(array $options, int $actorId): array
    {
        $intended = trim((string) ($options['posting_date'] ?? '')) ?: date('Y-m-d');

        $adjustment = $options['adjustment'] ?? null;
        if ($adjustment === null || ! is_array($adjustment)) {
            return ['posting_date' => $intended, 'adjustment' => null];
        }

        $authorisedBy = (int) ($adjustment['authorised_by'] ?? 0);
        $reason = trim((string) ($adjustment['reason'] ?? ''));
        if ($authorisedBy <= 0 || $reason === '') {
            throw new TreasuryException('An authorised adjustment needs an authoriser and a reason.');
        }

        $executorId = (int) ($options['executor_user_id'] ?? $actorId);
        if ($authorisedBy === $executorId) {
            throw new TreasuryException('The adjustment must be authorised by a different user than the executor.');
        }
        if (! $this->userHasPermission((int) ($options['created_by'] ?? 0), $authorisedBy, self::PERMISSION_APPROVE)) {
            throw new TreasuryException('The adjustment authoriser does not hold the approve treasury reconciliation permission.');
        }

        return [
            'posting_date' => date('Y-m-d'),
            'adjustment' => [
                'intended_date' => $intended,
                'authorised_by' => $authorisedBy,
                'reason' => $reason,
            ],
        ];
    }

    /** Same FY-CLOSE rule as the other Treasury services (CA17). */
    protected function isPeriodOpen(int $creatorId, Carbon $date): bool
    {
        if (! Schema::hasTable('journal_entries')) {
            return true;
        }

        return ! DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-' . $date->year . '-CLOSE%')
            ->exists();
    }

    /** Direct + role permission check (Phase 2/3 pattern). */
    protected function userHasPermission(int $creatorId, int $userId, string $permission): bool
    {
        if (! Schema::hasTable('model_has_permissions') || ! Schema::hasTable('permissions')) {
            return true;
        }

        $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');
        if (! $permissionId) {
            return false;
        }

        $direct = DB::table('model_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('model_type', User::class)
            ->where('model_id', $userId)
            ->exists();
        if ($direct) {
            return true;
        }

        if (Schema::hasTable('role_has_permissions') && Schema::hasTable('model_has_roles')) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $permissionId)->pluck('role_id')->all();
            if ($roleIds !== []) {
                return DB::table('model_has_roles')
                    ->whereIn('role_id', $roleIds)
                    ->where('model_type', User::class)
                    ->where('model_id', $userId)
                    ->exists();
            }
        }

        return false;
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function rateString(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '1.0000000000';
        }

        return number_format((float) $value, 10, '.', '');
    }

    protected function nullableDate(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            throw new TreasuryException($label . ' is not a valid date.');
        }
    }

    /** Round a 4-decimal amount to the ledger's 2-decimal precision (half away from zero). */
    protected function round2(string $amount4): string
    {
        return number_format(round((float) $amount4, 2), 2, '.', '');
    }

    protected function abs4(string $amount): string
    {
        return number_format(abs((float) $amount), 4, '.', '');
    }

    protected function audit(int $companyId, string $entityType, int $entityId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        if (! Schema::hasTable('dimension_audit_events')) {
            return;
        }

        DimensionAuditEvent::create([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $before !== null ? json_encode($before) : null,
            'after_json' => $after !== null ? json_encode($after) : null,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);
    }
}
