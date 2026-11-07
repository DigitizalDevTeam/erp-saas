<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 2 — approval steps (plan §5, §6).
 *
 * One row per approval level of a payment request, generated from the
 * company's approval rules when the request is submitted. Levels are
 * decided in order (lowest pending level first).
 */
class TreasuryApprovalStep extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_INVALIDATED = 'invalidated';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_RETURNED,
        self::STATUS_INVALIDATED,
    ];

    protected $fillable = [
        'payment_request_id',
        'rule_version',
        'level',
        'permission',
        'required_votes',
        'eligible_user_ids',
        'substitute_user_id',
        'substitute_expiry',
        'status',
        'decided_at',
    ];

    protected $casts = [
        'eligible_user_ids' => 'array',
        'substitute_expiry' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(TreasuryPaymentRequest::class, 'payment_request_id');
    }

    public function decisions()
    {
        return $this->hasMany(TreasuryApprovalDecision::class, 'approval_step_id')->orderBy('decided_at');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Eligible user ids as a list of ints. */
    public function eligibleUserIds(): array
    {
        $ids = $this->eligible_user_ids;
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Is the substitute still valid (set, and not expired)? */
    public function substituteIsValid(): bool
    {
        if ($this->substitute_user_id === null) {
            return false;
        }

        return $this->substitute_expiry === null || $this->substitute_expiry->isFuture();
    }
}
