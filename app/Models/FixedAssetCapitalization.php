<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 1 — capitalization event (plan §5, spec §9).
 *
 * One row per capitalization: the date, the locked capitalized
 * cost and the journal the poster created. The original cost is
 * locked after posting — it is corrected only by reversal,
 * adjustment or disposal, never by editing this row.
 */
class FixedAssetCapitalization extends Model
{
    protected $fillable = [
        'created_by',
        'asset_id',
        'acquisition_id',
        'capitalization_date',
        'capitalized_cost',
        'journal_id',
        'capitalized_by',
    ];

    protected $casts = [
        'capitalization_date' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function acquisition()
    {
        return $this->belongsTo(FixedAssetAcquisition::class, 'acquisition_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }
}
