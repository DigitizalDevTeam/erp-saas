<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixed Assets Phase 0 — the asset master / current summary
 * (plan §5, spec §7, §20).
 *
 * The master record is the current summary derived from approved
 * transactions; approved/posted transactions are corrected only
 * by reversal, adjustment or disposal, never by editing or
 * deleting. Phase 0 creates the record and its foundation only —
 * the registration / acquisition / capitalization workflow is
 * Phase 1, so no asset is created through a screen yet.
 *
 * Multi-currency (plan §12b D2): every asset stores its own
 * currency + exchange rate + base-currency value (base = MZN).
 * Depreciation (plan §12b D6): starts on the purchase /
 * capitalization date; useful life is per asset in weeks/months/
 * years.
 */
class FixedAsset extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_TRANSFER = 'in_transfer';
    public const STATUS_UNDER_MAINTENANCE = 'under_maintenance';
    public const STATUS_OUT_OF_SERVICE = 'out_of_service';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_LOST = 'lost';
    public const STATUS_STOLEN = 'stolen';
    public const STATUS_VERIFICATION_PENDING = 'verification_pending';
    public const STATUS_FULLY_DEPRECIATED = 'fully_depreciated';
    public const STATUS_IMPAIRED = 'impaired';
    public const STATUS_HELD_FOR_SALE = 'held_for_sale';
    public const STATUS_DISPOSAL_PENDING = 'disposal_pending';
    public const STATUS_DISPOSED = 'disposed';
    public const STATUS_WRITTEN_OFF = 'written_off';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_AVAILABLE,
        self::STATUS_ACTIVE,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_TRANSFER,
        self::STATUS_UNDER_MAINTENANCE,
        self::STATUS_OUT_OF_SERVICE,
        self::STATUS_DAMAGED,
        self::STATUS_LOST,
        self::STATUS_STOLEN,
        self::STATUS_VERIFICATION_PENDING,
        self::STATUS_FULLY_DEPRECIATED,
        self::STATUS_IMPAIRED,
        self::STATUS_HELD_FOR_SALE,
        self::STATUS_DISPOSAL_PENDING,
        self::STATUS_DISPOSED,
        self::STATUS_WRITTEN_OFF,
        self::STATUS_INACTIVE,
    ];

    public const ACQUISITION_PURCHASE = 'purchase';
    public const ACQUISITION_DONATION = 'donation';
    public const ACQUISITION_OPENING_BALANCE = 'opening_balance';
    public const ACQUISITION_TRANSFER_IN = 'transfer_in';
    public const ACQUISITION_OTHER = 'other';

    public const ACQUISITION_METHODS = [
        self::ACQUISITION_PURCHASE,
        self::ACQUISITION_DONATION,
        self::ACQUISITION_OPENING_BALANCE,
        self::ACQUISITION_TRANSFER_IN,
        self::ACQUISITION_OTHER,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'branch_id',
        'department_id',
        'cost_centre_id',
        'location_id',
        'category_id',
        'custodian_id',
        'parent_asset_id',
        'code',
        'name',
        'description',
        'serial_number',
        'model',
        'brand',
        'barcode_value',
        'is_component',
        'acquisition_method',
        'supplier',
        'po_reference',
        'invoice_reference',
        'purchase_date',
        'receipt_date',
        'purchase_cost',
        'currency',
        'exchange_rate',
        'amount_base',
        'capitalized_cost',
        'warranty_start',
        'warranty_end',
        'capitalization_date',
        'depreciation_start_date',
        'useful_life',
        'life_unit',
        'depreciation_method',
        'depreciation_rate',
        'residual_value',
        'asset_account_id',
        'accum_depreciation_account_id',
        'depreciation_expense_account_id',
        'opening_accumulated_depreciation',
        'status',
        'current_book_value',
    ];

    protected $casts = [
        'is_component' => 'boolean',
        'purchase_date' => 'date',
        'receipt_date' => 'date',
        'warranty_start' => 'date',
        'warranty_end' => 'date',
        'capitalization_date' => 'date',
        'depreciation_start_date' => 'date',
        'useful_life' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(FixedAssetCategory::class, 'category_id');
    }

    public function location()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'location_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function custodian()
    {
        return $this->belongsTo(Employee::class, 'custodian_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function components()
    {
        return $this->hasMany(self::class, 'parent_asset_id')->orderBy('code');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(FixedAssetDocument::class, 'asset_id')->orderByDesc('version');
    }

    public function approvals()
    {
        return $this->hasMany(FixedAssetApproval::class, 'asset_id')->orderBy('created_at');
    }

    public function assignments()
    {
        return $this->hasMany(FixedAssetAssignment::class, 'asset_id')->orderByDesc('created_at');
    }

    public function transfers()
    {
        return $this->hasMany(FixedAssetTransfer::class, 'asset_id')->orderByDesc('created_at');
    }

    public function statusHistory()
    {
        return $this->hasMany(FixedAssetStatusHistory::class, 'asset_id')->orderByDesc('changed_at');
    }

    public function auditEvents()
    {
        return $this->hasMany(FixedAssetAuditEvent::class, 'asset_id')->orderByDesc('created_at');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isDisposed(): bool
    {
        return in_array($this->status, [self::STATUS_DISPOSED, self::STATUS_WRITTEN_OFF], true);
    }

    /** QR/barcode value printed on the label (spec §10). */
    public function labelValue(): string
    {
        return (string) ($this->barcode_value ?: $this->code);
    }
}
