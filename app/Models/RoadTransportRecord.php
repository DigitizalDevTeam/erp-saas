<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoadTransportRecord extends Model
{
    protected $fillable = [
        'road_manifest_id',
        'record_no',
        'transport_date',
        'vehicle_no',
        'driver_name',
        'from_location',
        'to_location',
        'departure_at',
        'arrival_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transport_date' => 'date',
        'departure_at' => 'datetime',
        'arrival_at' => 'datetime',
    ];

    public function roadManifest()
    {
        return $this->belongsTo(RoadManifest::class, 'road_manifest_id');
    }
}

