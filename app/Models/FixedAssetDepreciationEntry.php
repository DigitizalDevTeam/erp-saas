<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 3 — one asset's line in a monthly
 * depreciation run (plan §5, §10; spec §13, §14).
 *
 * Each entry posts against one Phase 1 schedule row. The
 * unique (created_by + asset_id + period) key is the
 * duplicate-period guard: a period can never post twice for
 * the same asset, whatever the run.
 */
class FixedAssetDepreciationEntry extends Model
{
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_POSTED = 'posted';

    public const STATUSES = [
        self::STATUS_CALCULATED,
        self::STATUS_POSTED,
    ];

    protected $fillable = [
        'created_by',
        'run_id',
        'asset_id',
        'schedule_id',
        'period',
        'planned_amount',
        'posted_amount',
        'cost_centre_id',
        'status',
        'journal_id',
    ];

    protected $casts = [
        'period' => 'date',
    ];

    public function run()
    {
        return $this->belongsTo(FixedAssetDepreciationRun::class, 'run_id');
    }

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function schedule()
    {
        return $this->belongsTo(FixedAssetDepreciationSchedule::class, 'schedule_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }
}
