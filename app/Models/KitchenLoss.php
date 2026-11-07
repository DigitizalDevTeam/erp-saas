<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenLoss extends Model
{
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_RECORDED,
        self::STATUS_APPROVED,
        self::STATUS_POSTED,
        self::STATUS_REJECTED,
    ];

    public const CATEGORY_PREPARATION = 'preparation_loss';
    public const CATEGORY_BURNT_SPOILED = 'burnt_spoiled';
    public const CATEGORY_BUFFET_LEFTOVER = 'buffet_leftover';
    public const CATEGORY_CUSTOMER_RETURN = 'customer_return';
    public const CATEGORY_STAFF_MEAL = 'staff_meal';
    public const CATEGORY_COMPLIMENTARY = 'complimentary';
    public const CATEGORY_TASTING = 'tasting';
    public const CATEGORY_TRANSFER = 'transfer';

    public const CATEGORIES = [
        self::CATEGORY_PREPARATION,
        self::CATEGORY_BURNT_SPOILED,
        self::CATEGORY_BUFFET_LEFTOVER,
        self::CATEGORY_CUSTOMER_RETURN,
        self::CATEGORY_STAFF_MEAL,
        self::CATEGORY_COMPLIMENTARY,
        self::CATEGORY_TASTING,
        self::CATEGORY_TRANSFER,
    ];

    /**
     * Categories that need a manager's approval before they can be
     * posted: food that leaves the business as a gift or a meal
     * (staff meal, complimentary, tasting). Routine operational waste
     * (preparation loss, burnt / spoiled, buffet leftover, customer
     * return, transfer) is recorded directly as approved.
     */
    public const APPROVAL_CATEGORIES = [
        self::CATEGORY_STAFF_MEAL,
        self::CATEGORY_COMPLIMENTARY,
        self::CATEGORY_TASTING,
    ];

    /** Where reused food may go — never a batch that is already sold. */
    public const REUSE_STAFF_MEAL = 'staff_meal';
    public const REUSE_COMPLIMENTARY = 'complimentary';
    public const REUSE_RE_PREPPED = 're_prepped';
    public const REUSE_OTHER = 'other';

    public const REUSE_DESTINATIONS = [
        self::REUSE_STAFF_MEAL,
        self::REUSE_COMPLIMENTARY,
        self::REUSE_RE_PREPPED,
        self::REUSE_OTHER,
    ];

    public static function categoryNeedsApproval(string $category): bool
    {
        return in_array($category, self::APPROVAL_CATEGORIES, true);
    }

    protected $fillable = [
        'loss_number',
        'category',
        'product_id',
        'quantity',
        'unit_id',
        'unit_cost',
        'total_cost',
        'warehouse_id',
        'destination_warehouse_id',
        'reason',
        'recorded_by',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_note',
        'posted_by',
        'posted_at',
        'reuse_flag',
        'food_safety_note',
        'reuse_destination',
        'kitchen_production_order_id',
        'pos_id',
        'pos_product_id',
        'kitchen_production_batch_id',
        'warehouse_loss_id',
        'stock_already_deducted',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'reuse_flag' => 'boolean',
        'stock_already_deducted' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'unit_id', 'id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id', 'id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(warehouse::class, 'destination_warehouse_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id', 'id');
    }

    public function pos()
    {
        return $this->belongsTo(Pos::class, 'pos_id', 'id');
    }

    public function posProduct()
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id', 'id');
    }

    public function batch()
    {
        return $this->belongsTo(KitchenProductionBatch::class, 'kitchen_production_batch_id', 'id');
    }

    public function writeOff()
    {
        return $this->belongsTo(WarehouseLoss::class, 'warehouse_loss_id', 'id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by', 'id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by', 'id');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by', 'id');
    }

    public function isRecorded(): bool
    {
        return $this->status === self::STATUS_RECORDED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function needsApproval(): bool
    {
        return self::categoryNeedsApproval((string) $this->category);
    }
}
