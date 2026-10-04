<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('End of Day (EOD) Report') }} — {{ $date }}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: "Segoe UI", Arial, sans-serif; color:#1f2937; }
    .page { padding: 18px 6px; }
    .head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #0f5132; padding-bottom:12px; }
    .brand h1 { font-size:19px; color:#0f5132; }
    .brand .sub { font-size:11px; color:#6b7280; margin-top:3px; line-height:1.55; }
    .docbox { text-align:right; }
    .docbox .type { display:inline-block; background:#0f5132; color:#fff; font-size:11px; font-weight:700; letter-spacing:1.2px; padding:5px 10px; border-radius:4px; }
    .docbox .no { font-size:20px; font-weight:800; margin-top:8px; color:#111827; }
    .docbox .dt { font-size:11px; color:#6b7280; margin-top:2px; }
    .meta { display:flex; gap:10px; margin:16px 0 4px; }
    .meta .m { flex:1; background:#f3f6f4; border:1px solid #e2e8e5; border-radius:8px; padding:9px 12px; }
    .meta .k { font-size:9.5px; letter-spacing:.8px; color:#6b7280; font-weight:700; text-transform:uppercase; }
    .meta .v { font-size:13px; font-weight:700; margin-top:3px; color:#111827; }
    .kpis { display:flex; gap:10px; margin:12px 0 6px; }
    .kpi { flex:1; border:1px solid #e5e7eb; border-top:3px solid #0f5132; border-radius:8px; padding:12px; }
    .kpi .k { font-size:9.5px; letter-spacing:.8px; color:#6b7280; font-weight:700; text-transform:uppercase; }
    .kpi .v { font-size:19px; font-weight:800; margin-top:4px; color:#0f5132; }
    .kpi .s { font-size:10.5px; color:#6b7280; margin-top:2px; }
    h2.sec { font-size:12px; letter-spacing:1px; text-transform:uppercase; color:#0f5132; border-bottom:1.5px solid #d1d5db; padding-bottom:5px; margin:20px 0 9px; font-weight:800; }
    table { width:100%; border-collapse:collapse; font-size:12px; }
    th { background:#eef4f1; color:#374151; text-align:left; padding:7px 9px; font-size:10.5px; letter-spacing:.6px; text-transform:uppercase; border-bottom:2px solid #0f5132; }
    td { padding:6.5px 9px; border-bottom:1px solid #e5e7eb; }
    tbody tr:nth-child(even) td { background:#fafafa; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    tfoot td { font-weight:800; background:#eef4f1 !important; border-top:2px solid #0f5132; color:#0f5132; }
    .cols { display:flex; gap:22px; } .cols > div { flex:1; }
    .sign { display:flex; gap:40px; margin-top:40px; }
    .sign .b { flex:1; border-top:1px solid #9ca3af; padding-top:6px; font-size:10.5px; color:#6b7280; text-align:center; margin-top:40px; }
    .foot { margin-top:24px; border-top:1px solid #e5e7eb; padding-top:8px; display:flex; justify-content:space-between; font-size:9.5px; color:#9ca3af; }
    @page { size: A4; margin: 12mm; }
    @media print { .noprint { display:none !important; } }
</style>
</head>
<body>
@php
    $fmt = fn ($v) => \Auth::user()->priceFormat((float) $v);
    $reportDate = \Carbon\Carbon::parse($date);
    $totalCashReceived = (float) ($cashTotal ?? 0) + (float) ($cashFromBalance ?? 0);
    $itemsQtyTotal = 0.0; foreach (($itemsSold ?? []) as $row) { $itemsQtyTotal += (float) ($row['qty'] ?? 0); }
@endphp
<div class="page">
    <div class="head">
        <div class="brand">
            <h1>{{ $companyName }}</h1>
            <div class="sub">
                @if(!empty($vatNumber)){{ __('VAT NR') }}: {{ $vatNumber }}<br>@endif
                @if(!empty($companyAddress)){{ $companyAddress }}<br>@endif
                @if(!empty($companyTelephone))Tel: {{ $companyTelephone }} @endif @if(!empty($companyEmail)) · {{ $companyEmail }} @endif
            </div>
        </div>
        <div class="docbox">
            <span class="type">{{ __('END OF DAY REPORT') }}</span>
            <div class="no">EOD · {{ $reportDate->format('d/m/Y') }}</div>
            <div class="dt">{{ __('Printed') }}: {{ date('d/m/Y H:i:s') }}</div>
        </div>
    </div>

    <div class="meta">
        <div class="m"><div class="k">{{ __('Report Date') }}</div><div class="v">{{ $reportDate->format('l, d M Y') }}</div></div>
        <div class="m"><div class="k">{{ __('Cash Register') }}</div><div class="v">1</div></div>
        <div class="m"><div class="k">{{ __('Tickets') }}</div><div class="v">{{ sprintf('%09d', $ticketFrom ?? 0) }} → {{ sprintf('%09d', $ticketTo ?? 0) }}</div></div>
        <div class="m"><div class="k">{{ __('Salesperson') }}</div><div class="v">{{ $salespersonName }}</div></div>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="k">{{ __('TOTAL SALES') }}</div><div class="v">{{ $fmt($totalSales) }}</div><div class="s">{{ $receiptsCount }} {{ __('receipts issued') }}</div></div>
        <div class="kpi"><div class="k">{{ __('Cash Received') }}</div><div class="v">{{ $fmt($totalCashReceived) }}</div><div class="s">{{ __('Total cash received') }}</div></div>
        <div class="kpi"><div class="k">{{ __('Customers') }}</div><div class="v">{{ $customersCount }}</div><div class="s">{{ __('Average X Customer') }} {{ $fmt($avgPerCustomer) }}</div></div>
        <div class="kpi"><div class="k">{{ __('Average X Receipt') }}</div><div class="v">{{ $fmt($avgPerReceipt) }}</div><div class="s">{{ __('Receipts Issued') }}: {{ $receiptsCount }}</div></div>
    </div>

    <h2 class="sec">{{ __('SALES BY PAYMENT METHOD') }}</h2>
    <table>
        <thead><tr><th>{{ __('PAYMENT METHOD') }}</th><th class="num">{{ __('Receipts') }}</th><th class="num">{{ __('AMOUNT') }}</th><th class="num">{{ __('Share') }}</th></tr></thead>
        <tbody>
        @foreach($paymentMethodRows as $r)
            <tr>
                <td>{{ $r['name'] }}</td>
                <td class="num">{{ $r['count'] }}</td>
                <td class="num">{{ $fmt($r['amount']) }}</td>
                <td class="num">{{ $totalSales > 0 ? number_format($r['amount'] / $totalSales * 100, 1) : '0.0' }}%</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total Sales') }}</td><td class="num">{{ $receiptsCount }}</td><td class="num">{{ $fmt($totalSales) }}</td><td class="num">100%</td></tr></tfoot>
    </table>

    <div class="cols">
        <div>
            <h2 class="sec">{{ __('CASH RECEIVED') }}</h2>
            <table>
                <tbody>
                    <tr><td>{{ __('Cash from sales') }}</td><td class="num">{{ $fmt($cashTotal ?? 0) }}</td></tr>
                    @if(isset($cashFromBalance) && $cashFromBalance > 0)
                        <tr><td>{{ __('Cash from balance payments') }}</td><td class="num">{{ $fmt($cashFromBalance) }}</td></tr>
                    @endif
                </tbody>
                <tfoot><tr><td>{{ __('Total cash received') }}</td><td class="num">{{ $fmt($totalCashReceived) }}</td></tr></tfoot>
            </table>

            @if(count($taxSummary ?? []) > 0)
            <h2 class="sec">{{ __('TAXES') }}</h2>
            <table>
                <thead><tr><th>{{ __('TAX') }}</th><th class="num">{{ __('BASE') }}</th><th class="num">{{ __('QUOTA') }}</th><th class="num">{{ __('TOTAL') }}</th></tr></thead>
                <tbody>
                @foreach($taxSummary as $taxName => $r)
                    <tr><td>{{ $taxName }}</td><td class="num">{{ $fmt($r['base']) }}</td><td class="num">{{ $fmt($r['quota']) }}</td><td class="num">{{ $fmt($r['total']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        <div>
            <h2 class="sec">{{ __('SALES PER EMPLOYEE') }}</h2>
            <table>
                <thead><tr><th>{{ __('Employee') }}</th><th class="num">{{ __('Receipts') }}</th><th class="num">{{ __('AMOUNT') }}</th></tr></thead>
                <tbody>
                @foreach($salesPerEmployee as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['count'] }}</td><td class="num">{{ $fmt($r['amount']) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot><tr><td>{{ __('Total') }}</td><td class="num">{{ $receiptsCount }}</td><td class="num">{{ $fmt($totalSales) }}</td></tr></tfoot>
            </table>

            @if(!empty($accountsReceivableRows) && count($accountsReceivableRows) > 0)
            <h2 class="sec">{{ __('pos.accounts_receivable') }}</h2>
            <table>
                <thead><tr><th>{{ __('Invoice') }}</th><th class="num">{{ __('Total') }}</th><th class="num">{{ __('Outstanding') }}</th></tr></thead>
                <tbody>
                @foreach($accountsReceivableRows as $ar)
                    <tr><td>{{ $ar['invoice_id'] }}</td><td class="num">{{ $fmt($ar['total']) }}</td><td class="num">{{ $fmt($ar['outstanding']) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot><tr><td>{{ __('pos.total_outstanding') }}</td><td class="num"></td><td class="num">{{ $fmt($accountsReceivableTotal) }}</td></tr></tfoot>
            </table>
            @endif
        </div>
    </div>

    @if(count($categorySummary ?? []) > 0)
    <h2 class="sec">{{ __('SALES BY SECTION') }}</h2>
    <table>
        <thead><tr><th>{{ __('SECTION') }}</th><th class="num">{{ __('Items') }}</th><th class="num">{{ __('AMOUNT') }}</th><th class="num">{{ __('Share') }}</th></tr></thead>
        <tbody>
        @php $totalCat = array_sum(array_map(fn ($c) => (float) $c['amount'], $categorySummary)); @endphp
        @foreach($categorySummary as $catName => $row)
            <tr>
                <td>{{ $catName }}</td>
                <td class="num">{{ number_format((float) $row['qty'], 0) }}</td>
                <td class="num">{{ $fmt($row['amount']) }}</td>
                <td class="num">{{ $totalCat > 0 ? number_format($row['amount'] / $totalCat * 100, 1) : '0.0' }}%</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="num">{{ number_format($itemsQtyTotal, 0) }}</td><td class="num">{{ $fmt($totalCat) }}</td><td class="num">100%</td></tr></tfoot>
    </table>
    @endif

    @if(count($itemsSold ?? []) > 0)
    <h2 class="sec">{{ __('SUMMARY OF ITEMS SOLD') }}</h2>
    <table>
        <thead><tr><th>{{ __('ITEM') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('AMOUNT') }}</th></tr></thead>
        <tbody>
        @foreach($itemsSold as $itemName => $row)
            <tr><td>{{ $itemName }}</td><td class="num">{{ number_format((float) $row['qty'], 2) }}</td><td class="num">{{ $fmt($row['amount']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="num">{{ number_format($itemsQtyTotal, 2) }}</td><td class="num">{{ $fmt($totalSales) }}</td></tr></tfoot>
    </table>
    @endif

    <div class="sign">
        <div class="b">{{ __('Cashier') }} — {{ $salespersonName }}</div>
        <div class="b">{{ __('Manager Signature') }}</div>
        <div class="b">{{ __('Date / Time') }}</div>
    </div>

    <div class="foot">
        <span>{{ $companyName }} · {{ __('End of Day (EOD) Report') }} · {{ $reportDate->format('d/m/Y') }}</span>
        <span>{{ __('Generated by ERP SaaS POS') }}</span>
    </div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
