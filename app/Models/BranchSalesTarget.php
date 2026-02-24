<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BranchSalesTarget extends Model
{
    const PERIOD_DAY = 'day';
    const PERIOD_WEEK = 'week';
    const PERIOD_MONTH = 'month';
    const PERIOD_YEAR = 'year';

    protected $fillable = [
        'branch_id',
        'period_type',
        'period_value',
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
     * Actual sales for this branch in this period (sum of paid POS totals).
     */
    public function getActualSalesAttribute(): float
    {
        return (float) self::actualSalesFor($this->branch_id, $this->period_type, $this->period_value, $this->created_by);
    }

    /**
     * Get [start, end] date range for the period (inclusive).
     */
    public static function periodToDateRange(string $periodType, string $periodValue): array
    {
        switch ($periodType) {
            case self::PERIOD_DAY:
                $d = Carbon::parse($periodValue)->startOfDay();
                return [$d->format('Y-m-d'), $d->format('Y-m-d')];
            case self::PERIOD_WEEK:
                $d = Carbon::parse($periodValue)->startOfWeek(); // Monday
                $end = $d->copy()->endOfWeek();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
            case self::PERIOD_MONTH:
                $d = Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth();
                $end = $d->copy()->endOfMonth();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
            case self::PERIOD_YEAR:
                $d = Carbon::createFromFormat('Y', $periodValue)->startOfYear();
                $end = $d->copy()->endOfYear();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
            default:
                $d = Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth();
                $end = $d->copy()->endOfMonth();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
        }
    }

    /**
     * Get actual sales amount for a branch/period/creator.
     */
    public static function actualSalesFor(int $branchId, string $periodType, string $periodValue, int $createdBy): float
    {
        [$start, $end] = self::periodToDateRange($periodType, $periodValue);
        $list = Pos::where('branch_id', $branchId)
            ->where('created_by', $createdBy)
            ->whereNull('cancelled_at')
            ->whereHas('posPayments')
            ->whereDate('pos_date', '>=', $start)
            ->whereDate('pos_date', '<=', $end)
            ->with('items')
            ->get();
        return (float) $list->sum(fn ($pos) => $pos->getTotal());
    }

    /**
     * Get actual sales keyed by 'branch_id-period_type-period_value' for given targets (avoid N+1).
     */
    public static function actualSalesMapForTargets(\Illuminate\Support\Collection $targets, int $createdBy): array
    {
        $map = [];
        foreach ($targets as $t) {
            $key = $t->branch_id . '-' . $t->period_type . '-' . $t->period_value;
            $map[$key] = self::actualSalesFor($t->branch_id, $t->period_type, $t->period_value, $createdBy);
        }
        return $map;
    }

    /**
     * Human-readable period label.
     */
    public function getPeriodLabelAttribute(): string
    {
        switch ($this->period_type) {
            case self::PERIOD_DAY:
                return Carbon::parse($this->period_value)->format('M j, Y');
            case self::PERIOD_WEEK:
                $d = Carbon::parse($this->period_value)->startOfWeek();
                return $d->format('M j') . ' – ' . $d->copy()->endOfWeek()->format('M j, Y');
            case self::PERIOD_MONTH:
                return Carbon::createFromFormat('Y-m', $this->period_value)->format('F Y');
            case self::PERIOD_YEAR:
                return $this->period_value;
            default:
                return $this->period_value;
        }
    }
}
