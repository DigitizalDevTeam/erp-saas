<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HotelPriceRule extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_CHILD = 'child';

    public const TYPE_EXTRA_PERSON = 'extra_person';

    public const TYPE_ADDON = 'addon';

    public const BASIS_PER_NIGHT = 'per_night';

    public const BASIS_PER_STAY = 'per_stay';

    protected $fillable = [
        'created_by',
        'name',
        'rule_type',
        'hotel_room_type_id',
        'hotel_addon_id',
        'adults_min',
        'adults_max',
        'qty_from',
        'qty_to',
        'price',
        'price_basis',
        'valid_from',
        'valid_to',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'hotel_room_type_id' => 'integer',
        'hotel_addon_id' => 'integer',
        'adults_min' => 'integer',
        'adults_max' => 'integer',
        'qty_from' => 'integer',
        'qty_to' => 'integer',
        'price' => 'float',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function ruleTypeOptions(): array
    {
        return [
            self::TYPE_CHILD => __('Child'),
            self::TYPE_EXTRA_PERSON => __('Additional person'),
            self::TYPE_ADDON => __('Extra item (addon)'),
        ];
    }

    public static function basisOptions(): array
    {
        return [
            self::BASIS_PER_NIGHT => __('Per night'),
            self::BASIS_PER_STAY => __('Per stay'),
        ];
    }

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }

    public function addon()
    {
        return $this->belongsTo(HotelAddon::class, 'hotel_addon_id');
    }
}
