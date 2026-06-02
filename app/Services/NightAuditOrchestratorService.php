<?php

namespace App\Services;

use App\Models\Pos;
use App\Models\PosDayClosed;
use App\Models\PosCashDrop;
use App\Models\PosCashIn;
use App\Models\PosPayment;
use App\Models\PosShift;
use App\Models\Utility;
use App\Support\AttendanceShiftCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NightAuditOrchestratorService
{
    /**
     * Close business day: finalize open POS shifts, lock date, return summary for hotel night audit.
     *
     * @return array{pos_sales_total: float, pos_payments_total: float, shifts_closed: int, summary: array}
     */
    public static function closeBusinessDay(int $creatorId, string $auditDate, ?int $branchId = null, ?int $closedByUserId = null): array
    {
        $closedByUserId = $closedByUserId ?? (int) Auth::id();

        return DB::transaction(function () use ($creatorId, $auditDate, $branchId, $closedByUserId) {
            $shiftsClosed = self::closeOpenPosShifts($creatorId, $auditDate, $branchId);

            $posQuery = Pos::where('created_by', $creatorId)
                ->whereDate('pos_date', $auditDate)
                ->whereNull('cancelled_at');
            if ($branchId) {
                $posQuery->where('branch_id', $branchId);
            }
            $posIds = $posQuery->pluck('id');

            $posSalesTotal = (float) Pos::whereIn('id', $posIds)->get()->sum(fn ($p) => $p->getTotal());
            $posPaymentsTotal = (float) PosPayment::whereIn('pos_id', $posIds)->sum('amount');

            $byOperational = [];
            foreach (AttendanceShiftCatalog::operationalShiftOptions() as $code => $label) {
                $shiftIds = PosShift::where('created_by', $creatorId)
                    ->whereDate('opened_at', $auditDate)
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->where('operational_shift', $code)
                    ->pluck('id');
                $ids = Pos::whereIn('shift_id', $shiftIds)->whereNull('cancelled_at')->pluck('id');
                $byOperational[$code] = [
                    'label' => $label,
                    'sales' => (float) PosPayment::whereIn('pos_id', $ids)->sum('amount'),
                    'shifts' => $shiftIds->count(),
                ];
            }

            PosDayClosed::firstOrCreate(
                [
                    'closed_date' => $auditDate,
                    'created_by' => $creatorId,
                ],
                [
                    'closed_by' => $closedByUserId,
                    'closed_at' => now(),
                    'locked_by_night_audit' => true,
                ]
            );
            PosDayClosed::where('closed_date', $auditDate)
                ->where('created_by', $creatorId)
                ->update(['locked_by_night_audit' => true, 'closed_at' => now()]);

            return [
                'pos_sales_total' => $posSalesTotal,
                'pos_payments_total' => $posPaymentsTotal,
                'shifts_closed' => $shiftsClosed,
                'summary' => [
                    'audit_date' => $auditDate,
                    'branch_id' => $branchId,
                    'operational_shifts' => $byOperational,
                    'closed_at' => now()->toIso8601String(),
                ],
            ];
        });
    }

    public static function closeOpenPosShifts(int $creatorId, string $auditDate, ?int $branchId = null): int
    {
        $query = PosShift::where('created_by', $creatorId)
            ->where('status', PosShift::STATUS_OPEN)
            ->whereDate('opened_at', '<=', $auditDate);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $count = 0;
        foreach ($query->get() as $shift) {
            $posIds = Pos::where('shift_id', $shift->id)->pluck('id');
            $salesTotal = (float) PosPayment::whereIn('pos_id', $posIds)->sum('amount');
            $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)
                ->where(function ($q) {
                    $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
                })
                ->sum('amount');
            $expectedCash = (float) $shift->opening_balance + $cashTotal
                - (float) PosCashDrop::where('shift_id', $shift->id)->sum('amount')
                + (float) PosCashIn::where('shift_id', $shift->id)->sum('amount');

            $shift->closed_at = now();
            $shift->closing_balance = $expectedCash;
            $shift->cash_total = $cashTotal;
            $shift->sales_total = $salesTotal;
            $shift->sales_count = $posIds->count();
            $shift->status = PosShift::STATUS_CLOSED;
            $shift->save();
            $count++;
        }

        return $count;
    }

    public static function configuredNightAuditTime(int $creatorId): string
    {
        $settings = Utility::settingsById($creatorId);

        return self::normalizeTimeString($settings['night_audit_time'] ?? '02:00');
    }

    public static function normalizeTimeString(string $time): string
    {
        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Throwable $e) {
            return '02:00';
        }
    }
}
