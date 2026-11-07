<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\DimensionAuditEvent;
use App\Models\PosShift;
use App\Models\TreasuryCashCount;
use App\Models\TreasuryCashMovement;
use App\Models\TreasuryCashSession;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 5 — cash register sessions (plan §5, §6, §7, §10,
 * CA09).
 *
 * A session is the cash book for one register over one period. It
 * opens with a starting float, records cash movements, is counted by
 * denomination, and closes with a variance that — together with the
 * count that produced it — is kept forever.
 *
 * States (plan §6): OPEN → COUNT (denomination count, expected vs
 * actual, variance) → CLOSED (locked; variance needs a reason and
 * approval, adjustment posted separately) → REOPENED only with
 * authorisation, a reason and an audit event, subject to closed-
 * period rules. CLOSED → LOCKED is terminal (audit lock).
 *
 * Expected physical cash = opening float + cash inflows − cash
 * outflows (handovers included). Card and mobile totals are tracked
 * separately and never enter the physical count.
 *
 * The opening float is cash already on the register account (an
 * approved opening balance or a transfer in) — the session counts
 * against it, it never invents money. A typed float that differs
 * from the register's posted balance at open is stored on the
 * session and shown as a warning; it is NOT silently corrected.
 *
 * Cash movements are balance movements on the register account:
 * each one writes a treasury entry on the session's cash-movement
 * operation, so the register's posted balance tracks the physical
 * cash. They carry no journal — like an approved opening balance,
 * they move a balance, they do not recognise income or expense (the
 * sale or payment behind the cash was already posted by its own
 * flow). Card and mobile movements are session-local records only.
 * The variance adjustment and the handover DO post journals — they
 * are real Treasury movements.
 *
 * CA09: the count and the variance are both preserved. A count
 * recorded while the session is open is the draft count: re-counting
 * before close supersedes it (the audit event keeps the before /
 * after). Once closed, the count rows are immutable, and a reopen +
 * re-count + re-close posts only the DELTA against the variance
 * already posted — the previously posted adjustment is never edited
 * or reversed by the new close.
 *
 * A POS shift feeds a session ONCE: the shift must be closed,
 * same-company, and no other session may already reference it. The
 * shift's cash takings arrive as one inflow movement at feed; card /
 * credit totals are snapshotted for display.
 *
 * Posting (plan §7): every variance adjustment posts through
 * JournalEntryPoster only — shortage Dr variance expense (default
 * 684) / Cr cash, surplus Dr cash / Cr variance income (default
 * 764). A payment in a closed period is blocked, or routed as an
 * authorised adjustment to an open period (CA17).
 */
class TreasuryCashSessionService
{
    public const AUDIT_ENTITY = 'treasury_cash_session';

    protected TreasuryAccountService $accounts;
    protected TreasuryTransferService $transfers;
    protected JournalEntryPoster $poster;
    protected ChartAccountResolver $resolver;

    public function __construct(
        ?TreasuryAccountService $accounts = null,
        ?TreasuryTransferService $transfers = null,
        ?JournalEntryPoster $poster = null,
        ?ChartAccountResolver $resolver = null,
    ) {
        $this->accounts = $accounts ?? new TreasuryAccountService();
        $this->transfers = $transfers ?? new TreasuryTransferService($this->accounts);
        $this->poster = $poster ?? new JournalEntryPoster();
        $this->resolver = $resolver ?? new ChartAccountResolver();
    }

    // ------------------------------------------------------------------
    // Lookup (CA16 — company scope on every action)
    // ------------------------------------------------------------------

    public function sessionForCompany(int $creatorId, int $sessionId): TreasuryCashSession
    {
        $session = TreasuryCashSession::where('created_by', $creatorId)->find($sessionId);

        if (! $session) {
            throw new TreasuryException('Cash session not found for this company.');
        }

        return $session;
    }

    // ------------------------------------------------------------------
    // Open
    // ------------------------------------------------------------------

    /**
     * Open a session on a cash register.
     *
     * @param array{register_id: int, operator_user_id: int, opening_float: string, branch_id?: int|null, shift_id?: int|null, exchange_rate?: string|null, rate_source?: string|null, rate_date?: string|null} $data
     */
    public function openSession(int $creatorId, array $data, int $actorId): TreasuryCashSession
    {
        return DB::transaction(function () use ($creatorId, $data, $actorId) {
            $registerId = (int) ($data['register_id'] ?? 0);
            if ($registerId <= 0) {
                throw new TreasuryException('A cash register account is required.');
            }
            $register = $this->accounts->accountForCompany($creatorId, $registerId);
            if (! $register->isActive()) {
                throw new TreasuryException('The cash register account ' . $register->code . ' is inactive.');
            }
            if ($register->type !== TreasuryFinancialAccount::TYPE_CASH_REGISTER) {
                throw new TreasuryException('The account ' . $register->code . ' is not a cash register — a session opens on a cash-register account.');
            }

            // One open session per register: two open sessions on one
            // till would count the same cash twice.
            $open = TreasuryCashSession::where('created_by', $creatorId)
                ->where('register_id', $register->id)
                ->where('state', TreasuryCashSession::STATE_OPEN)
                ->exists();
            if ($open) {
                throw new TreasuryException('Register ' . $register->code . ' already has an open session — close or lock it before opening another.');
            }

            $operator = User::find((int) ($data['operator_user_id'] ?? 0));
            if (! $operator) {
                throw new TreasuryException('The session operator is required.');
            }
            if ((int) $operator->id !== $creatorId && (int) $operator->created_by !== $creatorId) {
                throw new TreasuryException('The session operator does not belong to this company.');
            }

            $float = $this->decimalString($data['opening_float'] ?? null, 'Opening float');
            if (bccomp($float, '0', 4) < 0) {
                throw new TreasuryException('The opening float cannot be negative.');
            }

            $baseCurrency = TreasurySettings::baseCurrency($creatorId);
            $rate = '1.0000000000';
            $rateSource = null;
            $rateDate = null;
            if ($register->currency !== $baseCurrency) {
                $rateInput = $data['exchange_rate'] ?? null;
                if ($rateInput === null || trim((string) $rateInput) === '') {
                    throw new TreasuryException('An exchange rate is required when the register does not use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
                }
                $rate = $this->rateString($rateInput, 'Exchange rate');
                if (bccomp($rate, '0', 10) <= 0) {
                    throw new TreasuryException('An exchange rate is required when the register does not use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
                }
                $rateSource = trim((string) ($data['rate_source'] ?? ''));
                if ($rateSource === '') {
                    throw new TreasuryException('The exchange-rate source is required (bank rate, central bank, or manual entry).');
                }
                $rateSource = substr($rateSource, 0, 20);
                $rateDate = $this->nullableDate($data['rate_date'] ?? null, 'Rate date') ?? date('Y-m-d');
            }

            $shift = null;
            if (! empty($data['shift_id'])) {
                $shift = $this->validateShiftFeed($creatorId, (int) $data['shift_id']);
            }

            $posted = $this->accounts->postedBalance($register);
            $floatDifference = bcsub($float, $posted, 4);

            $session = TreasuryCashSession::create([
                'created_by' => $creatorId,
                'branch_id' => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
                'register_id' => $register->id,
                'operator_user_id' => (int) $operator->id,
                'shift_id' => $shift ? $shift->id : null,
                'currency' => (string) $register->currency,
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'opened_at' => now(),
                'opening_float' => $float,
                'opening_float_difference' => $floatDifference,
                'expected_balance' => $float,
                'card_total' => '0.0000',
                'mobile_total' => '0.0000',
                'shift_cash_total' => $shift ? $this->decimalString($shift->cash_total ?? '0', 'Shift cash total') : null,
                'shift_card_total' => $shift ? $this->decimalString($shift->card_total ?? '0', 'Shift card total') : null,
                'shift_credit_total' => $shift ? $this->decimalString($shift->credit_total ?? '0', 'Shift credit total') : null,
                'shift_difference' => $shift ? $this->decimalString($shift->difference ?? '0', 'Shift difference') : null,
                'shift_closing_balance' => $shift ? $this->decimalString($shift->closing_balance ?? '0', 'Shift closing balance') : null,
                'state' => TreasuryCashSession::STATE_OPEN,
            ]);

            // A shift feed imports the shift's cash takings once, as
            // one inflow movement — the only path for POS cash to
            // enter the register's Treasury balance.
            if ($shift && bccomp((string) $session->shift_cash_total, '0', 4) > 0) {
                $this->recordMovementInternal(
                    $session,
                    TreasuryCashMovement::TYPE_INFLOW,
                    TreasuryCashMovement::METHOD_CASH,
                    (string) $session->shift_cash_total,
                    'POS shift #' . $shift->id,
                    'POS shift cash takings',
                    $actorId
                );
                $session->refresh();
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'opened', null, $session->fresh()->toArray(), $actorId);

            return $session->fresh();
        });
    }

    /** A shift feeds a session once: closed, same company, never fed before. */
    protected function validateShiftFeed(int $creatorId, int $shiftId): PosShift
    {
        $shift = PosShift::find($shiftId);
        if (! $shift) {
            throw new TreasuryException('POS shift not found.');
        }
        if ((int) $shift->created_by !== $creatorId) {
            throw new TreasuryException('POS shift not found for this company.');
        }
        if ((string) $shift->status !== PosShift::STATUS_CLOSED) {
            throw new TreasuryException('Only a closed POS shift can feed a cash session (shift #' . $shift->id . ' is ' . $shift->status . ').');
        }
        $fed = TreasuryCashSession::where('created_by', $creatorId)
            ->where('shift_id', $shift->id)
            ->exists();
        if ($fed) {
            throw new TreasuryException('POS shift #' . $shift->id . ' has already fed a cash session — a shift feeds a session once.');
        }

        return $shift;
    }

    // ------------------------------------------------------------------
    // Movements
    // ------------------------------------------------------------------

    /**
     * Record a cash movement on an open session.
     *
     * Cash movements write a treasury entry on the session's
     * cash-movement operation (balance movement, no journal). Card
     * and mobile movements are session-local records only, shown
     * separately from physical cash. Handover is NOT recorded here —
     * use handover(), which moves the money through a transfer.
     *
     * @param array{type: string, method?: string, amount: string, reference?: string|null, reason?: string|null} $data
     */
    public function recordMovement(int $creatorId, int $sessionId, array $data, int $actorId): TreasuryCashMovement
    {
        $session = $this->sessionForCompany($creatorId, $sessionId);

        if (! $session->isOpen()) {
            throw new TreasuryException('Movements can only be recorded on an open session (state: ' . $session->state . ').');
        }

        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, TreasuryCashMovement::RECORDABLE_TYPES, true)) {
            throw new TreasuryException('Movement type must be inflow, outflow, float top-up or withdrawal (handover uses its own action).');
        }
        $method = (string) ($data['method'] ?? TreasuryCashMovement::METHOD_CASH);
        if (! in_array($method, TreasuryCashMovement::METHODS, true)) {
            throw new TreasuryException('Movement method must be cash, card or mobile.');
        }
        $amount = $this->decimalString($data['amount'] ?? null, 'Amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The movement amount must be greater than zero.');
        }

        return DB::transaction(function () use ($session, $type, $method, $amount, $data, $actorId) {
            $locked = TreasuryCashSession::lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new TreasuryException('Movements can only be recorded on an open session (state: ' . $locked->state . ').');
            }

            $movement = $this->recordMovementInternal(
                $locked,
                $type,
                $method,
                $amount,
                isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
                isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
                $actorId
            );

            $this->audit((int) $locked->created_by, self::AUDIT_ENTITY, $locked->id, 'movement_recorded', null, $movement->fresh()->toArray(), $actorId);

            return $movement->fresh();
        });
    }

    /**
     * The shared writer: creates the movement row, and for cash
     * movements the treasury entry on the session's operation.
     * Caller must hold the session row lock.
     */
    protected function recordMovementInternal(TreasuryCashSession $session, string $type, string $method, string $amount, ?string $reference, ?string $reason, int $actorId): TreasuryCashMovement
    {
        $amountBase = bcmul($amount, (string) ($session->exchange_rate ?? '1'), 4);

        $movement = TreasuryCashMovement::create([
            'cash_session_id' => $session->id,
            'created_by' => (int) $session->created_by,
            'recorded_by' => $actorId,
            'type' => $type,
            'method' => $method,
            'amount' => $amount,
            'amount_base' => $amountBase,
            'reference' => $reference,
            'reason' => $reason,
        ]);

        // Cash only: the entry moves the register's posted balance.
        // Card/mobile money is not in the till.
        if ($method === TreasuryCashMovement::METHOD_CASH) {
            $operation = $this->sessionOperation($session);
            $direction = in_array($type, [TreasuryCashMovement::TYPE_INFLOW, TreasuryCashMovement::TYPE_FLOAT_TOP_UP], true)
                ? TreasuryEntry::DIRECTION_INFLOW
                : TreasuryEntry::DIRECTION_OUTFLOW;

            $entry = TreasuryEntry::create([
                'operation_id' => $operation->id,
                'financial_account_id' => (int) $session->register_id,
                'direction' => $direction,
                'amount_original' => $amount,
                'amount_base' => $amountBase,
                'exchange_rate' => (string) ($session->exchange_rate ?? '1'),
                'confirmed_at' => now(),
                'journal_id' => null,
                'created_by' => (int) $session->created_by,
            ]);

            $movement->entry_id = $entry->id;
            $movement->save();

            $this->updateSessionOperationAmounts($session);
        }

        $this->refreshSessionTotals($session);

        return $movement->fresh();
    }

    /** Recompute expected physical cash + card/mobile totals from the movement rows. */
    protected function refreshSessionTotals(TreasuryCashSession $session): void
    {
        $expected = (string) $session->opening_float;
        $card = '0.0000';
        $mobile = '0.0000';

        foreach ($session->movements()->get() as $movement) {
            if ($movement->method === TreasuryCashMovement::METHOD_CASH) {
                $expected = $movement->addsCash()
                    ? bcadd($expected, (string) $movement->amount, 4)
                    : bcsub($expected, (string) $movement->amount, 4);
            } elseif ($movement->method === TreasuryCashMovement::METHOD_CARD) {
                $card = $movement->addsCash()
                    ? bcadd($card, (string) $movement->amount, 4)
                    : bcsub($card, (string) $movement->amount, 4);
            } else {
                $mobile = $movement->addsCash()
                    ? bcadd($mobile, (string) $movement->amount, 4)
                    : bcsub($mobile, (string) $movement->amount, 4);
            }
        }

        $session->expected_balance = $expected;
        $session->card_total = $card;
        $session->mobile_total = $mobile;
        if ($session->hasCount()) {
            $session->variance_amount = bcsub((string) $session->counted_balance, $expected, 4);
        }
        $session->save();
    }

    /** The session's cash-movement operation, created lazily on the first cash movement. */
    protected function sessionOperation(TreasuryCashSession $session): TreasuryOperation
    {
        if ($session->operation_id) {
            $operation = TreasuryOperation::lockForUpdate()->findOrFail((int) $session->operation_id);
            return $operation;
        }

        $operation = TreasuryOperation::create([
            'reference' => $this->nextReference((int) $session->created_by, TreasuryOperation::TYPE_CASH_MOVEMENT, date('Y-m-d')),
            'type' => TreasuryOperation::TYPE_CASH_MOVEMENT,
            'company_id' => (int) $session->created_by,
            'branch_id' => $session->branch_id,
            'financial_account_id' => (int) $session->register_id,
            'counterparty_type' => 'entity',
            'counterparty_id' => null,
            'currency' => (string) $session->currency,
            'amount_original' => '0.0000',
            'exchange_rate' => (string) ($session->exchange_rate ?? '1'),
            'rate_source' => $session->rate_source,
            'rate_date' => $session->rate_date,
            'amount_base' => '0.0000',
            'posting_date' => $session->opened_at ? $session->opened_at->format('Y-m-d') : date('Y-m-d'),
            'effective_date' => $session->opened_at ? $session->opened_at->format('Y-m-d') : date('Y-m-d'),
            'value_date' => $session->opened_at ? $session->opened_at->format('Y-m-d') : date('Y-m-d'),
            'state' => TreasuryOperation::STATE_CONFIRMED,
            'source_type' => 'treasury_cash_session',
            'source_id' => $session->id,
            'created_by' => (int) $session->created_by,
        ]);

        $session->operation_id = $operation->id;
        $session->save();

        return $operation;
    }

    /** Keep the session operation's amounts at the net cash moved. */
    protected function updateSessionOperationAmounts(TreasuryCashSession $session): void
    {
        if (! $session->operation_id) {
            return;
        }

        $net = '0.0000';
        foreach ($session->movements()->where('method', TreasuryCashMovement::METHOD_CASH)->get() as $movement) {
            $net = $movement->addsCash()
                ? bcadd($net, (string) $movement->amount, 4)
                : bcsub($net, (string) $movement->amount, 4);
        }

        $operation = TreasuryOperation::lockForUpdate()->findOrFail((int) $session->operation_id);
        $operation->amount_original = $net;
        $operation->amount_base = bcmul($net, (string) ($session->exchange_rate ?? '1'), 4);
        $operation->save();
    }

    // ------------------------------------------------------------------
    // Count (CA09)
    // ------------------------------------------------------------------

    /**
     * Record the denomination count on an open session.
     *
     * @param array<int, array{denomination: string, count: int|string}> $counts
     */
    public function recordCount(int $creatorId, int $sessionId, array $counts, int $actorId): TreasuryCashSession
    {
        $session = $this->sessionForCompany($creatorId, $sessionId);

        if (! $session->isOpen()) {
            throw new TreasuryException('The count can only be recorded on an open session (state: ' . $session->state . ').');
        }
        if ($counts === []) {
            throw new TreasuryException('The count needs at least one denomination.');
        }

        $total = '0.0000';
        $rows = [];
        foreach ($counts as $row) {
            $denomination = $this->decimalString($row['denomination'] ?? null, 'Denomination');
            if (bccomp($denomination, '0', 4) <= 0) {
                throw new TreasuryException('Denominations must be greater than zero.');
            }
            $count = (int) ($row['count'] ?? 0);
            if ($count < 0) {
                throw new TreasuryException('Counts cannot be negative.');
            }
            $amount = bcmul($denomination, (string) $count, 4);
            $total = bcadd($total, $amount, 4);
            $rows[] = ['denomination' => $denomination, 'count' => $count, 'amount' => $amount];
        }

        return DB::transaction(function () use ($session, $rows, $total, $actorId) {
            $locked = TreasuryCashSession::lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new TreasuryException('The count can only be recorded on an open session (state: ' . $locked->state . ').');
            }

            $before = [
                'counted_balance' => $locked->counted_balance !== null ? (string) $locked->counted_balance : null,
                'variance_amount' => $locked->variance_amount !== null ? (string) $locked->variance_amount : null,
            ];

            // The draft count is superseded by the new count; the
            // audit event keeps the before/after. Once closed, count
            // rows are immutable (recordCount refuses a closed
            // session), so the count at close is preserved forever.
            $locked->counts()->delete();
            foreach ($rows as $row) {
                TreasuryCashCount::create([
                    'cash_session_id' => $locked->id,
                    'created_by' => (int) $locked->created_by,
                    'denomination' => $row['denomination'],
                    'count' => $row['count'],
                    'amount' => $row['amount'],
                ]);
            }

            $this->refreshSessionTotals($locked);
            $locked->counted_balance = $total;
            $locked->variance_amount = bcsub($total, (string) $locked->expected_balance, 4);
            $locked->save();

            $this->audit((int) $locked->created_by, self::AUDIT_ENTITY, $locked->id, 'count_recorded', $before, [
                'counted_balance' => $total,
                'variance_amount' => (string) $locked->variance_amount,
                'rows' => $rows,
            ], $actorId);

            return $locked->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Close (CA09) — preview, close, reopen, lock
    // ------------------------------------------------------------------

    /**
     * Preview the close: the confirm screen shows the recheck results
     * before the user closes (plan §8: a financial confirmation shows
     * account, counterparty, currency, amount and effect).
     *
     * Options: variance_reason, variance_approved_by, posting_date,
     * adjustment (CA17).
     *
     * @return array{session: TreasuryCashSession, register: TreasuryFinancialAccount, expected: string, counted: string|null, variance: string|null, variance_delta: string|null, card_total: string, mobile_total: string, posting_date: string, checks: array<int, array{key: string, passed: bool, detail: string}>, can_close: bool, adjustment: array|null}
     */
    public function previewClose(int $creatorId, int $sessionId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);
        $session = $this->sessionForCompany($creatorId, $sessionId);
        $register = $this->accounts->accountForCompany($creatorId, (int) $session->register_id);

        $expected = $this->expectedBalance($session);
        $counted = $session->counted_balance !== null ? (string) $session->counted_balance : null;
        $variance = $counted !== null ? bcsub($counted, $expected, 4) : null;
        $delta = $variance !== null ? bcsub($variance, (string) ($session->variance_posted_amount ?? '0'), 4) : null;

        $posting = $this->resolvePostingDate($session, $options);
        $checks = $this->recheckClose($creatorId, $session, $register, $expected, $counted, $variance, $delta, $posting, $options);

        $canClose = true;
        foreach ($checks as $check) {
            if (! $check['passed']) {
                $canClose = false;
                break;
            }
        }

        return [
            'session' => $session,
            'register' => $register,
            'expected' => $expected,
            'counted' => $counted,
            'variance' => $variance,
            'variance_delta' => $delta,
            'card_total' => (string) $session->card_total,
            'mobile_total' => (string) $session->mobile_total,
            'posting_date' => $posting['posting_date'],
            'checks' => $checks,
            'can_close' => $canClose,
            'adjustment' => $posting['adjustment'],
        ];
    }

    /**
     * Close an open session.
     *
     * The variance adjustment is a SEPARATE posting with its own
     * approval — never folded into the count. Only the delta against
     * the variance already posted is adjusted, so a reopen + re-count
     * + re-close never edits or reverses the earlier adjustment.
     * A variance below ledger precision (rounds to 0.00) posts no
     * journal and is treated as posted.
     *
     * Options: variance_reason, variance_approved_by, posting_date,
     * adjustment (CA17).
     */
    public function closeSession(int $creatorId, int $sessionId, int $actorId, array $options = []): TreasuryCashSession
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $sessionId, $actorId, $options) {
            $session = TreasuryCashSession::lockForUpdate()->findOrFail(
                $this->sessionForCompany($creatorId, $sessionId)->id
            );
            $register = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $session->register_id);

            $expected = $this->expectedBalance($session);
            $counted = $session->counted_balance !== null ? (string) $session->counted_balance : null;
            $variance = $counted !== null ? bcsub($counted, $expected, 4) : null;
            $delta = $variance !== null ? bcsub($variance, (string) ($session->variance_posted_amount ?? '0'), 4) : null;
            $posting = $this->resolvePostingDate($session, $options);

            $checks = $this->recheckClose($creatorId, $session, $register, $expected, $counted, $variance, $delta, $posting, $options);
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            $before = $session->fresh()->toArray();
            $session->expected_balance = $expected;
            $session->variance_amount = $variance;

            if ($delta !== null && bccomp($delta, '0', 4) !== 0) {
                $posted = $this->postVarianceAdjustment($creatorId, $session, $register, $delta, $posting['posting_date'], $actorId);
                if ($posted !== null) {
                    $session->variance_operation_id = $posted['operation']->id;
                    $session->variance_journal_id = $posted['journal']->id;
                }
                // A sub-ledger-precision delta posts nothing but is
                // still settled — it rounds to zero, not to an error.
                $session->variance_posted_amount = $variance;
            }

            if ($variance !== null && bccomp($variance, '0', 4) !== 0) {
                $session->variance_reason = trim((string) ($options['variance_reason'] ?? $session->variance_reason ?? ''));
                $session->variance_approved_by = (int) $options['variance_approved_by'];
                $session->variance_approved_at = now();
            }

            $session->state = TreasuryCashSession::STATE_CLOSED;
            $session->closed_at = now();
            $session->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'closed', $before, $session->fresh()->toArray(), $actorId);

            return $session->fresh();
        });
    }

    /**
     * @return array<int, array{key: string, passed: bool, detail: string}>
     */
    protected function recheckClose(int $creatorId, TreasuryCashSession $session, TreasuryFinancialAccount $register, string $expected, ?string $counted, ?string $variance, ?string $delta, array $posting, array $options): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $detail) use (&$checks) {
            $checks[] = ['key' => $key, 'passed' => $passed, 'detail' => $detail];
        };

        $add('state', $session->isOpen(), $session->isOpen()
            ? 'Session is open and can be closed.'
            : 'Only an open session can be closed (state: ' . $session->state . ').');

        $add('count', $counted !== null, $counted !== null
            ? 'Count recorded: ' . $counted . ' ' . $session->currency . ' against expected ' . $expected . '.'
            : 'Record the denomination count before closing — the variance is computed from the count.');

        if ($variance !== null && bccomp($variance, '0', 4) !== 0) {
            $reason = trim((string) ($options['variance_reason'] ?? $session->variance_reason ?? ''));
            $add('variance_reason', $reason !== '', $reason !== ''
                ? 'Variance reason recorded.'
                : 'A variance of ' . $variance . ' needs a reason before closing.');

            $approverId = (int) ($options['variance_approved_by'] ?? $session->variance_approved_by ?? 0);
            $executorId = (int) ($options['executor_user_id'] ?? 0);
            $approverOk = false;
            $approverDetail = 'A variance of ' . $variance . ' needs approval from a different user holding the approve treasury cash variances permission.';
            if ($approverId > 0) {
                if ($approverId === (int) $session->operator_user_id) {
                    $approverDetail = 'The variance cannot be approved by the session operator — a different user must approve it.';
                } elseif ($executorId > 0 && $approverId === $executorId) {
                    $approverDetail = 'The variance cannot be approved by the user closing the session — a different user must approve it.';
                } elseif (! $this->userHasPermission($creatorId, $approverId, 'approve treasury cash variances')) {
                    $approverDetail = 'The variance approver does not hold the approve treasury cash variances permission.';
                } else {
                    $approverOk = true;
                    $approverDetail = 'Variance approved by user #' . $approverId . ' (separate from operator and closer).';
                }
            }
            $add('variance_approval', $approverOk, $approverDetail);
        } else {
            $add('variance_reason', true, $variance === null ? 'No variance to explain yet.' : 'No variance — the count matches the expected balance.');
            $add('variance_approval', true, 'No variance — no approval needed.');
        }

        // Closed period (CA17): blocked, unless an authorised
        // adjustment to an open period is supplied.
        $postingDate = Carbon::parse($posting['posting_date']);
        $periodOpen = $this->isPeriodOpen($creatorId, $postingDate);
        $adjustmentOk = $posting['adjustment'] !== null;
        $add('period', $periodOpen || $adjustmentOk, $periodOpen
            ? 'Posting period ' . $postingDate->year . ' is open.'
            : ($adjustmentOk
                ? 'Posting year ' . $postingDate->year . ' is closed — posting as an authorised adjustment to ' . $posting['posting_date'] . '.'
                : 'Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).'));

        // The variance accounts must resolve when an adjustment posts.
        if ($delta !== null && bccomp($delta, '0', 4) !== 0 && bccomp($this->round2(bcmul($delta, (string) ($session->exchange_rate ?? '1'), 4)), '0', 2) !== 0) {
            try {
                if (bccomp($delta, '0', 4) < 0) {
                    $this->resolveVarianceShortageAccount($creatorId);
                } else {
                    $this->resolveVarianceSurplusAccount($creatorId);
                }
                $add('posting_accounts', true, 'Variance accounts resolve.');
            } catch (TreasuryException $e) {
                $add('posting_accounts', false, $e->getMessage());
            }
        } else {
            $add('posting_accounts', true, 'No variance adjustment to post.');
        }

        return $checks;
    }

    /**
     * Post the variance adjustment for a signed delta (register
     * currency). Shortage (delta < 0): Dr variance expense / Cr
     * cash, plus an outflow entry. Surplus (delta > 0): Dr cash /
     * Cr variance income, plus an inflow entry. Returns null when
     * the delta rounds to zero at ledger precision.
     *
     * @return array{operation: TreasuryOperation, journal: \App\Models\JournalEntry, entry: TreasuryEntry}|null
     */
    protected function postVarianceAdjustment(int $creatorId, TreasuryCashSession $session, TreasuryFinancialAccount $register, string $delta, string $postingDate, int $actorId): ?array
    {
        $rate = (string) ($session->exchange_rate ?? '1');
        $deltaBase = bcmul($delta, $rate, 4);
        $delta2 = $this->round2($deltaBase);
        if (bccomp($delta2, '0', 2) === 0) {
            return null;
        }

        $registerChartId = $this->chartAccountId($register);
        $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADJUSTMENT, $postingDate);
        $absolute = bccomp($delta, '0', 4) < 0 ? bcmul($delta, '-1', 4) : $delta;
        $absoluteBase = bcmul($absolute, $rate, 4);
        $absolute2 = $this->round2($absoluteBase);

        $operation = TreasuryOperation::create([
            'reference' => $reference,
            'type' => TreasuryOperation::TYPE_ADJUSTMENT,
            'company_id' => $creatorId,
            'branch_id' => $session->branch_id,
            'financial_account_id' => (int) $session->register_id,
            'counterparty_type' => 'entity',
            'counterparty_id' => null,
            'currency' => (string) $session->currency,
            'amount_original' => $absolute,
            'exchange_rate' => $rate,
            'rate_source' => $session->rate_source,
            'rate_date' => $session->rate_date,
            'amount_base' => $absoluteBase,
            'posting_date' => $postingDate,
            'effective_date' => $postingDate,
            'value_date' => $postingDate,
            'state' => TreasuryOperation::STATE_CONFIRMED,
            'source_type' => 'treasury_cash_session',
            'source_id' => $session->id,
            'created_by' => $creatorId,
        ]);

        if (bccomp($delta, '0', 4) < 0) {
            // Shortage: the till holds less than the books say.
            $varianceAccountId = $this->resolveVarianceShortageAccount($creatorId);
            $journal = $this->poster->post($creatorId, $postingDate, $reference, 'Treasury cash variance — shortage, session #' . $session->id, [
                [
                    'account' => $varianceAccountId,
                    'debit' => (float) $absolute2,
                    'description' => 'Cash shortage — session #' . $session->id . ' (' . ($session->variance_reason ?? 'no reason recorded') . ')',
                ],
                [
                    'account' => $registerChartId,
                    'credit' => (float) $absolute2,
                    'description' => 'Cash shortage — register ' . $register->code,
                ],
            ]);
            $direction = TreasuryEntry::DIRECTION_OUTFLOW;
        } else {
            // Surplus: the till holds more than the books say.
            $varianceAccountId = $this->resolveVarianceSurplusAccount($creatorId);
            $journal = $this->poster->post($creatorId, $postingDate, $reference, 'Treasury cash variance — surplus, session #' . $session->id, [
                [
                    'account' => $registerChartId,
                    'debit' => (float) $absolute2,
                    'description' => 'Cash surplus — register ' . $register->code,
                ],
                [
                    'account' => $varianceAccountId,
                    'credit' => (float) $absolute2,
                    'description' => 'Cash surplus — session #' . $session->id . ' (' . ($session->variance_reason ?? 'no reason recorded') . ')',
                ],
            ]);
            $direction = TreasuryEntry::DIRECTION_INFLOW;
        }

        $entry = TreasuryEntry::create([
            'operation_id' => $operation->id,
            'financial_account_id' => (int) $session->register_id,
            'direction' => $direction,
            'amount_original' => $absolute,
            'amount_base' => $absoluteBase,
            'exchange_rate' => $rate,
            'confirmed_at' => now(),
            'journal_id' => $journal->id,
            'created_by' => $creatorId,
        ]);

        $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'variance_adjusted', null, [
            'session_id' => $session->id,
            'reference' => $reference,
            'journal_id' => $journal->id,
            'delta' => $delta,
            'delta_base' => $deltaBase,
            'posting_date' => $postingDate,
        ], $actorId);

        return ['operation' => $operation, 'journal' => $journal, 'entry' => $entry];
    }

    /**
     * Reopen a closed session. The operator cannot reopen their own
     * session alone — a different user holding the manage permission
     * must authorise it, with a reason. The count and the variance
     * already posted stay as they are; a re-count + re-close posts
     * only the delta.
     */
    public function reopenSession(int $creatorId, int $sessionId, int $actorId, string $reason): TreasuryCashSession
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new TreasuryException('Reopening a session needs a reason.');
        }

        return DB::transaction(function () use ($creatorId, $sessionId, $actorId, $reason) {
            $session = TreasuryCashSession::lockForUpdate()->findOrFail(
                $this->sessionForCompany($creatorId, $sessionId)->id
            );

            if (! $session->isClosed()) {
                throw new TreasuryException('Only a closed session can be reopened (state: ' . $session->state . ').');
            }
            if ($actorId === (int) $session->operator_user_id) {
                throw new TreasuryException('The session operator cannot reopen their own closed session — a different user must authorise it.');
            }
            if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury cash sessions')) {
                throw new TreasuryException('Reopening a session needs the manage treasury cash sessions permission.');
            }

            $before = $session->fresh()->toArray();
            $session->state = TreasuryCashSession::STATE_OPEN;
            $session->closed_at = null;
            $session->reopen_reason = $reason;
            $session->reopened_by = $actorId;
            $session->reopened_at = now();
            $session->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'reopened', $before, $session->fresh()->toArray(), $actorId);

            return $session->fresh();
        });
    }

    /** Lock a closed session. Locked is terminal — no reopen, no movements, no count. */
    public function lockSession(int $creatorId, int $sessionId, int $actorId, string $reason): TreasuryCashSession
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new TreasuryException('Locking a session needs a reason.');
        }

        return DB::transaction(function () use ($creatorId, $sessionId, $actorId, $reason) {
            $session = TreasuryCashSession::lockForUpdate()->findOrFail(
                $this->sessionForCompany($creatorId, $sessionId)->id
            );

            if (! $session->isClosed()) {
                throw new TreasuryException('Only a closed session can be locked (state: ' . $session->state . ').');
            }
            if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury cash sessions')) {
                throw new TreasuryException('Locking a session needs the manage treasury cash sessions permission.');
            }

            $before = $session->fresh()->toArray();
            $session->state = TreasuryCashSession::STATE_LOCKED;
            $session->lock_reason = $reason;
            $session->locked_by = $actorId;
            $session->locked_at = now();
            $session->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $session->id, 'locked', $before, $session->fresh()->toArray(), $actorId);

            return $session->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Handover — cash to central Treasury through a Phase 4 transfer
    // ------------------------------------------------------------------

    /**
     * Hand cash from the register to a central account. The money
     * moves through the Phase 4 transfer machinery (immediate,
     * same currency): the transfer's confirm is the receipt
     * confirmation, and its entries + journal are the handover's
     * proof. The movement row records the handover on the session
     * but writes no second entry — the transfer already did.
     *
     * @param array{destination_account_id: int, amount: string, reference?: string|null, reason?: string|null, posting_date?: string|null} $data
     */
    public function handover(int $creatorId, int $sessionId, array $data, int $actorId, array $options = []): TreasuryCashMovement
    {
        $session = $this->sessionForCompany($creatorId, $sessionId);

        if (! $session->isOpen()) {
            throw new TreasuryException('A handover can only happen on an open session (state: ' . $session->state . ').');
        }

        $destinationId = (int) ($data['destination_account_id'] ?? 0);
        if ($destinationId <= 0) {
            throw new TreasuryException('A destination account is required for the handover.');
        }
        if ($destinationId === (int) $session->register_id) {
            throw new TreasuryException('The handover destination must be different from the register.');
        }
        $amount = $this->decimalString($data['amount'] ?? null, 'Handover amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The handover amount must be greater than zero.');
        }

        return DB::transaction(function () use ($creatorId, $session, $destinationId, $amount, $data, $actorId, $options) {
            $locked = TreasuryCashSession::lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw new TreasuryException('A handover can only happen on an open session (state: ' . $locked->state . ').');
            }

            $destination = $this->accounts->accountForCompany($creatorId, $destinationId);
            if (! $destination->isActive()) {
                throw new TreasuryException('The destination account ' . $destination->code . ' is inactive.');
            }
            if ((string) $destination->currency !== (string) $locked->currency) {
                throw new TreasuryException('The handover destination must use the same currency as the register (' . $locked->currency . ') — a handover is a same-currency move.');
            }
            if ($destination->type === TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT) {
                throw new TreasuryException('The handover destination cannot be a funds-in-transit account.');
            }

            $onHand = $this->expectedBalance($locked);
            if (bccomp($amount, $onHand, 4) > 0) {
                throw new TreasuryException('The handover of ' . $amount . ' exceeds the cash on hand (' . $onHand . ' ' . $locked->currency . ').');
            }

            $transfer = $this->transfers->createTransfer($creatorId, [
                'source_account_id' => (int) $locked->register_id,
                'destination_account_id' => $destination->id,
                'amount_debited' => $amount,
            ], $actorId);
            $confirmed = $this->transfers->confirmTransfer($creatorId, $transfer->id, $actorId, [
                'posting_date' => trim((string) ($data['posting_date'] ?? $options['posting_date'] ?? '')) ?: date('Y-m-d'),
            ]);
            $reference = (string) ($confirmed['operation']->reference ?? '');

            $movement = TreasuryCashMovement::create([
                'cash_session_id' => $locked->id,
                'created_by' => $creatorId,
                'recorded_by' => $actorId,
                'type' => TreasuryCashMovement::TYPE_HANDOVER,
                'method' => TreasuryCashMovement::METHOD_CASH,
                'amount' => $amount,
                'amount_base' => bcmul($amount, (string) ($locked->exchange_rate ?? '1'), 4),
                'reference' => $reference !== '' ? $reference : null,
                'reason' => isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null,
                'transfer_id' => $transfer->id,
            ]);

            $this->refreshSessionTotals($locked->fresh());

            $this->audit($creatorId, self::AUDIT_ENTITY, $locked->id, 'handover', null, $movement->fresh()->toArray(), $actorId);

            return $movement->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Balances
    // ------------------------------------------------------------------

    /** Expected physical cash from the session's own records (opening + cash movements). */
    public function expectedBalance(TreasuryCashSession $session): string
    {
        $expected = (string) $session->opening_float;

        foreach ($session->movements()->where('method', TreasuryCashMovement::METHOD_CASH)->get() as $movement) {
            $expected = $movement->addsCash()
                ? bcadd($expected, (string) $movement->amount, 4)
                : bcsub($expected, (string) $movement->amount, 4);
        }

        return $expected;
    }

    /** Cash on hand right now (same as expected — the session's own records). */
    public function cashOnHand(TreasuryCashSession $session): string
    {
        return $this->expectedBalance($session);
    }

    // ------------------------------------------------------------------
    // References, posting accounts, periods
    // ------------------------------------------------------------------

    /** Next reference for the company + type + year, serialised (Phase 1/2/4 pattern). */
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

    /** Resolve a chart account by a configured code/prefix, else the default prefix. Refuses when nothing resolves. */
    protected function resolveAccountOrFail(int $creatorId, ?string $configured, string $defaultPrefix, string $label): int
    {
        $code = trim((string) ($configured ?? ''));

        if ($code !== '') {
            $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
            if ($id !== null) {
                return $id;
            }
            throw new TreasuryException('No chart account found for ' . $label . ' (' . $code . '). Check the Treasury settings.');
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $defaultPrefix);
        if ($id !== null) {
            return $id;
        }

        throw new TreasuryException('No chart account found for ' . $label . '. Set one in Treasury settings (code or prefix).');
    }

    /** Cash shortage: Hoya chart 684 "Perdas em inventários". */
    protected function resolveVarianceShortageAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_CASH_VARIANCE_SHORTAGE_ACCOUNT_CODE),
            '684',
            'cash variance — shortage (684)'
        );
    }

    /** Cash surplus: Hoya chart 764 "Ganhos em inventários". */
    protected function resolveVarianceSurplusAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_CASH_VARIANCE_SURPLUS_ACCOUNT_CODE),
            '764',
            'cash variance — surplus (764)'
        );
    }

    protected function chartAccountId(?TreasuryFinancialAccount $account): int
    {
        $id = $account && $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
        if ($id <= 0) {
            throw new TreasuryException('The financial account has no chart account. Set one on the account before closing.');
        }

        return $id;
    }

    /**
     * @return array{posting_date: string, adjustment: array|null}
     */
    protected function resolvePostingDate(TreasuryCashSession $session, array $options): array
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

        // The authoriser must be a different user than the
        // executor, and must hold the cash-session permission.
        $executorId = (int) ($options['executor_user_id'] ?? 0);
        if ($executorId > 0 && $authorisedBy === $executorId) {
            throw new TreasuryException('The adjustment must be authorised by a different user than the executor.');
        }
        if (! $this->userHasPermission((int) $session->created_by, $authorisedBy, 'manage treasury cash sessions')) {
            throw new TreasuryException('The adjustment authoriser does not hold the manage treasury cash sessions permission.');
        }

        // The adjustment posts in an open period: today, when
        // today is open. (The recheck still blocks the posting
        // when even today is closed.)
        $today = date('Y-m-d');

        return [
            'posting_date' => $today,
            'adjustment' => [
                'intended_date' => $intended,
                'authorised_by' => $authorisedBy,
                'reason' => $reason,
            ],
        ];
    }

    /** Same FY-CLOSE rule as the receipt/execution/transfer services (CA17). */
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

    /** Direct + role permission check (Phase 2/3/4 pattern). */
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

    protected function rateString(mixed $value, ?string $label = null): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            if ($label !== null) {
                throw new TreasuryException($label . ' must be a number.');
            }

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
