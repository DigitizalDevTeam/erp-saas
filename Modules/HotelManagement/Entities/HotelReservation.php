<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelReservation extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CHECKED_IN = 'checked_in';
    const STATUS_CHECKED_OUT = 'checked_out';
    const STATUS_CANCELLED = 'cancelled';

    public const MEAL_ROOM_ONLY = 'room_only';
    public const MEAL_BREAKFAST = 'breakfast';
    public const MEAL_HALF_BOARD = 'half_board';
    public const MEAL_FULL_BOARD = 'full_board';
    public const MEAL_ALL_INCLUSIVE = 'all_inclusive';

    public const GUARANTEE_NONE = 'none';
    public const GUARANTEE_CARD = 'card';
    public const GUARANTEE_DEPOSIT = 'deposit';
    public const GUARANTEE_VOUCHER = 'voucher';

    protected $fillable = [
        'reservation_no',
        'hotel_guest_id',
        'hotel_group_id',
        'hotel_room_type_id',
        'hotel_room_id',
        'hotel_rate_plan_id',
        'branch_id',
        'check_in_at',
        'check_out_at',
        'estimated_arrival_time',
        'parking_requested',
        'check_in_date',
        'check_out_date',
        'adults',
        'extra_adults',
        'children',
        'infants',
        'source',
        'status',
        'billing_mode',
        'meal_plan',
        'booking_guarantee',
        'payment_method',
        'rate_per_night',
        'discount_amount',
        'discount_percent',
        'discount_reason',
        'guest_email_sent_at',
        'notes',
        'special_requests',
        'internal_notes',
        'hotel_booking_site_id',
        'online_payment_ref',
        'confirmation_token',
        'created_by',
    ];

    protected $casts = [
        'parking_requested' => 'boolean',
        'guest_email_sent_at' => 'datetime',
        'special_requests' => 'array',
        'discount_percent' => 'decimal:2',
    ];

    public static function mealPlanOptions(): array
    {
        return [
            self::MEAL_ROOM_ONLY => __('Accommodation only'),
            self::MEAL_BREAKFAST => __('Breakfast only'),
            self::MEAL_HALF_BOARD => __('Half board'),
            self::MEAL_FULL_BOARD => __('Full board'),
            self::MEAL_ALL_INCLUSIVE => __('All inclusive'),
        ];
    }

    public static function bookingGuaranteeOptions(): array
    {
        return [
            self::GUARANTEE_NONE => __('No guarantee'),
            self::GUARANTEE_CARD => __('Guaranteed by card'),
            self::GUARANTEE_DEPOSIT => __('Deposit'),
            self::GUARANTEE_VOUCHER => __('Voucher'),
        ];
    }

    public static function specialRequestOptions(): array
    {
        return [
            'crib' => __('Crib'),
            'extra_bed' => __('Extra bed'),
            'smoking' => __('Smoking room'),
            'non_smoking' => __('Non-smoking room'),
            'upper_floor' => __('Upper floor'),
            'sea_view' => __('Sea view'),
            'pool_view' => __('Pool view'),
            'reduced_mobility' => __('Reduced mobility access'),
            'honeymoon' => __('Honeymoon'),
            'birthday' => __('Birthday'),
            'vip' => __('VIP'),
        ];
    }

    public function isCheckedIn(): bool
    {
        return $this->status === self::STATUS_CHECKED_IN;
    }

    public function isCheckedOut(): bool
    {
        return $this->status === self::STATUS_CHECKED_OUT;
    }

    public function isLockedForEdit(): bool
    {
        return $this->isCheckedOut();
    }

    public function guest()
    {
        return $this->belongsTo(HotelGuest::class, 'hotel_guest_id');
    }

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function ratePlan()
    {
        return $this->belongsTo(HotelRatePlan::class, 'hotel_rate_plan_id');
    }

    public function stay()
    {
        return $this->hasOne(HotelStay::class);
    }

    public function bookingSite()
    {
        return $this->belongsTo(HotelBookingSite::class, 'hotel_booking_site_id');
    }

    public function reservationAddons()
    {
        return $this->hasMany(HotelReservationAddon::class);
    }

    public function priceLines()
    {
        return $this->hasMany(HotelReservationPriceLine::class);
    }
}
