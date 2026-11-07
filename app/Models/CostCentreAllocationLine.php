<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCentreAllocationLine extends Model
{
    protected $fillable = [
        'allocation_id',
        'cost_centre_id',
        'percentage',
        'fixed_amount',
        'allocated_amount',
        'sort_order',
    ];

    protected $casts = [
        'percentage' => 'decimal:6',
        'fixed_amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
    ];

    public function allocation()
    {
        return $this->belongsTo(CostCentreAllocation::class, 'allocation_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }
}
