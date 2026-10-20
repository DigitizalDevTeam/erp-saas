<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelAddon extends Model
{
    use HasFactory;

    public const TYPE_EXTRA_BED = 'extra_bed';

    public const TYPE_BREAKFAST = 'breakfast';

    public const TYPE_PARKING = 'parking';

    public const TYPE_LAUNDRY = 'laundry';

    public const TYPE_CHAIR = 'chair';

    public const TYPE_SOFA = 'sofa';

    public const TYPE_PILLOW = 'pillow';

    public const TYPE_OTHER = 'other';

    public const BILLING_PER_NIGHT = 'per_night';

    public const BILLING_PER_STAY = 'per_stay';

    public const BILLING_PER_UNIT = 'per_unit';

    protected $fillable = [
        'name',
        'type',
        'description',
        'price',
        'billing_mode',
        'taxable',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'price' => 'float',
        'taxable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function typeOptions(): array
    {
        return [
            self::TYPE_EXTRA_BED => __('Extra bed'),
            self::TYPE_BREAKFAST => __('Breakfast'),
            self::TYPE_PARKING => __('Parking'),
            self::TYPE_LAUNDRY => __('Laundry'),
            self::TYPE_CHAIR => __('Chair'),
            self::TYPE_SOFA => __('Sofa'),
            self::TYPE_PILLOW => __('Pillow'),
            self::TYPE_OTHER => __('Other'),
        ];
    }

    public static function billingModeOptions(): array
    {
        return [
            self::BILLING_PER_NIGHT => __('Per night'),
            self::BILLING_PER_STAY => __('Per stay'),
            self::BILLING_PER_UNIT => __('Per unit'),
        ];
    }

    public function reservationAddons()
    {
        return $this->hasMany(HotelReservationAddon::class, 'hotel_addon_id');
    }
}
