<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cost Centre Phase 7 — HR payroll: one row per cost centre in a
 * payslip's gross-expense split. Percentages sum to 100.000000%;
 * the residual lands on the deterministic final line (see
 * PayrollDimensionService / AllocationEngine).
 */
class PayslipCostCentreSplit extends Model
{
    protected $table = 'payslip_cost_centre_splits';

    protected $fillable = [
        'company_id',
        'created_by',
        'payslip_id',
        'cost_centre_id',
        'project_id',
        'percentage',
        'amount',
        'updated_by',
    ];

    protected $casts = [
        'percentage' => 'decimal:6',
        'amount' => 'decimal:4',
    ];

    public function payslip()
    {
        return $this->belongsTo(PaySlip::class, 'payslip_id');
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

    public function scopeForPayslip($query, int $payslipId)
    {
        return $query->where('payslip_id', $payslipId)->orderBy('id');
    }
}
