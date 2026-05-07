<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelOtaSetting extends Model
{
    use HasFactory;

    protected $table = 'hotel_ota_settings';

    protected $fillable = [
        'channel',
        'enabled',
        'api_key',
        'api_secret',
        'property_id',
        'webhook_url',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
