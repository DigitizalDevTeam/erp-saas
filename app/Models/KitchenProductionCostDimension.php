<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cost Centre Phase 7 — production: one row per completed kitchen
 * production order, attributing its consumed-ingredient cost
 * (order actual_cost) to the order's cost centre. Attribution
 * beside the money only — no journal, no stock change.
 */
class KitchenProductionCostDimension extends Model
{
    protected $table = 'kitchen_production_cost_dimensions';

    protected $fillable = [
        'company_id',
        'created_by',
        'kitchen_production_order_id',
        'cost_centre_id',
        'project_id',
        'amount',
        'currency_code',
        'status',
        'production_date',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }
}
