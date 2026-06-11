<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelFolioLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_folio_id',
        'line_type',
        'revenue_channel',
        'description',
        'quantity',
        'unit_price',
        'tax_amount',
        'discount_amount',
        'line_total',
        'source_pos_id',
        'hotel_addon_id',
        'created_by',
    ];

    public function folio()
    {
        return $this->belongsTo(HotelFolio::class, 'hotel_folio_id');
    }
}
