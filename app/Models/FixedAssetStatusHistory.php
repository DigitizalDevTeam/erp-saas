<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 0 — permanent location / custodian /
 * status history (plan §5, spec §24).
 *
 * Append-only: every status, location and custodian change
 * writes one row with who + when. Nothing here is ever edited
 * or deleted.
 */
class FixedAssetStatusHistory extends Model
{
    protected $table = 'fixed_asset_status_history';
    protected $fillable = [
        'created_by',
        'asset_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }
}
