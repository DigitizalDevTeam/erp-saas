<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelFolioPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_folio_id',
        'amount',
        'method',
        'reference',
        'notes',
        'accounting_payment_journal_id',
        'created_by',
    ];

    public function folio()
    {
        return $this->belongsTo(HotelFolio::class, 'hotel_folio_id');
    }
}
