<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GL dimensions on posted journal lines (Cost Centre Phase 4, plan §5/§8).
 *
 * One row per (posted journal line, cost centre): which cost centres a
 * posted line's money belongs to, and how much each gets. The line is
 * either a JournalItem (manual journals) or a TransactionLines row
 * (documents posted directly, e.g. bills and expenses) — plan decision
 * D8 decided BOTH, through this one table with a line_type.
 *
 * Dimensions sit BESIDE the money — these rows never create, change or
 * delete a journal line, a bank movement or a balance. Amounts are
 * SIGNED: a debit line carries positive amounts, a credit line carries
 * negative amounts, so dimension totals reconcile to the net GL
 * amounts (debit − credit).
 *
 * Rows are never edited in place: re-tagging a line supersedes the old
 * set (status 'superseded'); a reversal is a NEW row with a negated
 * amount linked to the original via reversal_of_id, and the original
 * row stays readable so the audit trail is complete.
 */
class JournalLineDimension extends Model
{
    public const LINE_JOURNAL_ITEM = 'journal_item';
    public const LINE_TRANSACTION_LINE = 'transaction_line';

    public const LINE_TYPES = [
        self::LINE_JOURNAL_ITEM,
        self::LINE_TRANSACTION_LINE,
    ];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_INHERITED = 'inherited';
    public const SOURCE_REVERSAL = 'reversal';
    public const SOURCE_PAYROLL = 'payroll';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_INHERITED,
        self::SOURCE_REVERSAL,
        self::SOURCE_PAYROLL,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'line_type',
        'line_id',
        'journal_id',
        'cost_centre_id',
        'project_id',
        'asset_id',
        'allocated_amount',
        'percentage',
        'currency_code',
        'source',
        'status',
        'reversal_of_id',
        'reversal_reason',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'percentage' => 'decimal:6',
    ];

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    /** The dimension row this row reverses (reversal rows only). */
    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** Reversal rows created against this row. */
    public function reversals()
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForLine($query, string $lineType, int $lineId)
    {
        return $query->where('line_type', $lineType)->where('line_id', $lineId);
    }

    public function scopeForJournal($query, int $journalId)
    {
        return $query->where('journal_id', $journalId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isReversal(): bool
    {
        return $this->source === self::SOURCE_REVERSAL;
    }
}
