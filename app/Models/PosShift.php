<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosShift extends Model
{
    const STATUS_OPEN = 'open';
    const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'branch_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'closing_balance',
        'cash_total',
        'card_total',
        'credit_total',
        'difference',
        'tips',
        'notes',
        'sales_count',
        'sales_total',
        'status',
        'created_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tableAssignments()
    {
        return $this->hasMany(PosTableAssignment::class, 'shift_id');
    }

    public function posSales()
    {
        return $this->hasMany(Pos::class, 'shift_id');
    }

    /** Phase 2: Cash drops (withdrawals) during shift */
    public function cashDrops()
    {
        return $this->hasMany(PosCashDrop::class, 'shift_id', 'id');
    }

    /** Cash in (float/top-ups) during shift */
    public function cashIns()
    {
        return $this->hasMany(PosCashIn::class, 'shift_id', 'id');
    }

    public function getCashDropsTotalAttribute(): float
    {
        return (float) $this->cashDrops()->sum('amount');
    }

    public function getCashInsTotalAttribute(): float
    {
        return (float) $this->cashIns()->sum('amount');
    }
}
