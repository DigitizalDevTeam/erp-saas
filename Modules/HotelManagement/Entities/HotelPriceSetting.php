<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelPriceSetting extends Model
{
    use HasFactory;

    public const DEFAULT_INCLUDED_ADULTS = 2;

    public const DEFAULT_DISCOUNT_CAP_MANAGER = 25.0;

    public const DEFAULT_DISCOUNT_CAP_FRONTDESK = 10.0;

    protected $fillable = [
        'created_by',
        'included_adults',
        'discount_cap_manager_percent',
        'discount_cap_frontdesk_percent',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'included_adults' => 'integer',
        'discount_cap_manager_percent' => 'float',
        'discount_cap_frontdesk_percent' => 'float',
    ];

    public static function forCreator(int $creatorId): self
    {
        return self::query()->firstOrCreate(
            ['created_by' => $creatorId],
            [
                'included_adults' => self::DEFAULT_INCLUDED_ADULTS,
                'discount_cap_manager_percent' => self::DEFAULT_DISCOUNT_CAP_MANAGER,
                'discount_cap_frontdesk_percent' => self::DEFAULT_DISCOUNT_CAP_FRONTDESK,
            ]
        );
    }
}
