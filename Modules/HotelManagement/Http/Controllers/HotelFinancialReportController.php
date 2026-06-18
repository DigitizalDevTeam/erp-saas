<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Http\Controllers\Concerns\RendersHotelReports;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelFinancialReportController extends Controller
{
    use RendersHotelReports;

    protected function authorizeReport(): void
    {
        HotelAuthorization::abortUnless(HotelAuthorization::viewReports(Auth::user()));
    }

    /**
     * Map a folio line to a revenue bucket for category breakdowns.
     */
    private function revenueBucket(string $lineType, ?string $channel): string
    {
        $channel = strtolower((string) $channel);

        return match ($lineType) {
            'room_charge' => 'room',
            'pos_charge' => 'restaurant',
            'minibar_charge' => 'minibar',
            'laundry_charge' => 'laundry',
            'addon_charge' => 'addons',
            default => match ($channel) {
                'room' => 'room',
                'restaurant' => 'restaurant',
                'laundry' => 'laundry',
                default => 'other',
            },
        };
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function folioLines(int $creatorId, Carbon $from, Carbon $to)
    {
        $channelSelect = Schema::hasColumn('hotel_folio_lines', 'revenue_channel')
            ? 'l.revenue_channel'
            : 'NULL as revenue_channel';

        return DB::table('hotel_folio_lines as l')
            ->join('hotel_folios as f', 'f.id', '=', 'l.hotel_folio_id')
            ->where('f.created_by', $creatorId)
            ->whereBetween('l.created_at', [$from, $to])
            ->selectRaw('DATE(l.created_at) as d, l.line_type, ' . $channelSelect . ', l.quantity, l.unit_price, l.tax_amount, l.discount_amount, l.line_total')
            ->get();
    }

    /**
     * Build per-period revenue rows keyed by date or month.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    private function revenueByPeriod(int $creatorId, Carbon $from, Carbon $to, string $granularity): array
    {
        $lines = $this->folioLines($creatorId, $from, $to);
        $buckets = ['room', 'restaurant', 'minibar', 'laundry', 'addons', 'other'];
        $groups = [];

        foreach ($lines as $line) {
            $key = $granularity === 'month' ? substr($line->d, 0, 7) : $line->d;
            if (! isset($groups[$key])) {
                $groups[$key] = array_fill_keys($buckets, 0.0) + ['tax' => 0.0, 'discount' => 0.0, 'total' => 0.0];
            }
            $bucket = $this->revenueBucket($line->line_type, $line->revenue_channel);
            $net = (float) $line->quantity * (float) $line->unit_price;
            $groups[$key][$bucket] += $net;
            $groups[$key]['tax'] += (float) $line->tax_amount;
            $groups[$key]['discount'] += (float) $line->discount_amount;
            $groups[$key]['total'] += (float) $line->line_total;
        }

        ksort($groups);
        $rows = [];
        $totals = array_fill_keys($buckets, 0.0) + ['tax' => 0.0, 'discount' => 0.0, 'total' => 0.0];
        foreach ($groups as $key => $vals) {
            $rows[] = array_merge(['period' => $key], $vals);
            foreach ($vals as $k => $v) {
                $totals[$k] += $v;
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function dailyRevenue(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $data = $this->revenueByPeriod(Auth::user()->creatorId(), $from, $to, 'day');

        return $this->renderReport($request, [
            'key' => 'daily-revenue',
            'title' => __('Daily Revenue Report'),
            'icon' => 'ti ti-calendar-stats',
            'subtitle' => __('Revenue by category for each day in the period.'),
            'from' => $from,
            'to' => $to,
            'orientation' => 'landscape',
            'summary' => [
                ['label' => __('Total revenue'), 'value' => Auth::user()->priceFormat($data['totals']['total']), 'tone' => 'success'],
                ['label' => __('Room revenue'), 'value' => Auth::user()->priceFormat($data['totals']['room']), 'tone' => 'primary'],
                ['label' => __('Tax collected'), 'value' => Auth::user()->priceFormat($data['totals']['tax']), 'tone' => 'info'],
                ['label' => __('Discounts'), 'value' => Auth::user()->priceFormat($data['totals']['discount']), 'tone' => 'danger'],
            ],
            'columns' => $this->revenueColumns(__('Date')),
            'rows' => $data['rows'],
            'totalsRow' => array_merge(['period' => __('Total')], $data['totals']),
        ]);
    }

    public function monthlyRevenue(Request $request)
    {
        $this->authorizeReport();
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfMonth() : Carbon::today()->endOfMonth();
        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfMonth() : $to->copy()->subMonths(11)->startOfMonth();
        $data = $this->revenueByPeriod(Auth::user()->creatorId(), $from, $to, 'month');

        return $this->renderReport($request, [
            'key' => 'monthly-revenue',
            'title' => __('Monthly Revenue Report'),
            'icon' => 'ti ti-calendar-month',
            'subtitle' => __('Revenue by category for each month in the period.'),
            'from' => $from,
            'to' => $to,
            'orientation' => 'landscape',
            'summary' => [
                ['label' => __('Total revenue'), 'value' => Auth::user()->priceFormat($data['totals']['total']), 'tone' => 'success'],
                ['label' => __('Room revenue'), 'value' => Auth::user()->priceFormat($data['totals']['room']), 'tone' => 'primary'],
                ['label' => __('Restaurant / POS'), 'value' => Auth::user()->priceFormat($data['totals']['restaurant']), 'tone' => 'warning'],
                ['label' => __('Tax collected'), 'value' => Auth::user()->priceFormat($data['totals']['tax']), 'tone' => 'info'],
            ],
            'columns' => $this->revenueColumns(__('Month')),
            'rows' => $data['rows'],
            'totalsRow' => array_merge(['period' => __('Total')], $data['totals']),
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function revenueColumns(string $periodLabel): array
    {
        return [
            ['label' => $periodLabel, 'key' => 'period', 'type' => 'text'],
            ['label' => __('Room'), 'key' => 'room', 'type' => 'money'],
            ['label' => __('Restaurant'), 'key' => 'restaurant', 'type' => 'money'],
            ['label' => __('Minibar'), 'key' => 'minibar', 'type' => 'money'],
            ['label' => __('Laundry'), 'key' => 'laundry', 'type' => 'money'],
            ['label' => __('Addons'), 'key' => 'addons', 'type' => 'money'],
            ['label' => __('Other'), 'key' => 'other', 'type' => 'money'],
            ['label' => __('Tax'), 'key' => 'tax', 'type' => 'money'],
            ['label' => __('Discount'), 'key' => 'discount', 'type' => 'money'],
            ['label' => __('Total'), 'key' => 'total', 'type' => 'money'],
        ];
    }

    public function paymentCollection(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $creatorId = Auth::user()->creatorId();

        $payments = DB::table('hotel_folio_payments as p')
            ->join('hotel_folios as f', 'f.id', '=', 'p.hotel_folio_id')
            ->where('f.created_by', $creatorId)
            ->whereBetween('p.created_at', [$from, $to])
            ->selectRaw('DATE(p.created_at) as d, p.method, COUNT(*) as txns, SUM(p.amount) as amount')
            ->groupBy('d', 'p.method')
            ->orderBy('d')
            ->get();

        $rows = $payments->map(fn ($p) => [
            'date' => $p->d,
            'method' => $this->methodLabel($p->method),
            'txns' => (int) $p->txns,
            'amount' => (float) $p->amount,
        ])->all();

        $byMethod = $payments->groupBy('method')->map(fn ($g) => (float) $g->sum('amount'));
        $total = (float) $payments->sum('amount');

        $summary = [['label' => __('Total collected'), 'value' => Auth::user()->priceFormat($total), 'tone' => 'success']];
        foreach ($byMethod->sortDesc()->take(3) as $method => $amount) {
            $summary[] = ['label' => $this->methodLabel($method), 'value' => Auth::user()->priceFormat($amount), 'tone' => 'primary'];
        }

        return $this->renderReport($request, [
            'key' => 'payment-collection',
            'title' => __('Payment Collection Report'),
            'icon' => 'ti ti-cash',
            'subtitle' => __('Payments received by day and method.'),
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'columns' => [
                ['label' => __('Date'), 'key' => 'date', 'type' => 'text'],
                ['label' => __('Method'), 'key' => 'method', 'type' => 'text'],
                ['label' => __('Transactions'), 'key' => 'txns', 'type' => 'number'],
                ['label' => __('Amount'), 'key' => 'amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totalsRow' => ['date' => __('Total'), 'txns' => (int) $payments->sum('txns'), 'amount' => $total],
        ]);
    }

    public function outstanding(Request $request)
    {
        $this->authorizeReport();
        $creatorId = Auth::user()->creatorId();

        $folios = DB::table('hotel_folios as f')
            ->leftJoin('hotel_stays as s', 's.id', '=', 'f.hotel_stay_id')
            ->leftJoin('hotel_reservations as r', 'r.id', '=', 's.hotel_reservation_id')
            ->leftJoin('hotel_guests as g', 'g.id', '=', 'r.hotel_guest_id')
            ->leftJoin('hotel_rooms as rm', 'rm.id', '=', 'r.hotel_room_id')
            ->where('f.created_by', $creatorId)
            ->whereColumn('f.paid_total', '<', 'f.grand_total')
            ->where('f.grand_total', '>', 0)
            ->selectRaw("f.folio_no, f.grand_total, f.paid_total, f.status, f.created_at,
                TRIM(CONCAT(COALESCE(g.first_name,''), ' ', COALESCE(g.last_name,''))) as guest,
                rm.room_no as room")
            ->orderByDesc('f.created_at')
            ->get();

        $rows = $folios->map(fn ($f) => [
            'folio' => $f->folio_no,
            'guest' => trim((string) $f->guest) ?: '—',
            'room' => $f->room ?: '—',
            'grand' => (float) $f->grand_total,
            'paid' => (float) $f->paid_total,
            'balance' => (float) $f->grand_total - (float) $f->paid_total,
            'age' => Carbon::parse($f->created_at)->diffInDays(now()),
            'status' => __(ucfirst((string) $f->status)),
        ])->all();

        $totalBalance = (float) $folios->sum(fn ($f) => (float) $f->grand_total - (float) $f->paid_total);

        return $this->renderReport($request, [
            'key' => 'outstanding-balance',
            'title' => __('Outstanding Balance Report'),
            'icon' => 'ti ti-alert-triangle',
            'subtitle' => __('Open folios with an unpaid balance.'),
            'showDateFilter' => false,
            'summary' => [
                ['label' => __('Total outstanding'), 'value' => Auth::user()->priceFormat($totalBalance), 'tone' => 'danger'],
                ['label' => __('Open folios'), 'value' => $folios->count(), 'tone' => 'warning'],
            ],
            'columns' => [
                ['label' => __('Folio'), 'key' => 'folio', 'type' => 'text'],
                ['label' => __('Guest'), 'key' => 'guest', 'type' => 'text'],
                ['label' => __('Room'), 'key' => 'room', 'type' => 'text'],
                ['label' => __('Grand total'), 'key' => 'grand', 'type' => 'money'],
                ['label' => __('Paid'), 'key' => 'paid', 'type' => 'money'],
                ['label' => __('Balance'), 'key' => 'balance', 'type' => 'money'],
                ['label' => __('Age (days)'), 'key' => 'age', 'type' => 'number'],
                ['label' => __('Status'), 'key' => 'status', 'type' => 'text'],
            ],
            'rows' => $rows,
            'totalsRow' => ['folio' => __('Total'), 'balance' => $totalBalance],
            'emptyText' => __('No outstanding balances. All folios are settled.'),
        ]);
    }

    public function cashierClosing(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request, 0);
        $creatorId = Auth::user()->creatorId();

        $payments = DB::table('hotel_folio_payments as p')
            ->join('hotel_folios as f', 'f.id', '=', 'p.hotel_folio_id')
            ->where('f.created_by', $creatorId)
            ->whereBetween('p.created_at', [$from, $to])
            ->selectRaw('p.created_by as cashier_id, p.method, COUNT(*) as txns, SUM(p.amount) as amount')
            ->groupBy('p.created_by', 'p.method')
            ->get();

        $userNames = User::whereIn('id', $payments->pluck('cashier_id')->unique()->filter()->all())
            ->pluck('name', 'id');

        $rows = $payments->map(fn ($p) => [
            'cashier' => $userNames[$p->cashier_id] ?? ('#' . $p->cashier_id),
            'method' => $this->methodLabel($p->method),
            'txns' => (int) $p->txns,
            'amount' => (float) $p->amount,
        ])->sortBy([['cashier', 'asc'], ['method', 'asc']])->values()->all();

        $total = (float) $payments->sum('amount');

        return $this->renderReport($request, [
            'key' => 'cashier-closing',
            'title' => __('Cashier Closing Report'),
            'icon' => 'ti ti-user-dollar',
            'subtitle' => __('Collections grouped by cashier and payment method for the period.'),
            'from' => $from,
            'to' => $to,
            'summary' => [
                ['label' => __('Total collected'), 'value' => Auth::user()->priceFormat($total), 'tone' => 'success'],
                ['label' => __('Cashiers'), 'value' => $payments->pluck('cashier_id')->unique()->count(), 'tone' => 'primary'],
                ['label' => __('Transactions'), 'value' => (int) $payments->sum('txns'), 'tone' => 'info'],
            ],
            'columns' => [
                ['label' => __('Cashier'), 'key' => 'cashier', 'type' => 'text'],
                ['label' => __('Method'), 'key' => 'method', 'type' => 'text'],
                ['label' => __('Transactions'), 'key' => 'txns', 'type' => 'number'],
                ['label' => __('Amount'), 'key' => 'amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totalsRow' => ['cashier' => __('Total'), 'txns' => (int) $payments->sum('txns'), 'amount' => $total],
        ]);
    }

    public function occupancyRevenue(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $creatorId = Auth::user()->creatorId();
        $roomsTotal = max(1, HotelRoom::where('created_by', $creatorId)->count());

        $roomRevenue = DB::table('hotel_folio_lines as l')
            ->join('hotel_folios as f', 'f.id', '=', 'l.hotel_folio_id')
            ->where('f.created_by', $creatorId)
            ->where('l.line_type', 'room_charge')
            ->whereBetween('l.created_at', [$from, $to])
            ->selectRaw('DATE(l.created_at) as d, SUM(l.line_total) as revenue')
            ->groupBy('d')
            ->pluck('revenue', 'd');

        $period = CarbonPeriod::create($from->toDateString(), $to->toDateString());
        $rows = [];
        $totRevenue = 0.0;
        $totSold = 0;
        foreach ($period as $day) {
            $d = $day->toDateString();
            $sold = HotelReservation::where('created_by', $creatorId)
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->whereDate('check_in_date', '<=', $d)
                ->whereDate('check_out_date', '>', $d)
                ->count();
            $revenue = (float) ($roomRevenue[$d] ?? 0);
            $occupancy = round(min(100, ($sold / $roomsTotal) * 100), 1);
            $adr = $sold > 0 ? round($revenue / $sold, 2) : 0;
            $revpar = round($revenue / $roomsTotal, 2);
            $rows[] = [
                'date' => $d,
                'available' => $roomsTotal,
                'sold' => $sold,
                'occupancy' => $occupancy,
                'revenue' => $revenue,
                'adr' => $adr,
                'revpar' => $revpar,
            ];
            $totRevenue += $revenue;
            $totSold += $sold;
        }

        $avgAdr = $totSold > 0 ? round($totRevenue / $totSold, 2) : 0;

        return $this->renderReport($request, [
            'key' => 'occupancy-revenue',
            'title' => __('Occupancy Revenue Report'),
            'icon' => 'ti ti-bed',
            'subtitle' => __('Rooms sold, occupancy %, room revenue, ADR and RevPAR per day.'),
            'from' => $from,
            'to' => $to,
            'orientation' => 'landscape',
            'summary' => [
                ['label' => __('Room revenue'), 'value' => Auth::user()->priceFormat($totRevenue), 'tone' => 'success'],
                ['label' => __('Rooms sold'), 'value' => $totSold, 'tone' => 'primary'],
                ['label' => __('Avg ADR'), 'value' => Auth::user()->priceFormat($avgAdr), 'tone' => 'info'],
                ['label' => __('Total rooms'), 'value' => $roomsTotal, 'tone' => 'secondary'],
            ],
            'columns' => [
                ['label' => __('Date'), 'key' => 'date', 'type' => 'text'],
                ['label' => __('Rooms available'), 'key' => 'available', 'type' => 'number'],
                ['label' => __('Rooms sold'), 'key' => 'sold', 'type' => 'number'],
                ['label' => __('Occupancy'), 'key' => 'occupancy', 'type' => 'percent'],
                ['label' => __('Room revenue'), 'key' => 'revenue', 'type' => 'money'],
                ['label' => __('ADR'), 'key' => 'adr', 'type' => 'money'],
                ['label' => __('RevPAR'), 'key' => 'revpar', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totalsRow' => ['date' => __('Total'), 'sold' => $totSold, 'revenue' => $totRevenue],
        ]);
    }

    private function methodLabel(?string $method): string
    {
        $method = trim((string) $method);
        if ($method === '') {
            return __('Unspecified');
        }

        return __(ucfirst(str_replace('_', ' ', $method)));
    }
}
