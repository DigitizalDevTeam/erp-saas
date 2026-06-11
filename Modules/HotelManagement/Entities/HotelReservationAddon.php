<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelReservationAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_reservation_id',
        'hotel_addon_id',
        'qty',
        'unit_price',
        'billing_mode',
        'hotel_folio_line_id',
        'created_by',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'float',
    ];

    public function reservation()
    {
        return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id');
    }

    public function addon()
    {
        return $this->belongsTo(HotelAddon::class, 'hotel_addon_id');
    }

    public function folioLine()
    {
        return $this->belongsTo(HotelFolioLine::class, 'hotel_folio_line_id');
    }

    public function isPosted(): bool
    {
        return ! empty($this->hotel_folio_line_id);
    }
}
