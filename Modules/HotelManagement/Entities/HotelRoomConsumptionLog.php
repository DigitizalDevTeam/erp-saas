<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoomConsumptionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_room_id',
        'hotel_stay_id',
        'hotel_folio_id',
        'hotel_folio_line_id',
        'charge_type',
        'product_id',
        'warehouse_id',
        'service_date',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'service_date' => 'date',
        'unit_price' => 'float',
        'line_total' => 'float',
    ];

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function stay()
    {
        return $this->belongsTo(HotelStay::class, 'hotel_stay_id');
    }

    public function folio()
    {
        return $this->belongsTo(HotelFolio::class, 'hotel_folio_id');
    }

    public function folioLine()
    {
        return $this->belongsTo(HotelFolioLine::class, 'hotel_folio_line_id');
    }
}
