<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Model;

class HotelCashierShift extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const OP_MORNING = 'morning';
    public const OP_AFTERNOON = 'afternoon';
    public const OP_NIGHT = 'night';

    protected $fillable = [
        'branch_id',
        'user_id',
        'operational_shift',
        'opened_at',
        'closed_at',
        'opening_float',
        'expenses_total',
        'closing_cash',
        'expected_cash',
        'cash_collected',
        'card_collected',
        'other_collected',
        'difference',
        'cash_handover',
        'handed_to',
        'notes',
        'status',
        'created_by',
        'closed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'expenses_total' => 'decimal:2',
        'closing_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'cash_collected' => 'decimal:2',
        'card_collected' => 'decimal:2',
        'other_collected' => 'decimal:2',
        'difference' => 'decimal:2',
        'cash_handover' => 'decimal:2',
    ];

    public function expenses()
    {
        return $this->hasMany(HotelExpense::class, 'hotel_cashier_shift_id');
    }

    public static function operationalShiftOptions(): array
    {
        return [
            self::OP_MORNING => __('Morning'),
            self::OP_AFTERNOON => __('Afternoon'),
            self::OP_NIGHT => __('Night'),
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function closedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'closed_by');
    }
}
