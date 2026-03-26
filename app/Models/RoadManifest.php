<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadManifest extends Model
{
    protected $fillable = [
        'delivery_note_id',
        'manifest_no',
        'vehicle_no',
        'driver_name',
        'notes',
        'status',
        'created_by',
    ];

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    public function transportRecords()
    {
        return $this->hasMany(RoadTransportRecord::class, 'road_manifest_id');
    }
}

