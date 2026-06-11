<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelBookingCheckout extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hotel_booking_site_id',
        'created_by',
        'stripe_session_id',
        'status',
        'amount_cents',
        'currency',
        'booking_payload',
        'guest_token',
        'hotel_reservation_id',
        'stripe_payment_intent',
        'expires_at',
    ];

    protected $casts = [
        'booking_payload' => 'array',
        'amount_cents' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function bookingSite(): BelongsTo
    {
        return $this->belongsTo(HotelBookingSite::class, 'hotel_booking_site_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
