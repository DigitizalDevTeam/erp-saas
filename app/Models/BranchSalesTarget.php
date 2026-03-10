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
    public static function periodToDateRange(string $periodType, $periodValuea): array
    {
        // $periodValue = $periodValue !== null ? trim((string) $periodValue) : '';

        switch ($periodType) {
            case self::PERIOD_DAY:
                $d = $periodValue !== '' ? Carbon::parse($periodValue)->startOfDay() : Carbon::today()->startOfDay();
                return [$d->format('Y-m-d'), $d->format('Y-m-d')];
            case self::PERIOD_WEEK:
                $d = $periodValue !== '' ? Carbon::parse($periodValue)->startOfWeek() : Carbon::today()->startOfWeek();
                $end = $d->copy()->endOfWeek();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
            case self::PERIOD_MONTH:
                if ($periodValue === '' || preg_match('/^\d{4}-\d{2}$/', $periodValue) !== 1) {
                    $periodValue = Carbon::now()->format('Y-m');
                }
                $d = Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth();
                $end = $d->copy()->endOfMonth();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
            case self::PERIOD_YEAR:
                $year = $periodValue !== '' && preg_match('/^\d{4}$/', (string) $periodValue) ? (string) $periodValue : Carbon::now()->format('Y');
                $d = Carbon::createFromFormat('Y', $year)->startOfYear();
                $end = $d->copy()->endOfYear();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
            default:
                if ($periodValue === '' || preg_match('/^\d{4}-\d{2}$/', $periodValue) !== 1) {
                    $periodValue = Carbon::now()->format('Y-m');
                }
                $d = Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth();
                $end = $d->copy()->endOfMonth();
                return [$d->format('Y-m-d'), $end->format('Y-m-d')];
        }
    }

    /**
     * Get actual sales amount for a branch/period/creator.
     */
    public static function actualSalesFor(int $branchId, string $periodType, $periodValue, int $createdBy): float
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
        $value = $this->period_value !== null ? trim((string) $this->period_value) : '';
        switch ($this->period_type) {
            case self::PERIOD_DAY:
                $d = $value !== '' ? Carbon::parse($value) : Carbon::today();
                return $d->format('M j, Y');
            case self::PERIOD_WEEK:
                $d = $value !== '' ? Carbon::parse($value)->startOfWeek() : Carbon::today()->startOfWeek();
                return $d->format('M j') . ' – ' . $d->copy()->endOfWeek()->format('M j, Y');
            case self::PERIOD_MONTH:
                if ($value === '' || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
                    $value = Carbon::now()->format('Y-m');
                }
                return Carbon::createFromFormat('Y-m', $value)->format('F Y');
            case self::PERIOD_YEAR:
                return $value !== '' && preg_match('/^\d{4}$/', $value) ? $value : Carbon::now()->format('Y');
            default:
                return $value !== '' ? $value : '—';
        }
    }
}
