<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HotelExpense extends Model
{
    public const CATEGORY_LAUNDRY = 'laundry';
    public const CATEGORY_MAINTENANCE = 'maintenance';
    public const CATEGORY_SUPPLIES = 'supplies';
    public const CATEGORY_UTILITIES = 'utilities';
    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'branch_id',
        'category',
        'title',
        'amount',
        'expense_date',
        'payment_method',
        'vendor',
        'reference',
        'hotel_reservation_id',
        'hotel_room_id',
        'notes',
        'created_by',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_LAUNDRY => __('Laundry'),
            self::CATEGORY_MAINTENANCE => __('Maintenance'),
            self::CATEGORY_SUPPLIES => __('Supplies'),
            self::CATEGORY_UTILITIES => __('Utilities'),
            self::CATEGORY_OTHER => __('Other'),
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id');
    }

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function recorder()
    {
        return $this->belongsTo(\App\Models\User::class, 'recorded_by');
    }
}
