<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 4 — one expected asset inside a physical
 * verification campaign (plan §5; spec §19).
 *
 * The row is snapshotted at campaign creation: expected
 * location / custodian and the asset's status at that time
 * (previous_status). The check records the result, who
 * checked it and when; re-checking while the campaign runs
 * overwrites the result (the status only moves on close).
 */
class FixedAssetVerificationItem extends Model
{
    public const RESULT_PENDING = 'pending';
    public const RESULT_FOUND = 'found';
    public const RESULT_MISSING = 'missing';
    public const RESULT_DAMAGED = 'damaged';
    public const RESULT_WRONG_LOCATION = 'wrong_location';
    public const RESULT_WRONG_CUSTODIAN = 'wrong_custodian';

    public const RESULTS = [
        self::RESULT_PENDING,
        self::RESULT_FOUND,
        self::RESULT_MISSING,
        self::RESULT_DAMAGED,
        self::RESULT_WRONG_LOCATION,
        self::RESULT_WRONG_CUSTODIAN,
    ];

    protected $fillable = [
        'created_by',
        'verification_id',
        'asset_id',
        'expected_location_id',
        'expected_custodian_id',
        'previous_status',
        'result',
        'checked_by',
        'checked_at',
        'note',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function verification()
    {
        return $this->belongsTo(FixedAssetPhysicalVerification::class, 'verification_id');
    }

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function expectedLocation()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'expected_location_id');
    }

    public function expectedCustodian()
    {
        return $this->belongsTo(Employee::class, 'expected_custodian_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isChecked(): bool
    {
        return $this->result !== self::RESULT_PENDING;
    }
}
