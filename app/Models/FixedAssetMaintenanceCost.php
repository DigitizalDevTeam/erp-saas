<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 4 — one actual maintenance cost (plan §5;
 * spec §15).
 *
 * A cost is an expense, never a capitalization: recording it
 * posts Dr maintenance expense / Cr accounts payable through
 * JournalEntryPoster only, and never touches the asset's
 * capitalized cost, book value or depreciation schedule.
 * Posted costs are immutable — corrected only by a reversal,
 * never by editing or deleting.
 */
class FixedAssetMaintenanceCost extends Model
{
    public const COST_PARTS = 'parts';
    public const COST_LABOUR = 'labour';
    public const COST_OTHER = 'other';

    public const COST_TYPES = [
        self::COST_PARTS,
        self::COST_LABOUR,
        self::COST_OTHER,
    ];

    protected $fillable = [
        'created_by',
        'maintenance_request_id',
        'asset_id',
        'cost_type',
        'description',
        'amount',
        'vendor',
        'journal_id',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(FixedAssetMaintenanceRequest::class, 'maintenance_request_id');
    }

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
}
