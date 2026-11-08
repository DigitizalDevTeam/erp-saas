<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 5 — Disposal request (plan §5, §10;
 * spec §16, §17).
 *
 * Workflow: requested → approved → executed (or rejected /
 * cancelled). Only an approved disposal can be executed; the
 * execution posts the disposal journal and flips the asset to
 * disposed (sale / scrapping / donation) or written_off (loss
 * write-off). The asset row itself is never deleted.
 */
class FixedAssetDisposal extends Model
{
    public const METHOD_SALE = 'sale';
    public const METHOD_SCRAPPING = 'scrapping';
    public const METHOD_DONATION = 'donation';
    public const METHOD_WRITE_OFF = 'write_off';

    public const METHODS = [
        self::METHOD_SALE,
        self::METHOD_SCRAPPING,
        self::METHOD_DONATION,
        self::METHOD_WRITE_OFF,
    ];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_EXECUTED,
        self::STATUS_CANCELLED,
    ];

    /** A second disposal cannot start while one of these is open. */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'method',
        'status',
        'reason',
        'expected_proceeds',
        'actual_proceeds',
        'proceeds_received',
        'buyer_name',
        'disposal_date',
        'journal_id',
        'gain_loss_amount',
        'book_value_at_disposal',
        'accumulated_depreciation_at_disposal',
        'previous_status',
        'requested_by',
        'approved_by',
        'executed_by',
        'requested_at',
        'approved_at',
        'executed_at',
        'notes',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'proceeds_received' => 'boolean',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }

    /** Signed gain/loss: positive = gain, negative = loss. */
    public function gainLoss(): ?string
    {
        return $this->gain_loss_amount !== null ? (string) $this->gain_loss_amount : null;
    }
}
