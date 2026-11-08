<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 1 — acquisition event (plan §5, spec §6, §9).
 *
 * One row per acquisition, carrying the workflow:
 * draft → pending_review → pending_approval → approved →
 * capitalized (or returned / rejected / cancelled). The asset
 * master row is created together with the acquisition; the
 * acquisition is the workflow record, the asset is the summary.
 */
class FixedAssetAcquisition extends Model
{
    public const SOURCE_PURCHASE_PRODUCT = 'purchase_product';
    public const SOURCE_BILL_PRODUCT = 'bill_product';
    public const SOURCE_DELIVERY_NOTE_ITEM = 'delivery_note_item';
    public const SOURCE_DIRECT = 'direct';
    public const SOURCE_DONATION = 'donation';
    public const SOURCE_OPENING_BALANCE = 'opening_balance';

    public const SOURCE_TYPES = [
        self::SOURCE_PURCHASE_PRODUCT,
        self::SOURCE_BILL_PRODUCT,
        self::SOURCE_DELIVERY_NOTE_ITEM,
        self::SOURCE_DIRECT,
        self::SOURCE_DONATION,
        self::SOURCE_OPENING_BALANCE,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CAPITALIZED = 'capitalized';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_CAPITALIZED,
        self::STATUS_RETURNED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'source_type',
        'source_id',
        'source_reference',
        'supplier',
        'purchase_date',
        'receipt_date',
        'currency',
        'exchange_rate',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'capitalized_at',
        'journal_id',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'receipt_date' => 'date',
        'reviewed_at' => 'datetime',
        'capitalized_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function costs()
    {
        return $this->hasMany(FixedAssetAcquisitionCost::class, 'acquisition_id')->orderBy('id');
    }

    public function capitalization()
    {
        return $this->hasOne(FixedAssetCapitalization::class, 'acquisition_id');
    }

    /**
     * Approval rows for this acquisition: FixedAssetApproval rows
     * with transaction_type 'acquisition' and transaction_id =
     * this acquisition's id, ordered by level.
     */
    public function approvals()
    {
        return $this->hasMany(FixedAssetApproval::class, 'transaction_id')
            ->where('transaction_type', FixedAssetApproval::TYPE_ACQUISITION)
            ->orderBy('level');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isCapitalized(): bool
    {
        return $this->status === self::STATUS_CAPITALIZED;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED], true);
    }

    /**
     * Total acquisition cost in the acquisition currency: the
     * sum of the cost components' signed contributions
     * (recoverable VAT excluded, discounts negative).
     */
    public function getTotalCostAttribute(): string
    {
        $costs = $this->relationLoaded('costs') ? $this->costs : $this->costs()->get();
        $total = '0.0000';
        foreach ($costs as $cost) {
            $total = bcadd($total, (string) $cost->contribution, 4);
        }

        return $total;
    }

    /** Total acquisition cost in base currency (sum of amount_base). */
    public function getTotalCostBaseAttribute(): string
    {
        $costs = $this->relationLoaded('costs') ? $this->costs : $this->costs()->get();
        $total = '0.0000';
        foreach ($costs as $cost) {
            $total = bcadd($total, (string) $cost->amount_base, 4);
        }

        return $total;
    }
}
