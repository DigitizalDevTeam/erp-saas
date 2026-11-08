<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 3 — monthly depreciation run (plan §5,
 * §10; spec §13, §14).
 *
 * One run per company + period. Workflow (spec §13):
 * CALCULATED → REVIEWED → APPROVED → POSTED. The preview
 * (calculation) never posts; posting is a separate, explicit
 * step after approval and writes ONE journal per run via
 * JournalEntryPoster.
 */
class FixedAssetDepreciationRun extends Model
{
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_CALCULATED,
        self::STATUS_REVIEWED,
        self::STATUS_APPROVED,
        self::STATUS_POSTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'created_by',
        'period',
        'status',
        'total_amount',
        'asset_count',
        'journal_id',
        'requested_by',
        'reviewed_by',
        'approved_by',
        'posted_by',
        'reviewed_at',
        'approved_at',
        'posted_at',
        'notes',
    ];

    protected $casts = [
        'period' => 'date',
        'asset_count' => 'integer',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(FixedAssetDepreciationEntry::class, 'run_id')->orderBy('asset_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isCalculated(): bool
    {
        return $this->status === self::STATUS_CALCULATED;
    }

    public function isReviewed(): bool
    {
        return $this->status === self::STATUS_REVIEWED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
