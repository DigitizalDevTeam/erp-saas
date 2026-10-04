<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Pos;
use App\Models\PosDayClosed;
use App\Models\PosPayment;
use App\Models\PosShift;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Utility;
use Barryvdh\DomPDF\Facade\Pdf;

class PosEodReportService
{
    /**
     * Build EOD report data for a company and date.
     *
     * @return array<string, mixed>
     */
    public static function build(int $creatorId, string $date, ?string $salespersonFallback = null): array
    {
        $settings = Utility::settingsById($creatorId);
        $companyName = $settings['company_name'] ?? config('app.name');
        $vatNumber = $settings['vat_number'] ?? '';
        $companyAddress = trim(implode(', ', array_filter([
            $settings['company_address'] ?? '',
            $settings['company_city'] ?? '',
            $settings['company_state'] ?? '',
            $settings['company_country'] ?? '',
            $settings['company_zipcode'] ?? '',
        ])));

        $poses = Pos::where('created_by', $creatorId)
            ->whereDate('pos_date', $date)
            ->with(['items', 'shift.user', 'posPayments'])
            ->orderBy('pos_id')
            ->get();

        $totalSales = $poses->sum(fn ($p) => $p->getTotal());
        $receiptsCount = $poses->count();
        $ticketFrom = $poses->min('pos_id');
        $ticketTo = $poses->max('pos_id');
        $salespersonName = $poses->first() && $poses->first()->shift && $poses->first()->shift->user
            ? $poses->first()->shift->user->name
            : ($salespersonFallback ?? __('All'));

        $posIds = $poses->pluck('id');
        $paymentMethodRows = [];
        if ($posIds->isNotEmpty()) {
            $payments = PosPayment::whereIn('pos_id', $posIds)->get();
            $byMethod = $payments->groupBy(function ($p) {
                $m = $p->payment_method ?? 'cash';

                return $m === 'cash' ? __('Cash') : ($m === 'card' ? __('Card') : ($m === 'credit' ? __('Credit') : ucfirst($m)));
            });
            foreach ($byMethod as $methodName => $items) {
                $paymentMethodRows[] = [
                    'name' => $methodName,
                    'amount' => $items->sum('amount'),
                    'count' => $items->count(),
                ];
            }
        }

        $shiftIdsForDay = PosShift::where('created_by', $creatorId)->whereDate('opened_at', $date)->pluck('id');
        $cashFromBalance = $shiftIdsForDay->isNotEmpty()
            ? (float) InvoicePayment::whereIn('pos_shift_id', $shiftIdsForDay)->where('payment_method', 0)->sum('amount')
            : 0;
        if ($cashFromBalance > 0) {
            $cashRowKey = null;
            foreach ($paymentMethodRows as $i => $r) {
                if ($r['name'] === __('Cash')) {
                    $cashRowKey = $i;
                    break;
                }
            }
            if ($cashRowKey !== null) {
                $paymentMethodRows[$cashRowKey]['amount'] += $cashFromBalance;
            } else {
                array_unshift($paymentMethodRows, ['name' => __('Cash'), 'amount' => $cashFromBalance, 'count' => 0]);
            }
        }
        if (empty($paymentMethodRows)) {
            $paymentMethodRows[] = ['name' => 'POS', 'amount' => $totalSales, 'count' => $receiptsCount];
        }

        $statementRows = [];
        foreach ($poses as $pos) {
            $employeeName = $pos->shift && $pos->shift->user ? $pos->shift->user->name : '-';
            $employeeId = $pos->shift && $pos->shift->user ? $pos->shift->user->id : '';
            $statementRows[] = [
                'ticket' => $pos->pos_id,
                'count' => $pos->items->sum('quantity'),
                'time' => $pos->created_at ? $pos->created_at->format('H:i') : '-',
                'employee' => $employeeId ? $employeeId . ' ' . $employeeName : $employeeName,
                'amount' => $pos->getTotal(),
            ];
        }

        $salesPerEmployee = [];
        foreach ($poses as $pos) {
            $key = $pos->shift && $pos->shift->user
                ? ($pos->shift->user->id . '|' . $pos->shift->user->name)
                : '0|' . __('Without shift');
            if (! isset($salesPerEmployee[$key])) {
                $salesPerEmployee[$key] = ['name' => explode('|', $key)[1], 'amount' => 0, 'count' => 0];
            }
            $salesPerEmployee[$key]['amount'] += $pos->getTotal();
            $salesPerEmployee[$key]['count']++;
        }

        $categorySummary = [];
        $itemsSold = [];
        $taxSummary = [];
        $getTaxData = Utility::getTaxData();
        foreach ($poses as $pos) {
            foreach ($pos->items as $item) {
                $product = ProductService::find($item->product_id);
                $price = $item->price ?? 0;
                $qty = $item->quantity;
                $amount = $price * $qty;
                $catName = __('Uncategorized');
                if ($product) {
                    $catId = $product->category_id ?? 0;
                    $catName = $catId ? (ProductServiceCategory::find($catId)?->name ?? __('Uncategorized')) : __('Uncategorized');
                }
                if (! isset($categorySummary[$catName])) {
                    $categorySummary[$catName] = ['qty' => 0, 'amount' => 0];
                }
                $categorySummary[$catName]['qty'] += $qty;
                $categorySummary[$catName]['amount'] += $amount;

                $pname = $product ? $product->name : __('Item');
                if (! isset($itemsSold[$pname])) {
                    $itemsSold[$pname] = ['qty' => 0, 'amount' => 0];
                }
                $itemsSold[$pname]['qty'] += $qty;
                $itemsSold[$pname]['amount'] += $amount;

                if (! empty($item->tax) && is_array($getTaxData)) {
                    foreach (explode(',', $item->tax) as $tid) {
                        $tid = trim($tid);
                        if ($tid === '') {
                            continue;
                        }
                        $rate = $getTaxData[$tid]['rate'] ?? 0;
                        $taxAmount = ($rate / 100) * ($price * $qty);
                        $base = $price * $qty;
                        $label = ($getTaxData[$tid]['name'] ?? 'Tax') . ' ' . ($rate ?: 0) . '%';
                        if (! isset($taxSummary[$label])) {
                            $taxSummary[$label] = ['base' => 0, 'quota' => 0, 'total' => 0, 'rate' => $rate];
                        }
                        $taxSummary[$label]['base'] += $base;
                        $taxSummary[$label]['quota'] += $taxAmount;
                        $taxSummary[$label]['total'] += $base + $taxAmount;
                    }
                }
            }
        }

        $customersCount = $poses->pluck('customer_id')->unique()->filter()->count();
        if ($customersCount === 0 && $receiptsCount > 0) {
            $customersCount = $receiptsCount;
        }
        $avgPerReceipt = $receiptsCount > 0 ? $totalSales / $receiptsCount : 0;
        $avgPerCustomer = $customersCount > 0 ? $totalSales / $customersCount : 0;

        $cashTotal = 0;
        $cardTotal = 0;
        $creditTotal = 0;
        foreach ($poses as $p) {
            foreach ($p->posPayments ?? [] as $pm) {
                $m = strtolower($pm->payment_method ?? 'cash');
                if ($m === 'cash') {
                    $cashTotal += $pm->amount;
                } elseif ($m === 'card' || in_array($m, ['mpesa', 'bank_transfer', 'visa', 'cheque'])) {
                    $cardTotal += $pm->amount;
                } elseif ($m === 'credit') {
                    $creditTotal += $pm->amount;
                } else {
                    $cashTotal += $pm->amount;
                }
            }
        }

        $accountsReceivableRows = [];
        $accountsReceivableTotal = 0;
        if ($posIds->isNotEmpty()) {
            $creditInvoices = Invoice::whereIn('pos_id', $posIds)
                ->whereIn('status', [2, 3])
                ->get();
            foreach ($creditInvoices as $inv) {
                $paid = $inv->getTotal() - $inv->getDue();
                $outstanding = $inv->getDue();
                $accountsReceivableRows[] = [
                    'invoice_id' => $inv->invoice_id,
                    'total' => $inv->getTotal(),
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                ];
                $accountsReceivableTotal += $outstanding;
            }
        }

        $receivedCreditPayments = [];
        if ($shiftIdsForDay->isNotEmpty()) {
            $invoicePayments = InvoicePayment::whereIn('pos_shift_id', $shiftIdsForDay)
                ->whereDate('date', $date)
                ->with(['invoice'])
                ->get();
            foreach ($invoicePayments as $ip) {
                $methodLabel = match ((int) ($ip->payment_method ?? 0)) {
                    0 => __('Cash'),
                    1 => __('Card'),
                    2 => __('Cheque'),
                    3 => __('Bank Transfer'),
                    default => __('Other'),
                };
                $receivedCreditPayments[] = [
                    'invoice_id' => $ip->invoice?->invoice_id ?? '',
                    'amount' => $ip->amount,
                    'payment_method' => $methodLabel,
                ];
            }
        }

        $openShiftsForDate = PosShift::where('created_by', $creatorId)
            ->where('status', PosShift::STATUS_OPEN)
            ->whereDate('opened_at', $date)
            ->with(['branch', 'user'])
            ->get();
        $isDayClosed = PosDayClosed::where('closed_date', $date)->where('created_by', $creatorId)->exists();

        return [
            'date' => $date,
            'companyTelephone' => $settings['company_telephone'] ?? '',
            'companyEmail' => $settings['company_email'] ?? '',
            'companyName' => $companyName,
            'vatNumber' => $vatNumber,
            'companyAddress' => $companyAddress,
            'totalSales' => $totalSales,
            'ticketFrom' => $ticketFrom,
            'ticketTo' => $ticketTo,
            'receiptsCount' => $receiptsCount,
            'salespersonName' => $salespersonName,
            'paymentMethodRows' => $paymentMethodRows,
            'cashFromBalance' => $cashFromBalance,
            'cashTotal' => $cashTotal,
            'cardTotal' => $cardTotal,
            'creditTotal' => $creditTotal,
            'statementRows' => $statementRows,
            'salesPerEmployee' => array_values($salesPerEmployee),
            'customersCount' => $customersCount,
            'avgPerReceipt' => $avgPerReceipt,
            'avgPerCustomer' => $avgPerCustomer,
            'categorySummary' => $categorySummary,
            'taxSummary' => $taxSummary,
            'itemsSold' => $itemsSold,
            'accountsReceivableRows' => $accountsReceivableRows,
            'accountsReceivableTotal' => $accountsReceivableTotal,
            'receivedCreditPayments' => $receivedCreditPayments,
            'openShiftsForDate' => $openShiftsForDate,
            'isDayClosed' => $isDayClosed,
            'settings' => $settings,
            'poses' => $poses,
        ];
    }

    /**
     * Render EOD report as PDF binary string.
     */
    public static function renderPdf(int $creatorId, string $date): string
    {
        $data = self::build($creatorId, $date);

        if (! class_exists(Pdf::class)) {
            throw new \RuntimeException('PDF generation is not available.');
        }

        return Pdf::loadView('report.pos_eod_print_a4', $data)
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
