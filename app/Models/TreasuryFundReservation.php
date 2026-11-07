<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 2 — fund reservations (plan §5, §6, risk 3).
 *
 * Created when a payment is approved (if reservations are configured).
 * A reservation is NOT a posting — it only reduces the available
 * balance: available = posted − active reservations.
 *
 * Money is DECIMAL(20,4) — decimal casts return strings so bcmath
 * stays exact, never float (RF04).
 */
class TreasuryFundReservation extends Model
{
    public const STATE_ACTIVE = 'active';
    public const STATE_CONSUMED = 'consumed';
    public const STATE_RELEASED = 'released';
    public const STATE_EXPIRED = 'expired';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_ACTIVE,
        self::STATE_CONSUMED,
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
        self::STATE_CANCELLED,
    ];

    protected $fillable = [
        'financial_account_id',
        'payment_request_id',
        'amount',
        'amount_original',
        'currency',
        'expires_at',
        'consumed_amount',
        'released_amount',
        'state',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'amount_original' => 'decimal:4',
        'consumed_amount' => 'decimal:4',
        'released_amount' => 'decimal:4',
        'expires_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(TreasuryPaymentRequest::class, 'payment_request_id');
    }

    public function account()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'financial_account_id');
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    /** Amount still held by this reservation (base currency). */
    public function remaining(): string
    {
        $remaining = bcsub(bcsub((string) $this->amount, (string) $this->consumed_amount, 4), (string) $this->released_amount, 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }
}
