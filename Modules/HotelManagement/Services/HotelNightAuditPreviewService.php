<?php

namespace Modules\HotelManagement\Services;

use App\Models\Pos;
use App\Models\PosPayment;
use App\Services\BusinessDayLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelFolioPayment;
use Modules\HotelManagement\Entities\HotelNightAudit;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelStay;

class HotelNightAuditPreviewService
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $creatorId, string $auditDate, ?int $branchId = null): array
    {
        $date = Carbon::parse($auditDate)->toDateString();
        $previousDate = Carbon::parse($auditDate)->subDay()->toDateString();

        $totalRooms = HotelRoom::where('created_by', $creatorId)->count();
        $occupancy = $this->occupancyForDate($creatorId, $date, $totalRooms);
        $occupancyPrevious = $this->occupancyForDate($creatorId, $previousDate, $totalRooms);

        $revenue = $this->revenueForDate($creatorId, $date);
        $payments = $this->paymentsForDate($creatorId, $date);
        $pos = $this->posTotalsForDate($creatorId, $date, $branchId);
        $other = $this->otherSummaries($creatorId, $date, $revenue);
        $checklist = $this->preAuditChecklist($creatorId, $date);

        $isClosed = BusinessDayLockService::isLocked($creatorId, $date)
            || HotelNightAudit::where('created_by', $creatorId)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('audit_date', $date)
                ->where('status', 'closed')
                ->exists();

        $cashReceived = (float) ($payments['by_method']['cash'] ?? 0) + (float) ($pos['cash_payments'] ?? 0);
        $openFolioBalance = (float) $checklist['open_folios_balance'];

        return [
            'audit_date' => $date,
            'is_closed' => $isClosed,
            'occupancy' => $occupancy,
            'occupancy_previous' => $occupancyPrevious,
            'revenue' => $revenue,
            'payments' => $payments,
            'pos' => $pos,
            'cash_register' => [
                'opening_balance' => 0.0,
                'cash_inflows' => round($cashReceived, 2),
                'cash_outflows' => 0.0,
                'closing_balance' => round($cashReceived, 2),
            ],
            'advances' => [
                'received' => round((float) $payments['total'], 2),
                'used' => round(max(0, (float) $payments['total'] - $openFolioBalance), 2),
                'balance' => round($openFolioBalance, 2),
            ],
            'other' => $other,
            'checklist' => $checklist,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function occupancyForDate(int $creatorId, string $date, int $totalRooms): array
    {
        $occupied = (int) HotelReservation::where('created_by', $creatorId)
            ->whereNotNull('hotel_room_id')
            ->whereIn('status', [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CONFIRMED])
            ->whereDate('check_in_date', '<=', $date)
            ->whereDate('check_out_date', '>', $date)
            ->distinct()
            ->count('hotel_room_id');

        $arrivals = (int) HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_in_date', $date)
            ->count();

        $departures = (int) HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_out_date', $date)
            ->whereIn('status', [HotelReservation::STATUS_CHECKED_OUT, HotelReservation::STATUS_CHECKED_IN])
            ->count();

        $available = max(0, $totalRooms - $occupied);
        $rate = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 2) : 0.0;

        return [
            'total_rooms' => $totalRooms,
            'occupied' => $occupied,
            'available' => $available,
            'rate' => $rate,
            'arrivals' => $arrivals,
            'departures' => $departures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenueForDate(int $creatorId, string $date): array
    {
        $lines = HotelFolioLine::query()
            ->where('created_by', $creatorId)
            ->whereDate('created_at', $date)
            ->get(['line_type', 'line_total', 'tax_amount']);

        $lodging = 0.0;
        $services = 0.0;
        $laundry = 0.0;
        $minibar = 0.0;
        $other = 0.0;
        $taxes = 0.0;

        foreach ($lines as $line) {
            $amount = (float) $line->line_total;
            $taxes += (float) $line->tax_amount;
            match ((string) $line->line_type) {
                'room_charge', 'addon_charge' => $lodging += $amount,
                'pos_charge' => $services += $amount,
                'laundry_charge' => $laundry += $amount,
                'minibar_charge' => $minibar += $amount,
                default => $other += $amount,
            };
        }

        $total = round($lodging + $services + $laundry + $minibar + $other, 2);

        return [
            'lodging' => round($lodging, 2),
            'services' => round($services, 2),
            'laundry' => round($laundry, 2),
            'minibar' => round($minibar, 2),
            'other' => round($other, 2),
            'total' => $total,
            'taxes' => round($taxes, 2),
            'by_source' => [
                ['label' => 'room_charges', 'amount' => round($lodging, 2)],
                ['label' => 'restaurant_pos', 'amount' => round($services, 2)],
                ['label' => 'laundry_extras', 'amount' => round($laundry + $other, 2)],
                ['label' => 'minibar', 'amount' => round($minibar, 2)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsForDate(int $creatorId, string $date): array
    {
        if (! Schema::hasTable('hotel_folio_payments')) {
            return ['by_method' => [], 'total' => 0.0];
        }

        $rows = HotelFolioPayment::query()
            ->where('created_by', $creatorId)
            ->whereDate('created_at', $date)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $byMethod = [];
        $total = 0.0;
        foreach ($rows as $method => $amount) {
            $byMethod[(string) $method] = round((float) $amount, 2);
            $total += (float) $amount;
        }

        return [
            'by_method' => $byMethod,
            'total' => round($total, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function posTotalsForDate(int $creatorId, string $date, ?int $branchId): array
    {
        $posQuery = Pos::where('created_by', $creatorId)
            ->whereDate('pos_date', $date)
            ->whereNull('cancelled_at');
        if ($branchId) {
            $posQuery->where('branch_id', $branchId);
        }
        $posIds = $posQuery->pluck('id');

        if ($posIds->isEmpty()) {
            return ['sales_total' => 0.0, 'payments_total' => 0.0, 'cash_payments' => 0.0];
        }

        $salesTotal = (float) Pos::whereIn('id', $posIds)->get()->sum(fn ($p) => $p->getTotal());
        $paymentsTotal = (float) PosPayment::whereIn('pos_id', $posIds)->sum('amount');
        $cashPayments = (float) PosPayment::whereIn('pos_id', $posIds)
            ->where(function ($q) {
                $q->whereRaw('LOWER(payment_method) = ?', ['cash'])
                    ->orWhere('payment_method', '0');
            })
            ->sum('amount');

        return [
            'sales_total' => round($salesTotal, 2),
            'payments_total' => round($paymentsTotal, 2),
            'cash_payments' => round($cashPayments, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $revenue
     * @return array<string, mixed>
     */
    private function otherSummaries(int $creatorId, string $date, array $revenue): array
    {
        $cancellations = HotelReservation::where('created_by', $creatorId)
            ->where('status', HotelReservation::STATUS_CANCELLED)
            ->whereDate('updated_at', $date)
            ->count();

        $noShows = HotelReservation::where('created_by', $creatorId)
            ->where('status', HotelReservation::STATUS_PENDING)
            ->whereDate('check_in_date', $date)
            ->count();

        $adjustments = (float) HotelFolioLine::where('created_by', $creatorId)
            ->whereDate('created_at', $date)
            ->sum('discount_amount');

        return [
            'cancellations' => ['count' => $cancellations, 'amount' => 0.0],
            'no_shows' => ['count' => $noShows, 'amount' => 0.0],
            'adjustments' => ['count' => $adjustments > 0 ? 1 : 0, 'amount' => round($adjustments, 2)],
            'refunds' => ['count' => 0, 'amount' => 0.0],
            'taxes' => $revenue['taxes'] ?? 0.0,
            'grand_total' => $revenue['total'] ?? 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preAuditChecklist(int $creatorId, string $date): array
    {
        $openFolios = HotelFolio::where('created_by', $creatorId)
            ->whereHas('stay', fn ($q) => $q->where('status', 'in_house'))
            ->with(['stay.reservation.guest', 'stay.reservation.room'])
            ->get()
            ->filter(fn (HotelFolio $f) => $f->paymentStatusLabel() !== 'paid')
            ->values();

        $openBalance = $openFolios->sum(fn (HotelFolio $f) => max(0, (float) $f->grand_total - (float) $f->paid_total));

        $inHouseRoomIds = HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->pluck('hotel_room_id')
            ->filter()
            ->unique();

        $anomalies = [];

        $occupiedWithoutStay = HotelRoom::where('created_by', $creatorId)
            ->where('status', HotelRoom::STATUS_OCCUPIED)
            ->whereNotIn('id', $inHouseRoomIds)
            ->get(['id', 'room_no']);

        foreach ($occupiedWithoutStay as $room) {
            $anomalies[] = [
                'type' => 'room_occupied_no_guest',
                'room_no' => $room->room_no,
                'message' => __('Room marked occupied but no in-house stay.'),
            ];
        }

        $staysWithoutOccupied = HotelRoom::where('created_by', $creatorId)
            ->whereIn('id', $inHouseRoomIds)
            ->where('status', '!=', HotelRoom::STATUS_OCCUPIED)
            ->get(['id', 'room_no', 'status']);

        foreach ($staysWithoutOccupied as $room) {
            $anomalies[] = [
                'type' => 'guest_in_non_occupied_room',
                'room_no' => $room->room_no,
                'message' => __('In-house guest but room status is :status.', ['status' => $room->status]),
            ];
        }

        $pendingRestocks = 0;
        if (Schema::hasTable('hotel_room_minibar_restocks')) {
            $pendingRestocks = (int) \Modules\HotelManagement\Entities\HotelRoomMinibarRestock::where('created_by', $creatorId)
                ->where('status', 'pending')
                ->count();
        }

        return [
            'open_folios' => $openFolios->map(fn (HotelFolio $f) => [
                'folio_no' => $f->folio_no,
                'guest' => trim(($f->stay?->reservation?->guest?->first_name ?? '') . ' ' . ($f->stay?->reservation?->guest?->last_name ?? '')),
                'room_no' => $f->stay?->reservation?->room?->room_no,
                'balance' => max(0, round((float) $f->grand_total - (float) $f->paid_total, 2)),
                'url' => route('hotel.folios.show', $f->id),
            ])->all(),
            'open_folios_balance' => round($openBalance, 2),
            'room_anomalies' => $anomalies,
            'pending_minibar_restocks' => $pendingRestocks,
            'departures_today_unpaid' => $openFolios->count(),
        ];
    }
}
