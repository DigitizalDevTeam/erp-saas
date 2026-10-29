<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDeliveryNoteItem extends Model
{
    protected $fillable = [
        'purchase_delivery_note_id',
        'source_type',
        'source_line_id',
        'product_id',
        'product_variant_id',
        'ordered_qty',
        'delivered_qty',
        'price',
        'row_no',
        'shelf_no',
        'batch_no',
        'expiry_date',
        'mfg_date',
        'selected_options_json',
        'created_by',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:4',
        'delivered_qty' => 'decimal:4',
        'price' => 'decimal:2',
        'expiry_date' => 'date',
        'mfg_date' => 'date',
    ];

    public function note()
    {
        return $this->belongsTo(PurchaseDeliveryNote::class, 'purchase_delivery_note_id');
    }

    public function product()
    {
        return $this->hasOne(ProductService::class, 'id', 'product_id');
    }
}
