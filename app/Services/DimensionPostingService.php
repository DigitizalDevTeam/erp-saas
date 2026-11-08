<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\CostCentre;
use App\Models\CostCentreAllocation;
use App\Models\DimensionAuditEvent;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\TransactionLines;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GL dimensions on posted journal lines (Cost Centre Phase 4, plan §5/§8).
 *
 * Dimensions attach to the lines the app already posts — a JournalItem
 * (manual journals) or a TransactionLines row (documents posted
 * directly, e.g. bills and expenses) — plan decision D8 decided BOTH,
 * through one journal_line_dimensions table with a line_type.
 *
 * This service NEVER writes a journal line and never changes a debit
 * or credit amount. The only writers of money remain JournalEntryPoster
 * / Utility::addTransactionLines() / DocumentLedgerService. Amounts on
 * dimension rows are SIGNED (debit positive, credit negative) so
 * dimension totals reconcile to the net GL amounts.
 *
 * Two house rules, enforced here:
 *  - A TransactionLines row with reference 'Journal' is only the
 *    mirror of a JournalItem (JournalEntryPoster writes both). Tagging
 *    it would dimension the same money twice, so attachToLine()
 *    normalises it to the JournalItem it mirrors.
 *  - A bill's TransactionLines rows are dimensioned by SYNC from the
 *    posted Phase 1 allocation (syncFromAllocation), never by hand —
 *    the allocation is the dimension source of truth for that line,
 *    and a manual tag on the same row would double-count it.
 */
class DimensionPostingService
{
    public function __construct(
        protected AllocationEngine $engine = new AllocationEngine(),
        protected CostCentreAllocationService $allocations = new CostCentreAllocationService(),
        protected CostCentreService $centres = new CostCentreService(),
    ) {}

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    public function dimensionsForLine(string $lineType, int $lineId, int $creatorId)
    {
        $this->assertLineType($lineType);

        return JournalLineDimension::forCompany($creatorId)
            ->forLine($lineType, $lineId)
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get();
    }

    /** Signed total of the active rows on one line (debit +, credit −). */
    public function activeTotalForLine(string $lineType, int $lineId, int $creatorId): string
    {
        $this->assertLineType($lineType);

        $total = JournalLineDimension::forCompany($creatorId)
            ->forLine($lineType, $lineId)
            ->active()
            ->sum('allocated_amount');

        return $this->engine->round2((string) $total);
    }

    /** One line's active split as a summary. */
    public function summaryForLine(string $lineType, int $lineId, int $creatorId): array
    {
        $rows = $this->dimensionsForLine($lineType, $lineId, $creatorId);
        $info = $this->lineInfo($lineType, $lineId, $creatorId);

        return [
            'line_type' => $lineType,
            'line_id' => $lineId,
            'journal_id' => $info['journal_id'],
            'account_id' => $info['account_id'],
            'line_amount' => $info['net'],
            'total' => $this->activeTotalForLine($lineType, $lineId, $creatorId),
            'rows' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'cost_centre_id' => (int) $r->cost_centre_id,
                'cost_centre_code' => $r->costCentre?->code,
                'project_id' => $r->project_id !== null ? (int) $r->project_id : null,
                'allocated_amount' => (string) $r->allocated_amount,
                'percentage' => $r->percentage !== null ? (string) $r->percentage : null,
                'source' => $r->source,
                'reversal_of_id' => $r->reversal_of_id !== null ? (int) $r->reversal_of_id : null,
            ])->all(),
        ];
    }

    /**
     * Resolve a posted line and verify it belongs to this company.
     * Returns the line's net amount (debit − credit, signed), its
     * absolute amount, its sign, its date, its account and its journal.
     *
     * A TransactionLines row with reference 'Journal' mirrors a
     * JournalItem — it is normalised to that JournalItem, so the same
     * money is never dimensioned twice.
     */
    public function lineInfo(string $lineType, int $lineId, int $creatorId): array
    {
        $this->assertLineType($lineType);

        if ($lineType === JournalLineDimension::LINE_JOURNAL_ITEM) {
            $item = JournalItem::find($lineId);
            if (! $item) {
                throw new CostCentreException(__('The journal line does not exist.'));
            }
            $journal = JournalEntry::find((int) $item->journal);
            if (! $journal) {
                throw new CostCentreException(__('The journal this line belongs to does not exist.'));
            }
            if ((int) $journal->created_by !== $creatorId) {
                throw new CostCentreException(__('This journal line belongs to another company.'));
            }

            return $this->infoFromAmounts(
                (string) $item->debit, (string) $item->credit,
                (int) $item->account, (int) $journal->id,
                $journal->date ? Carbon::parse($journal->date) : Carbon::today(),
                'Journal'
            );
        }

        $line = TransactionLines::find($lineId);
        if (! $line) {
            throw new CostCentreException(__('The transaction line does not exist.'));
        }
        if ((int) $line->created_by !== $creatorId) {
            throw new CostCentreException(__('This transaction line belongs to another company.'));
        }

        // The 'Journal' mirror of a JournalItem: dimension the item.
        if ((string) $line->reference === 'Journal' && (int) $line->reference_sub_id > 0) {
            return $this->lineInfo(JournalLineDimension::LINE_JOURNAL_ITEM, (int) $line->reference_sub_id, $creatorId);
        }

        return $this->infoFromAmounts(
            (string) $line->debit, (string) $line->credit,
            (int) $line->account_id,
            (string) $line->reference === 'Journal' ? (int) $line->reference_id : null,
            $line->date ? Carbon::parse($line->date) : Carbon::today(),
            (string) $line->reference
        );
    }

    protected function infoFromAmounts(string $debit, string $credit, int $accountId, ?int $journalId, Carbon $date, ?string $reference): array
    {
        $debit = $this->engine->round2($debit);
        $credit = $this->engine->round2($credit);
        $net = bcsub($debit, $credit, 2);

        return [
            'net' => $net,
            'abs' => str_starts_with($net, '-') ? substr($net, 1) : $net,
            'sign' => bccomp($net, '0', 2) < 0 ? -1 : 1,
            'account_id' => $accountId,
            'journal_id' => $journalId,
            'date' => $date,
            'reference' => $reference,
        ];
    }

    // ------------------------------------------------------------------
    // Manual tagging — one posted line, many centres
    // ------------------------------------------------------------------

    /**
     * Tag a posted line with a cost centre split. The split is
     * validated by the AllocationEngine (% sums to 100% or fixed
     * amounts equal the line amount, with the deterministic rounding
     * residual) — the split math is never rebuilt here. The split is
     * computed against the line's ABSOLUTE amount and then signed by
     * the line (debit +, credit −), so a credit line's dimensions come
     * out negative and reconcile to the net GL amount.
     *
     * The old active rows for the line are superseded, never edited in
     * place; the audit event keeps the before/after. This writes
     * DIMENSION rows only — it never touches the journal line, the
     * bank balance or any debt.
     */
    public function attachToLine(
        string $lineType,
        int $lineId,
        array $lines,
        string $basis,
        int $creatorId,
        int $userId,
        ?int $projectId = null,
        ?string $source = null
    ): array {
        $source = $source ?? JournalLineDimension::SOURCE_MANUAL;
        if (! in_array($source, JournalLineDimension::SOURCES, true)) {
            throw new CostCentreException(__('Unknown dimension source.'));
        }

        $info = $this->lineInfo($lineType, $lineId, $creatorId);
        // Normalised line (a 'Journal' mirror resolves to its item).
        $lineType = $this->normalisedLineType($lineType, $lineId, $creatorId);
        $lineId = $this->normalisedLineId($lineType, $lineId, $creatorId);

        if (bccomp($info['net'], '0', 2) === 0) {
            throw new CostCentreException(__('This line has no amount to split.'));
        }

        $computed = $this->engine->compute($info['abs'], $lines, $basis);

        foreach ($computed['lines'] as $row) {
            $centre = CostCentre::find($row['cost_centre_id']);
            if (! $centre) {
                throw new CostCentreException(__('The selected cost centre does not exist.'));
            }
            if ((int) $centre->created_by !== $creatorId) {
                throw new CostCentreException(__('Cost centre :code belongs to another company.', ['code' => $centre->code]));
            }
            $this->centres->assertCanAcceptTransactions($centre, $info['date']);
        }

        $this->allocations->assertPeriodOpen($creatorId, $info['date']);

        if ($projectId !== null) {
            $this->assertProjectBelongsToCompany($projectId, $creatorId);
        }

        $currency = $this->baseCurrency($creatorId);
        $sign = $info['sign'];

        return DB::transaction(function () use ($lineType, $lineId, $computed, $creatorId, $userId, $projectId, $currency, $sign, $info, $source) {
            $before = $this->summaryForLine($lineType, $lineId, $creatorId);

            JournalLineDimension::forCompany($creatorId)
                ->forLine($lineType, $lineId)
                ->active()
                ->update(['status' => JournalLineDimension::STATUS_SUPERSEDED]);

            foreach ($computed['lines'] as $row) {
                JournalLineDimension::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'line_type' => $lineType,
                    'line_id' => $lineId,
                    'journal_id' => $info['journal_id'],
                    'cost_centre_id' => $row['cost_centre_id'],
                    'project_id' => $projectId,
                    'asset_id' => null,
                    'allocated_amount' => $sign < 0
                        ? $this->engine->round2('-'.$row['allocated_amount'])
                        : $row['allocated_amount'],
                    'percentage' => $row['percentage'],
                    'currency_code' => $currency,
                    'source' => $source,
                    'status' => JournalLineDimension::STATUS_ACTIVE,
                ]);
            }

            $after = $this->summaryForLine($lineType, $lineId, $creatorId);
            $this->audit($creatorId, $lineId, 'tagged', $before, $after, $userId);

            return $after;
        });
    }

    /** Supersede every active dimension row on a line (it was deleted). */
    public function removeForLine(string $lineType, int $lineId, int $creatorId, int $userId, string $reason): void
    {
        $this->assertLineType($lineType);
        $lineType = $this->normalisedLineType($lineType, $lineId, $creatorId);
        $lineId = $this->normalisedLineId($lineType, $lineId, $creatorId);

        // Closed-period protection (Phase 6 hardening): clearing a tag
        // is a write into the line's period, so it is blocked the same
        // way attachToLine is. lineInfo() also re-asserts the line
        // belongs to this company. Before this check, a closed-period
        // journal edit could strip dimensions while attachToLine
        // refused to re-tag them.
        $info = $this->lineInfo($lineType, $lineId, $creatorId);
        $this->allocations->assertPeriodOpen($creatorId, $info['date']);

        $before = $this->summaryForLine($lineType, $lineId, $creatorId);
        if ($before['rows'] === []) {
            return;
        }

        DB::transaction(function () use ($lineType, $lineId, $creatorId, $userId, $before, $reason) {
            JournalLineDimension::forCompany($creatorId)
                ->forLine($lineType, $lineId)
                ->active()
                ->update(['status' => JournalLineDimension::STATUS_SUPERSEDED]);

            $after = $this->summaryForLine($lineType, $lineId, $creatorId);
            $after['removal_reason'] = $reason;
            $this->audit($creatorId, $lineId, 'removed', $before, $after, $userId);
        });
    }

    // ------------------------------------------------------------------
    // Sync from a Phase 1 allocation — bill/expense lines only
    // ------------------------------------------------------------------

    /**
     * Copy a POSTED Phase 1 allocation onto the bill's TransactionLines
     * rows as journal dimensions. The allocation is the dimension
     * source of truth for that line: the posted split amounts are
     * copied as-is, never recomputed.
     *
     * Idempotent: existing INHERITED rows for the line are superseded
     * first. A MANUAL tag on the same line wins — sync skips that line
     * with a warning instead of overwriting the manager's tag, so the
     * same money is never dimensioned twice.
     */
    public function syncFromAllocation(CostCentreAllocation $allocation, int $creatorId, int $userId): array
    {
        if ((int) $allocation->created_by !== $creatorId) {
            throw new CostCentreException(__('This allocation belongs to another company.'));
        }
        if (! $allocation->isPosted()) {
            throw new CostCentreException(__('Only a posted allocation can be synced to the ledger.'));
        }

        $reference = match ($allocation->source_line_type) {
            CostCentreAllocation::LINE_BILL_PRODUCT => 'Bill',
            CostCentreAllocation::LINE_BILL_ACCOUNT => 'Bill Account',
            default => throw new CostCentreException(__('This allocation line cannot be synced to the ledger.')),
        };

        $tl = TransactionLines::where('created_by', $creatorId)
            ->where('reference', $reference)
            ->where('reference_id', (int) $allocation->source_id)
            ->where('reference_sub_id', (int) $allocation->source_line_id)
            ->first();

        if (! $tl) {
            return [
                'synced' => false,
                'rows' => 0,
                'warnings' => [__('The bill line has no posted transaction line yet, so there is nothing to sync.')],
            ];
        }

        // Closed-period protection (Phase 6 hardening): syncing writes
        // dimension rows dated to the transaction line, so a line in a
        // closed fiscal year refuses to sync — the same way a manual
        // tag on that line is refused. The allocation stays posted and
        // the sync can run after an authorised reopen.
        if ($tl->date) {
            $this->allocations->assertPeriodOpen($creatorId, Carbon::parse($tl->date));
        }

        $warnings = [];
        $tlAmount = $this->engine->round2((string) $tl->debit);
        if (bccomp($tlAmount, (string) $allocation->source_amount, 2) !== 0) {
            $warnings[] = __('The transaction line amount (:tl) differs from the allocation amount (:alloc) — the reconciliation check will flag this line.', [
                'tl' => $tlAmount,
                'alloc' => (string) $allocation->source_amount,
            ]);
        }

        $manual = JournalLineDimension::forCompany($creatorId)
            ->forLine(JournalLineDimension::LINE_TRANSACTION_LINE, (int) $tl->id)
            ->active()
            ->where('source', JournalLineDimension::SOURCE_MANUAL)
            ->exists();
        if ($manual) {
            return [
                'synced' => false,
                'rows' => 0,
                'warnings' => array_merge($warnings, [__('This line already has a manual dimension tag, so the allocation was not synced over it. Clear the manual tag first.')]),
            ];
        }

        $currency = $this->baseCurrency($creatorId);
        $lines = $allocation->lines()->orderBy('sort_order')->get();

        return DB::transaction(function () use ($tl, $lines, $creatorId, $userId, $currency, $warnings, $allocation) {
            $before = $this->summaryForLine(JournalLineDimension::LINE_TRANSACTION_LINE, (int) $tl->id, $creatorId);

            JournalLineDimension::forCompany($creatorId)
                ->forLine(JournalLineDimension::LINE_TRANSACTION_LINE, (int) $tl->id)
                ->active()
                ->where('source', JournalLineDimension::SOURCE_INHERITED)
                ->update(['status' => JournalLineDimension::STATUS_SUPERSEDED]);

            $created = 0;
            foreach ($lines as $line) {
                JournalLineDimension::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'line_type' => JournalLineDimension::LINE_TRANSACTION_LINE,
                    'line_id' => (int) $tl->id,
                    'journal_id' => null,
                    'cost_centre_id' => (int) $line->cost_centre_id,
                    'project_id' => null,
                    'asset_id' => null,
                    'allocated_amount' => (string) $line->allocated_amount,
                    'percentage' => $line->percentage !== null ? (string) $line->percentage : null,
                    'currency_code' => $currency,
                    'source' => JournalLineDimension::SOURCE_INHERITED,
                    'status' => JournalLineDimension::STATUS_ACTIVE,
                ]);
                $created++;
            }

            $after = $this->summaryForLine(JournalLineDimension::LINE_TRANSACTION_LINE, (int) $tl->id, $creatorId);
            $this->audit($creatorId, (int) $tl->id, 'synced', $before, $after, $userId);

            return [
                'synced' => true,
                'rows' => $created,
                'allocation_id' => (int) $allocation->id,
                'warnings' => $warnings,
            ];
        });
    }

    /** Sync every posted allocation of one bill to the ledger. */
    public function syncForBill(Bill $bill, int $creatorId, int $userId): array
    {
        if ((int) $bill->created_by !== $creatorId) {
            throw new CostCentreException(__('This bill belongs to another company.'));
        }

        $sourceType = $this->allocations->sourceTypeForBill($bill);
        $posted = $this->allocations->postedForSource($sourceType, (int) $bill->id, $creatorId);

        $synced = 0;
        $rows = 0;
        $warnings = [];
        foreach ($posted as $allocation) {
            $result = $this->syncFromAllocation($allocation, $creatorId, $userId);
            if ($result['synced']) {
                $synced++;
                $rows += $result['rows'];
            }
            $warnings = array_merge($warnings, $result['warnings']);
        }

        return [
            'allocations' => $posted->count(),
            'synced' => $synced,
            'rows' => $rows,
            'warnings' => $warnings,
        ];
    }

    // ------------------------------------------------------------------
    // Reversal — mirror dimensions onto a reversing journal
    // ------------------------------------------------------------------

    /**
     * Mirror a journal's dimensions onto its reversing journal. The
     * reversal journal itself is posted by the normal journal flow —
     * this method only mirrors the DIMENSIONS: for every active
     * dimension row on the original journal's lines, a NEW active row
     * with the negated amount is created on the matching reversal
     * line (same account, same absolute amount), linked to the original
     * via reversal_of_id. The original rows stay readable — they are
     * never edited, superseded or deleted — so the original audit is
     * kept and the net dimension total (original + reversal) is zero,
     * exactly like the net GL.
     *
     * Idempotent: if the reversal journal already mirrors these rows,
     * nothing is written again.
     */
    public function reverseDimensionsForJournal(
        int $originalJournalId,
        int $reversalJournalId,
        int $creatorId,
        int $userId,
        string $reason
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new CostCentreException(__('A reason is required to reverse journal dimensions.'));
        }
        if ($originalJournalId === $reversalJournalId) {
            throw new CostCentreException(__('The reversal journal must be different from the original journal.'));
        }

        $original = JournalEntry::find($originalJournalId);
        $reversal = JournalEntry::find($reversalJournalId);
        if (! $original || ! $reversal) {
            throw new CostCentreException(__('One of the journals does not exist.'));
        }
        if ((int) $original->created_by !== $creatorId || (int) $reversal->created_by !== $creatorId) {
            throw new CostCentreException(__('These journals belong to another company.'));
        }

        $reversalDate = $reversal->date ? Carbon::parse($reversal->date) : Carbon::today();
        $this->allocations->assertPeriodOpen($creatorId, $reversalDate);

        $originalRows = JournalLineDimension::forCompany($creatorId)
            ->forJournal($originalJournalId)
            ->active()
            ->where('source', '!=', JournalLineDimension::SOURCE_REVERSAL)
            ->orderBy('id')
            ->get();

        if ($originalRows->isEmpty()) {
            return [
                'reversed_rows' => 0,
                'warnings' => [__('The original journal has no dimensions to reverse.')],
                'already_done' => false,
            ];
        }

        // Already mirrored? Any reversal row on the reversal journal
        // linked to one of the original rows means this was done.
        $already = JournalLineDimension::forCompany($creatorId)
            ->forJournal($reversalJournalId)
            ->active()
            ->where('source', JournalLineDimension::SOURCE_REVERSAL)
            ->whereIn('reversal_of_id', $originalRows->pluck('id')->all())
            ->exists();
        if ($already) {
            return [
                'reversed_rows' => 0,
                'warnings' => [__('This journal’s dimensions were already reversed onto the reversal journal.')],
                'already_done' => true,
            ];
        }

        $reversalItems = JournalItem::where('journal', $reversalJournalId)->orderBy('id')->get();
        // One reversal line can receive MANY dimension rows (a 3-way split
        // lands on the one mirrored line), so the match is cached per
        // original line — never "use up" a reversal item after one row.
        $matchByLine = [];

        return DB::transaction(function () use ($originalRows, $reversalItems, &$matchByLine, $creatorId, $userId, $reason, $originalJournalId, $reversalJournalId) {
            $before = $this->summaryForJournal($originalJournalId, $creatorId);
            $created = 0;
            $warnings = [];

            foreach ($originalRows as $row) {
                $originalItem = JournalItem::find((int) $row->line_id);
                if (! $originalItem) {
                    $warnings[] = __('Dimension row #:id points at a journal line that no longer exists, so it was skipped.', ['id' => (int) $row->id]);
                    continue;
                }

                if (! array_key_exists((int) $row->line_id, $matchByLine)) {
                    $originalNet = bcsub((string) $originalItem->debit, (string) $originalItem->credit, 2);
                    $match = null;
                    foreach ($reversalItems as $candidate) {
                        if ((int) $candidate->account !== (int) $originalItem->account) {
                            continue;
                        }
                        $candidateNet = bcsub((string) $candidate->debit, (string) $candidate->credit, 2);
                        // A reversal line is the equal-and-opposite amount.
                        if (bccomp($candidateNet, $this->negate($originalNet), 2) !== 0) {
                            continue;
                        }
                        $match = $candidate;
                        break;
                    }
                    $matchByLine[(int) $row->line_id] = $match;
                }

                $match = $matchByLine[(int) $row->line_id];
                if (! $match) {
                    $warnings[] = __('No matching line was found on the reversal journal for the :account line, so its dimensions were skipped.', ['account' => (int) $originalItem->account]);
                    continue;
                }

                JournalLineDimension::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'line_type' => JournalLineDimension::LINE_JOURNAL_ITEM,
                    'line_id' => (int) $match->id,
                    'journal_id' => $reversalJournalId,
                    'cost_centre_id' => (int) $row->cost_centre_id,
                    'project_id' => $row->project_id,
                    'asset_id' => $row->asset_id,
                    'allocated_amount' => $this->engine->round2($this->negate((string) $row->allocated_amount)),
                    'percentage' => $row->percentage !== null ? (string) $row->percentage : null,
                    'currency_code' => $row->currency_code,
                    'source' => JournalLineDimension::SOURCE_REVERSAL,
                    'status' => JournalLineDimension::STATUS_ACTIVE,
                    'reversal_of_id' => (int) $row->id,
                    'reversal_reason' => $reason,
                ]);
                $created++;
            }

            $after = $this->summaryForJournal($reversalJournalId, $creatorId);
            $this->audit($creatorId, $originalJournalId, 'reversed', $before, $after, $userId);

            return [
                'reversed_rows' => $created,
                'warnings' => $warnings,
                'already_done' => false,
            ];
        });
    }

    /** Active dimensions of one journal, grouped by line. */
    public function summaryForJournal(int $journalId, int $creatorId): array
    {
        $rows = JournalLineDimension::forCompany($creatorId)
            ->forJournal($journalId)
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get();

        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->allocated_amount, 2);
        }

        return [
            'journal_id' => $journalId,
            'total' => $total,
            'rows' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'line_type' => $r->line_type,
                'line_id' => (int) $r->line_id,
                'cost_centre_id' => (int) $r->cost_centre_id,
                'cost_centre_code' => $r->costCentre?->code,
                'allocated_amount' => (string) $r->allocated_amount,
                'source' => $r->source,
                'reversal_of_id' => $r->reversal_of_id !== null ? (int) $r->reversal_of_id : null,
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Guards + helpers
    // ------------------------------------------------------------------

    protected function assertLineType(string $lineType): void
    {
        if (! in_array($lineType, JournalLineDimension::LINE_TYPES, true)) {
            throw new CostCentreException(__('Invalid journal line type.'));
        }
    }

    /**
     * Normalised line type: a 'Journal' TransactionLines mirror
     * resolves to its JournalItem. Returns the type to STORE rows under.
     */
    protected function normalisedLineType(string $lineType, int $lineId, int $creatorId): string
    {
        if ($lineType !== JournalLineDimension::LINE_TRANSACTION_LINE) {
            return $lineType;
        }
        $line = TransactionLines::find($lineId);
        if ($line && (string) $line->reference === 'Journal' && (int) $line->reference_sub_id > 0) {
            return JournalLineDimension::LINE_JOURNAL_ITEM;
        }

        return $lineType;
    }

    protected function normalisedLineId(string $lineType, int $lineId, int $creatorId): int
    {
        if ($lineType !== JournalLineDimension::LINE_TRANSACTION_LINE) {
            return $lineId;
        }
        $line = TransactionLines::find($lineId);
        if ($line && (string) $line->reference === 'Journal' && (int) $line->reference_sub_id > 0) {
            return (int) $line->reference_sub_id;
        }

        return $lineId;
    }

    protected function assertProjectBelongsToCompany(int $projectId, int $creatorId): void
    {
        if (! Schema::hasTable('projects')) {
            throw new CostCentreException(__('Projects do not exist in this app.'));
        }
        $project = DB::table('projects')->where('id', $projectId)->first();
        if (! $project) {
            throw new CostCentreException(__('The selected project does not exist.'));
        }
        if ((int) ($project->created_by ?? 0) !== $creatorId) {
            throw new CostCentreException(__('The selected project belongs to another company.'));
        }
    }

    /** Company base currency (D7: stored from day one; MZN default). */
    public function baseCurrency(int $creatorId): string
    {
        if (! Schema::hasTable('settings')) {
            return 'MZN';
        }
        $code = DB::table('settings')
            ->where('created_by', $creatorId)
            ->where('name', 'site_currency')
            ->value('value');

        return is_string($code) && trim($code) !== '' ? trim($code) : 'MZN';
    }

    protected function negate(string $value): string
    {
        return str_starts_with(ltrim($value), '-') ? substr(ltrim($value), 1) : '-'.ltrim($value);
    }

    protected function audit(int $creatorId, int $entityId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        DimensionAuditEvent::record(
            $creatorId,
            'journal_line_dimension',
            $entityId,
            $action,
            $before,
            $after,
            $actorId
        );
    }
}
