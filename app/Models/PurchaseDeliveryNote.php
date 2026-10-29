<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDeliveryNote extends Model
{
    protected $fillable = [
        'note_number',
        'vender_id',
        'warehouse_id',
        'purchase_id',
        'bill_id',
        'delivery_date',
        'created_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseDeliveryNoteItem::class, 'purchase_delivery_note_id', 'id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function vender()
    {
        return $this->hasOne(Vender::class, 'id', 'vender_id');
    }

    public function warehouse()
    {
        return $this->hasOne(warehouse::class, 'id', 'warehouse_id');
    }
}
