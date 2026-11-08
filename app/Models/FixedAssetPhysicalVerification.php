<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 4 — physical verification campaign
 * (plan §5; spec §19).
 *
 * Workflow: planned → in_progress → closed; cancelled is
 * terminal. A campaign covers one location, or the whole
 * company when location_id is null. Expected assets are
 * snapshotted into verification items at creation; while the
 * campaign runs its assets are under_verification, and on
 * close each asset returns to its previous status — except
 * missing ones (flagged "lost") and damaged ones (flagged
 * "damaged"). Missing assets are never automatically written
 * off: a write-off is a Phase 5 disposal decision.
 */
class FixedAssetPhysicalVerification extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'created_by',
        'location_id',
        'name',
        'deadline',
        'status',
        'created_by_user',
        'started_by',
        'closed_by',
        'started_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'deadline' => 'date',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function location()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'location_id');
    }

    public function items()
    {
        return $this->hasMany(FixedAssetVerificationItem::class, 'verification_id')->orderBy('asset_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /** Result counts for the summary (found / missing / ...). */
    public function resultCounts(): array
    {
        $counts = array_fill_keys(FixedAssetVerificationItem::RESULTS, 0);
        foreach ($this->items as $item) {
            $counts[$item->result] = ($counts[$item->result] ?? 0) + 1;
        }

        return $counts;
    }
}
