<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixed Assets Phase 0 — physical asset location (plan §5).
 *
 * Location = Branch + Asset Location. Codes are user-typed, short
 * and unique per company; a code is never reused after the
 * location is deactivated (the unique key covers soft-deleted
 * rows too).
 */
class FixedAssetLocation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'created_by',
        'branch_id',
        'code',
        'name',
        'room',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assets()
    {
        return $this->hasMany(FixedAsset::class, 'location_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
