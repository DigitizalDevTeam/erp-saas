<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 0 — approval decision (plan §5, spec §3, §24).
 *
 * Every decision (who, when, approve/return/reject, comment) is
 * recorded. No self-approval: the service layer rejects approver
 * = requester for acquisition, transfer, revaluation, impairment,
 * disposal and stock adjustment, at any value.
 */
class FixedAssetApproval extends Model
{
    public const TYPE_ACQUISITION = 'acquisition';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_DISPOSAL = 'disposal';
    public const TYPE_REVALUATION = 'revaluation';
    public const TYPE_IMPAIRMENT = 'impairment';
    public const TYPE_STOCK_ADJUSTMENT = 'stock_adjustment';

    public const TRANSACTION_TYPES = [
        self::TYPE_ACQUISITION,
        self::TYPE_ASSIGNMENT,
        self::TYPE_TRANSFER,
        self::TYPE_DISPOSAL,
        self::TYPE_REVALUATION,
        self::TYPE_IMPAIRMENT,
        self::TYPE_STOCK_ADJUSTMENT,
    ];

    public const DECISION_PENDING = 'pending';
    public const DECISION_APPROVED = 'approved';
    public const DECISION_RETURNED = 'returned';
    public const DECISION_REJECTED = 'rejected';

    public const DECISIONS = [
        self::DECISION_PENDING,
        self::DECISION_APPROVED,
        self::DECISION_RETURNED,
        self::DECISION_REJECTED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'transaction_type',
        'transaction_id',
        'level',
        'requested_by',
        'approver_id',
        'decision',
        'comment',
        'amount_base',
        'decided_at',
    ];

    protected $casts = [
        'level' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isPending(): bool
    {
        return $this->decision === self::DECISION_PENDING;
    }
}
