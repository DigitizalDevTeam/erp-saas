<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchSalesTarget extends Model
{
    protected $fillable = [
        'branch_id',
        'month',
        'target_amount',
        'created_by',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Actual sales for this branch in this month (sum of paid POS totals).
     */
    public function getActualSalesAttribute(): float
    {
        return (float) self::actualSalesFor($this->branch_id, $this->month, $this->created_by);
    }

    /**
     * Get actual sales amount for a branch/month/creator.
     */
    public static function actualSalesFor(int $branchId, string $month, int $createdBy): float
    {
        [$year, $m] = explode('-', $month);
        $list = Pos::where('branch_id', $branchId)
            ->where('created_by', $createdBy)
            ->whereNull('cancelled_at')
            ->whereHas('posPayments')
            ->whereYear('pos_date', $year)
            ->whereMonth('pos_date', (int) $m)
            ->with('items')
            ->get();
        return (float) $list->sum(fn ($pos) => $pos->getTotal());
    }

    /**
     * Get actual sales keyed by 'branch_id-month' for given targets (avoid N+1).
     */
    public static function actualSalesMapForTargets(\Illuminate\Support\Collection $targets, int $createdBy): array
    {
        $map = [];
        foreach ($targets as $t) {
            $key = $t->branch_id . '-' . $t->month;
            $map[$key] = self::actualSalesFor($t->branch_id, $t->month, $createdBy);
        }
        return $map;
    }
}
