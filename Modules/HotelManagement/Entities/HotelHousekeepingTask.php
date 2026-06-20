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
        'source',
        'notes',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public const SOURCE_NIGHT_AUDIT = 'night_audit';
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_MANUAL = 'manual';

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedByUser()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
