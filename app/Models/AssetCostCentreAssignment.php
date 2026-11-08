<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Effective-dated cost-centre assignment of a fixed asset
 * (Cost Centre Phase 2, plan §5).
 *
 * One row = one centre's share of one asset for one validity
 * window. A generation of rows (one assign() call) always sums
 * to exactly 100.000000%; a new generation supersedes the open
 * one (old rows keep their valid_to) and ending closes the open
 * generation — rows are never edited in place, so the history is
 * the audit. Legal ownership, the GL asset account and the
 * physical location stay on the asset itself, never here.
 */
class AssetCostCentreAssignment extends Model
{
    protected $fillable = [
        'asset_id',
        'company_id',
        'cost_centre_id',
        'percentage',
        'valid_from',
        'valid_to',
        'created_by',
    ];

    protected $casts = [
        'percentage' => 'decimal:6',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopeForAsset($query, int $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    /** Rows in force on one date (valid_from <= date <= valid_to, or open). */
    public function scopeActiveOn($query, Carbon $date)
    {
        $day = $date->copy()->startOfDay()->format('Y-m-d');

        return $query->whereDate('valid_from', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day);
            });
    }

    /** Rows whose validity window intersects [from, to]. */
    public function scopeOverlapping($query, Carbon $from, Carbon $to)
    {
        $fromDay = $from->copy()->startOfDay()->format('Y-m-d');
        $toDay = $to->copy()->startOfDay()->format('Y-m-d');

        return $query->whereDate('valid_from', '<=', $toDay)
            ->where(function ($q) use ($fromDay) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $fromDay);
            });
    }

    public function isOpen(): bool
    {
        return $this->valid_to === null;
    }
}
