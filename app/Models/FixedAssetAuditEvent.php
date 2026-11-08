<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 0 — audit event (plan §5, spec §24).
 *
 * Append-only. Every lifecycle transition, approval, amendment
 * and master-data change writes one row with actor, action,
 * reason and before/after values. Nothing here is ever edited
 * or deleted.
 */
class FixedAssetAuditEvent extends Model
{
    protected $fillable = [
        'created_by',
        'asset_id',
        'entity',
        'entity_id',
        'action',
        'actor_id',
        'reason',
        'before_values',
        'after_values',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }
}
