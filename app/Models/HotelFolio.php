<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelFolio extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_stay_id',
        'folio_no',
        'sub_total',
        'tax_total',
        'discount_total',
        'grand_total',
        'paid_total',
        'status',
        'created_by',
    ];

    public function stay()
    {
        return $this->belongsTo(HotelStay::class, 'hotel_stay_id');
    }

    public function lines()
    {
        return $this->hasMany(HotelFolioLine::class);
    }
}
