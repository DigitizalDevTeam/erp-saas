<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelReservationPriceLine extends Model
{
    use HasFactory;

    public const KIND_CHILD = 'child';

    public const KIND_EXTRA_PERSON = 'extra_person';

    protected $fillable = [
        'created_by',
        'hotel_reservation_id',
        'kind',
        'label',
        'rule_id',
        'qty',
        'unit_price',
        'units',
        'amount',
        'hotel_folio_line_id',
    ];

    protected $casts = [
        'hotel_reservation_id' => 'integer',
        'rule_id' => 'integer',
        'qty' => 'integer',
        'unit_price' => 'float',
        'units' => 'integer',
        'amount' => 'float',
        'hotel_folio_line_id' => 'integer',
    ];

    public function reservation()
    {
        return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id');
    }

    public function rule()
    {
        return $this->belongsTo(HotelPriceRule::class, 'rule_id');
    }
}
