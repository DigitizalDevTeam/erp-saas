<?php

namespace Modules\HotelManagement\Entities;

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

    public function payments()
    {
        return $this->hasMany(HotelFolioPayment::class, 'hotel_folio_id');
    }

    /**
     * Payment status vs grand_total: pending | partial | paid
     */
    public function paymentStatusLabel(): string
    {
        $paid = (float) $this->paid_total;
        $grand = (float) $this->grand_total;
        if ($grand <= 0) {
            return 'pending';
        }
        if ($paid >= $grand - 0.01) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }

        return 'pending';
    }
}
