<?php

namespace Modules\HotelManagement\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelHousekeepingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_room_id',
        'assigned_to',
        'status',
        'notes',
        'created_by',
    ];

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
