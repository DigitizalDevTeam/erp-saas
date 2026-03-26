<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryNote extends Model
{
    protected $fillable = [
        'invoice_id',
        'delivery_note_no',
        'issue_date',
        'status',
        'stock_deducted_at',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'stock_deducted_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function roadManifest()
    {
        return $this->hasOne(RoadManifest::class, 'delivery_note_id');
    }
}

