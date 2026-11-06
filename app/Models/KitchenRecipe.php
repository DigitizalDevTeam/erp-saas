<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenRecipe extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'code',
        'name',
        'product_id',
        'category_id',
        'version',
        'status',
        'expected_yield_quantity',
        'expected_yield_unit_id',
        'standard_portion_quantity',
        'standard_portion_unit_id',
        'preparation_time_minutes',
        'equipment',
        'instructions',
        'storage_conditions',
        'allergens',
        'photo',
        'loss_percent',
        'cost_centre_id',
        'branch_id',
        'created_by',
        'submitted_by',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'expected_yield_quantity' => 'decimal:4',
        'standard_portion_quantity' => 'decimal:4',
        'loss_percent' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function expectedYieldUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'expected_yield_unit_id', 'id');
    }

    public function standardPortionUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'standard_portion_unit_id', 'id');
    }

    /** Lines of THIS recipe version only. */
    public function lines()
    {
        return $this->hasMany(ProductRecipe::class, 'kitchen_recipe_id', 'id')
            ->where('version', $this->version)
            ->orderBy('id');
    }

    /** All versions of this recipe (same code, same company). */
    public function versions()
    {
        return self::where('created_by', $this->created_by)
            ->where('code', $this->code)
            ->orderBy('version');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }
}
