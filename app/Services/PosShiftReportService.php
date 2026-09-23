<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\InvoicePayment;
use App\Models\Pos;
use App\Models\PosCashDrop;
use App\Models\PosCashIn;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PosShiftReportService
{
    public const MAX_DATE_RANGE_DAYS = 90;

    public function normalizeFilters(array $input): array
    {
        $end = $input['end_date'] ?? date('Y-m-d');
        $start = $input['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        if (strtotime($start) > strtotime($end)) {
            [$start, $end] = [$end, $start];
        }
        $days = (int) Carbon::parse($start)->diffInDays(Carbon::parse($end));
        if ($days > self::MAX_DATE_RANGE_DAYS) {
            $start = Carbon::parse($end)->subDays(self::MAX_DATE_RANGE_DAYS)->format('Y-m-d');
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'branch_id' => (int) ($input['branch_id'] ?? 0),
            'warehouse_id' => (int) ($input['warehouse_id'] ?? 0),
            'shift_user_id' => (int) ($input['shift_user_id'] ?? 0),
            'pos_user_id' => (int) ($input['pos_user_id'] ?? 0),
            'product_id' => (int) ($input['product_id'] ?? 0),
            'shift_status' => $input['shift_status'] ?? '',
            'order_type' => $input['order_type'] ?? '',
            'payment_method' => $input['payment_method'] ?? '',
        ];
    }

    public function queryShifts(int $creatorId, array $filters): Builder
    {
        $start = $filters['start_date'] . ' 00:00:00';
        $end = $filters['end_date'] . ' 23:59:59';

        $query = PosShift::query()
            ->where('created_by', $creatorId)
            ->whereBetween('opened_at', [$start, $end])
            ->with(['branch', 'user'])
            ->orderByDesc('opened_at');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['shift_status']) && in_array($filters['shift_status'], [PosShift::STATUS_OPEN, PosShift::STATUS_CLOSED], true)) {
            $query->where('status', $filters['shift_status']);
        }
        if (! empty($filters['shift_user_id'])) {
            $query->where('user_id', $filters['shift_user_id']);
        }
        if (! empty($filters['pos_user_id'])) {
            $query->whereHas('posSales', function ($q) use ($filters) {
                $q->where('pos_user_id', $filters['pos_user_id']);
            });
        }
        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('posSales', function ($q) use ($filters) {
                $q->where('warehouse_id', $filters['warehouse_id']);
            });
        }
        if (! empty($filters['product_id'])) {
            $query->whereHas('posSales.items', function ($q) use ($filters) {
                $q->where('product_id', $filters['product_id']);
            });
        }
        if (! empty($filters['order_type'])) {
            $query->whereHas('posSales', function ($q) use ($filters) {
                $q->where('order_type', $filters['order_type']);
            });
        }
        if (! empty($filters['payment_method'])) {
            $method = $filters['payment_method'];
            $query->whereHas('posSales.posPayments', function ($q) use ($method) {
                if ($method === PosPayment::METHOD_CASH) {
                    $q->where(function ($q2) {
                        $q2->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
                    });
                } else {
                    $q->where('payment_method', $method);
                }
            });
        }

        return $query;
    }

    public function getShiftIdsForFilters(int $creatorId, array $filters): Collection
    {
        return $this->queryShifts($creatorId, $filters)->pluck('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildShiftMetrics(PosShift $shift, int $creatorId): array
    {
        $posIds = Pos::where('shift_id', $shift->id)->pluck('id');
        $sales = Pos::where('shift_id', $shift->id)->with(['items', 'posPayments'])->orderBy('pos_id')->get();
        $paymentsByMethod = PosPayment::whereIn('pos_id', $posIds)
            ->selectRaw('payment_method, sum(amount) as total, count(*) as cnt')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');
        $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $balanceCash = (float) InvoicePayment::where('pos_shift_id', $shift->id)->where('payment_method', 0)->sum('amount');
        $cashTotal += $balanceCash;
        $cardTotal = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', PosPayment::METHOD_CREDIT)->sum('amount');
        $salesTotal = $sales->sum(fn ($p) => $p->getTotal());
        $cashDropsTotal = (float) PosCashDrop::where('shift_id', $shift->id)->sum('amount');
        $cashInsTotal = (float) PosCashIn::where('shift_id', $shift->id)->sum('amount');
        $cashOutExpensesTotal = (float) Expense::where('pos_shift_id', $shift->id)
            ->where('created_by', $creatorId)
            ->cashOutPosted()
            ->sum('amount');
        $expectedCash = (float) ($shift->opening_balance ?? 0) + $cashTotal + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
        $voidedLines = PosProduct::whereIn('pos_id', $posIds)
            ->whereNotNull('voided_at')
            ->with(['product', 'pos', 'voidedByUser'])
            ->orderBy('voided_at')
            ->get();
        $productQtySummary = PosProduct::query()
            ->selectRaw('pos_products.product_id, pos.table_id, pos.pos_user_id, SUM(pos_products.quantity) as qty_total, SUM(pos_products.price * pos_products.quantity) as amount_total')
            ->join('pos', 'pos_products.pos_id', '=', 'pos.id')
            ->whereIn('pos_products.pos_id', $posIds)
            ->whereNull('pos_products.voided_at')
            ->groupBy('pos_products.product_id', 'pos.table_id', 'pos.pos_user_id')
            ->with('product')
            ->orderByRaw('SUM(pos_products.quantity) DESC')
            ->get();

        $tableNames = PosTable::whereIn('id', $productQtySummary->pluck('table_id')->filter()->unique())->pluck('name', 'id');
        $waiterNames = User::whereIn('id', $productQtySummary->pluck('pos_user_id')->filter()->unique())->pluck('name', 'id');
        $productQtySummary->each(function ($row) use ($tableNames, $waiterNames) {
            $row->table_name = $row->table_id ? ($tableNames[$row->table_id] ?? null) : null;
            $row->waiter_name = $row->pos_user_id ? ($waiterNames[$row->pos_user_id] ?? null) : null;
        });

        return [
            'shift' => $shift,
            'sales' => $sales,
            'paymentsByMethod' => $paymentsByMethod,
            'cashTotal' => $cashTotal,
            'cardTotal' => $cardTotal,
            'creditTotal' => $creditTotal,
            'salesTotal' => $salesTotal,
            'cashDropsTotal' => $cashDropsTotal,
            'cashInsTotal' => $cashInsTotal,
            'cashOutExpensesTotal' => $cashOutExpensesTotal,
            'voidedLines' => $voidedLines,
            'balanceCash' => $balanceCash,
            'expectedCash' => $expectedCash,
            'productQtySummary' => $productQtySummary,
            'salesCount' => $sales->count(),
            'difference' => $shift->status === PosShift::STATUS_CLOSED ? (float) ($shift->difference ?? 0) : null,
        ];
    }

    /**
     * @param  Collection<int, int>  $shiftIds
     * @return array<string, mixed>
     */
    public function buildAggregates(Collection $shiftIds, int $creatorId, int $productFilter = 0): array
    {
        if ($shiftIds->isEmpty()) {
            return [
                'paymentsByMethod' => [],
                'products' => [],
                'voidedLines' => collect(),
                'voidedCount' => 0,
                'cashDrops' => collect(),
                'cashIns' => collect(),
                'expenses' => collect(),
            ];
        }

        $posIds = Pos::whereIn('shift_id', $shiftIds)->pluck('id');
        if ($posIds->isEmpty()) {
            return [
                'paymentsByMethod' => [],
                'products' => [],
                'voidedLines' => collect(),
                'voidedCount' => 0,
                'cashDrops' => collect(),
                'cashIns' => collect(),
                'expenses' => collect(),
            ];
        }

        $payments = PosPayment::whereIn('pos_id', $posIds)->get();
        $paymentsByMethod = [];
        foreach ($payments->groupBy(fn ($p) => $p->payment_method ?? PosPayment::METHOD_CASH) as $method => $items) {
            $label = $this->paymentMethodLabel($method);
            if (! isset($paymentsByMethod[$label])) {
                $paymentsByMethod[$label] = ['name' => $label, 'amount' => 0, 'count' => 0];
            }
            $paymentsByMethod[$label]['amount'] += $items->sum('amount');
            $paymentsByMethod[$label]['count'] += $items->count();
        }
        $balanceCash = (float) InvoicePayment::whereIn('pos_shift_id', $shiftIds)->where('payment_method', 0)->sum('amount');
        if ($balanceCash > 0) {
            $label = __('Balance invoice (cash)');
            if (! isset($paymentsByMethod[$label])) {
                $paymentsByMethod[$label] = ['name' => $label, 'amount' => 0, 'count' => 0];
            }
            $paymentsByMethod[$label]['amount'] += $balanceCash;
        }

        $productQuery = PosProduct::query()
            ->whereIn('pos_id', $posIds)
            ->whereNull('voided_at')
            ->selectRaw('product_id, SUM(quantity) as qty_total, SUM(price * quantity - discount) as amount_total')
            ->groupBy('product_id')
            ->with('product')
            ->orderByRaw('SUM(quantity) DESC');
        if ($productFilter > 0) {
            $productQuery->where('product_id', $productFilter);
        }
        $products = $productQuery->get()->map(function ($row) {
            return [
                'name' => $row->product ? $row->product->name : __('Unknown'),
                'quantity' => (float) $row->qty_total,
                'amount' => (float) $row->amount_total,
            ];
        })->values()->all();

        $voidedCount = PosProduct::whereIn('pos_id', $posIds)->whereNotNull('voided_at')->count();
        $voidedLines = PosProduct::whereIn('pos_id', $posIds)
            ->whereNotNull('voided_at')
            ->with(['product', 'pos', 'voidedByUser'])
            ->orderByDesc('voided_at')
            ->limit(200)
            ->get();

        $cashDrops = PosCashDrop::whereIn('shift_id', $shiftIds)->with(['user', 'shift.branch'])->orderByDesc('created_at')->get();
        $cashIns = PosCashIn::whereIn('shift_id', $shiftIds)->with(['user', 'shift.branch'])->orderByDesc('created_at')->get();
        $expenses = Expense::whereIn('pos_shift_id', $shiftIds)
            ->where('created_by', $creatorId)
            ->cashOutPosted()
            ->with(['shift.branch'])
            ->orderByDesc('date')
            ->get();

        return [
            'paymentsByMethod' => array_values($paymentsByMethod),
            'products' => $products,
            'voidedLines' => $voidedLines,
            'voidedCount' => $voidedCount,
            'cashDrops' => $cashDrops,
            'cashIns' => $cashIns,
            'expenses' => $expenses,
        ];
    }

    /**
     * Summary totals without per-shift metric loops (for large result sets).
     *
     * @param  Collection<int, int>  $shiftIds
     * @return array<string, float|int>
     */
    public function buildSummaryFast(Collection $shiftIds, int $creatorId): array
    {
        if ($shiftIds->isEmpty()) {
            return [
                'shift_count' => 0,
                'sales_total' => 0,
                'cash_total' => 0,
                'card_total' => 0,
                'credit_total' => 0,
                'cash_drops_total' => 0,
                'difference_total' => 0,
                'closed_shift_count' => 0,
            ];
        }

        $posIds = Pos::whereIn('shift_id', $shiftIds)->pluck('id');
        $salesTotal = 0;
        if ($posIds->isNotEmpty()) {
            $salesTotal = (float) PosPayment::whereIn('pos_id', $posIds)->sum('amount');
            $salesTotal += (float) InvoicePayment::whereIn('pos_shift_id', $shiftIds)->where('payment_method', 0)->sum('amount');
        }

        $cashTotal = $posIds->isEmpty() ? 0 : (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $cashTotal += (float) InvoicePayment::whereIn('pos_shift_id', $shiftIds)->where('payment_method', 0)->sum('amount');

        $cardTotal = $posIds->isEmpty() ? 0 : (float) PosPayment::whereIn('pos_id', $posIds)
            ->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = $posIds->isEmpty() ? 0 : (float) PosPayment::whereIn('pos_id', $posIds)
            ->where('payment_method', PosPayment::METHOD_CREDIT)->sum('amount');

        return [
            'shift_count' => $shiftIds->count(),
            'sales_total' => $salesTotal,
            'cash_total' => $cashTotal,
            'card_total' => $cardTotal,
            'credit_total' => $creditTotal,
            'cash_drops_total' => (float) PosCashDrop::whereIn('shift_id', $shiftIds)->sum('amount'),
            'difference_total' => (float) PosShift::whereIn('id', $shiftIds)->where('status', PosShift::STATUS_CLOSED)->sum('difference'),
            'closed_shift_count' => PosShift::whereIn('id', $shiftIds)->where('status', PosShift::STATUS_CLOSED)->count(),
        ];
    }

    /**
     * @param  Collection<int, PosShift>  $shifts
     * @return array<string, float|int>
     */
    public function buildSummaryFromMetrics(Collection $shifts, int $creatorId): array
    {
        $summary = [
            'shift_count' => $shifts->count(),
            'sales_total' => 0,
            'cash_total' => 0,
            'card_total' => 0,
            'credit_total' => 0,
            'cash_drops_total' => 0,
            'difference_total' => 0,
            'closed_shift_count' => 0,
        ];

        foreach ($shifts as $shift) {
            $m = $this->buildShiftMetrics($shift, $creatorId);
            $summary['sales_total'] += $m['salesTotal'];
            $summary['cash_total'] += $m['cashTotal'];
            $summary['card_total'] += $m['cardTotal'];
            $summary['credit_total'] += $m['creditTotal'];
            $summary['cash_drops_total'] += $m['cashDropsTotal'];
            if ($shift->status === PosShift::STATUS_CLOSED && $m['difference'] !== null) {
                $summary['difference_total'] += $m['difference'];
                $summary['closed_shift_count']++;
            }
        }

        return $summary;
    }

    public function paymentMethodLabel(?string $method): string
    {
        $method = $method ?? PosPayment::METHOD_CASH;
        return match ($method) {
            PosPayment::METHOD_CASH, '' => __('pos.payment_cash'),
            PosPayment::METHOD_CREDIT => __('pos.payment_credit'),
            'card', 'visa' => __('Card'),
            'mpesa' => 'M-Pesa',
            'bank_transfer' => __('Bank Transfer'),
            'cheque' => __('Cheque'),
            default => ucfirst($method),
        };
    }

    /**
     * @return array<int, string>
     */
    public function paymentMethodOptions(): array
    {
        return [
            '' => __('All'),
            PosPayment::METHOD_CASH => __('pos.payment_cash'),
            'card' => __('Card'),
            'mpesa' => 'M-Pesa',
            'bank_transfer' => __('Bank Transfer'),
            'cheque' => __('Cheque'),
            PosPayment::METHOD_CREDIT => __('pos.payment_credit'),
        ];
    }
}
