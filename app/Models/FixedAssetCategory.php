<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixed Assets Phase 0 — asset category (spec §5).
 *
 * A category determines the default accounting, depreciation and
 * approval behaviour of its assets. Codes are user-typed, short
 * and unique per company; a code is never reused after the
 * category is deactivated (the unique key covers soft-deleted
 * rows too).
 */
class FixedAssetCategory extends Model
{
    use SoftDeletes;

    public const CLASSIFICATION_TANGIBLE = 'tangible';
    public const CLASSIFICATION_INTANGIBLE = 'intangible';

    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_TANGIBLE,
        self::CLASSIFICATION_INTANGIBLE,
    ];

    public const LIFE_UNIT_WEEK = 'week';
    public const LIFE_UNIT_MONTH = 'month';
    public const LIFE_UNIT_YEAR = 'year';

    public const LIFE_UNITS = [
        self::LIFE_UNIT_WEEK,
        self::LIFE_UNIT_MONTH,
        self::LIFE_UNIT_YEAR,
    ];

    public const METHOD_STRAIGHT_LINE = 'straight_line';
    public const METHOD_REDUCING_BALANCE = 'reducing_balance';
    public const METHOD_UNITS_OF_PRODUCTION = 'units_of_production';

    // v2 methods are listed so the form can name them, but only
    // straight_line is implemented (Phase 3). Selecting a v2
    // method is refused by the service.
    public const METHODS = [
        self::METHOD_STRAIGHT_LINE,
        self::METHOD_REDUCING_BALANCE,
        self::METHOD_UNITS_OF_PRODUCTION,
    ];

    public const RESIDUAL_NONE = 'none';
    public const RESIDUAL_PERCENTAGE = 'percentage';
    public const RESIDUAL_FIXED = 'fixed';

    public const RESIDUAL_TYPES = [
        self::RESIDUAL_NONE,
        self::RESIDUAL_PERCENTAGE,
        self::RESIDUAL_FIXED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'branch_id',
        'code',
        'name',
        'description',
        'classification',
        'default_useful_life',
        'default_life_unit',
        'depreciation_method',
        'depreciation_rate',
        'residual_value_type',
        'residual_value_percent',
        'residual_value',
        'capitalization_threshold',
        'asset_account_id',
        'accum_depreciation_account_id',
        'depreciation_expense_account_id',
        'impairment_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_useful_life' => 'integer',
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
        return $this->hasMany(FixedAsset::class, 'category_id');
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
