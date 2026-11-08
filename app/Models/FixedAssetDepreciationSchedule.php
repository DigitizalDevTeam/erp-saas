<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 1 — depreciation schedule (plan §5, §10;
 * spec §13, §14).
 *
 * One row per asset per month: the PLAN the Phase 3 engine
 * posts against. The schedule is generated (activated) at
 * capitalization from the depreciation start date and the
 * useful life; the monthly run (Phase 3) posts entries against
 * these rows — it never regenerates them.
 *
 * Monthly depreciation = (Capitalized Cost − Residual Value −
 * Opening Accumulated Depreciation) ÷ remaining months, with
 * the rounding residual on the final period so the last closing
 * book value is exactly the residual value.
 */
class FixedAssetDepreciationSchedule extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_POSTED = 'posted';

    /**
     * Phase 6 — a row cancelled at disposal execution. The asset
     * is gone, so its remaining months must never post: the Phase 3
     * engine only computes PLANNED rows, so a cancelled row is
     * invisible to every future run. The row is kept (not deleted)
     * so the schedule still shows what was planned and what was
     * cut short by the disposal.
     */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_POSTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'period',
        'period_number',
        'opening_book_value',
        'period_depreciation',
        'accumulated_depreciation',
        'closing_book_value',
        'remaining_life_months',
        'status',
    ];

    protected $casts = [
        'period' => 'date',
        'period_number' => 'integer',
        'remaining_life_months' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }
}
