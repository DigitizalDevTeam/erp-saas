<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('End of Day (EOD) Report') }} — {{ $date }}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:"Courier New", monospace; color:#000; }
    .slip { width:72mm; margin:0 auto; padding:8px 2mm; font-size:11.5px; line-height:1.45; }
    .c { text-align:center; } .b { font-weight:700; } .big { font-size:14px; }
    .line { border-top:1px dashed #000; margin:7px 0; }
    .solid { border-top:2px solid #000; margin:7px 0; }
    .row { display:flex; justify-content:space-between; gap:8px; }
    h3 { font-size:12px; text-align:center; letter-spacing:1px; margin:9px 0 5px; text-transform:uppercase; }
    table { width:100%; border-collapse:collapse; font-size:11px; }
    td { padding:1.5px 0; vertical-align:top; }
    td.r, th.r { text-align:right; }
    th { border-bottom:1px solid #000; text-align:left; padding-bottom:2px; font-size:10.5px; }
    .tot { font-size:13px; font-weight:700; }
    @page { size: 80mm auto; margin: 2mm; }
    @media print { .noprint { display:none !important; } }
</style>
</head>
<body>
@php
    $fmt = fn ($v) => \Auth::user()->priceFormat((float) $v);
    $reportDate = \Carbon\Carbon::parse($date);
    $totalCashReceived = (float) ($cashTotal ?? 0) + (float) ($cashFromBalance ?? 0);
@endphp
<div class="slip">
    <div class="c b big">{{ $companyName }}</div>
    @if(!empty($vatNumber))<div class="c">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
    @if(!empty($companyAddress))<div class="c">{{ $companyAddress }}</div>@endif
    @if(!empty($companyTelephone))<div class="c">Tel: {{ $companyTelephone }}</div>@endif
    <div class="solid"></div>
    <div class="c b big">{{ __('END OF DAY REPORT') }}</div>
    <div class="c">EOD · {{ $reportDate->format('d/m/Y') }}</div>
    <div class="line"></div>
    <div class="row"><span>{{ __('Date') }}:</span><span>{{ $reportDate->format('d/m/Y') }} ({{ $reportDate->format('l') }})</span></div>
    <div class="row"><span>{{ __('Printed') }}:</span><span>{{ date('d/m/Y H:i:s') }}</span></div>
    <div class="row"><span>{{ __('Cash Register') }}:</span><span>1</span></div>
    <div class="row"><span>{{ __('Tickets') }}:</span><span>{{ (int) ($ticketFrom ?? 0) }} → {{ (int) ($ticketTo ?? 0) }}</span></div>
    <div class="row"><span>{{ __('Receipts Issued') }}:</span><span>{{ $receiptsCount }}</span></div>
    <div class="row"><span>{{ __('SALESPERSON') }}:</span><span>{{ $salespersonName }}</span></div>

    <div class="solid"></div>
    <div class="row tot"><span>{{ __('TOTAL SALES') }}</span><span>{{ $fmt($totalSales) }}</span></div>
    <div class="row"><span>{{ __('Customers') }}:</span><span>{{ $customersCount }}</span></div>
    <div class="row"><span>{{ __('Average X Receipt') }}:</span><span>{{ $fmt($avgPerReceipt) }}</span></div>
    <div class="row"><span>{{ __('Average X Customer') }}:</span><span>{{ $fmt($avgPerCustomer) }}</span></div>

    <h3>{{ __('SALES BY PAYMENT METHOD') }}</h3>
    <table>
        <tr><th>{{ __('PAYMENT METHOD') }}</th><th class="r">{{ __('NO.') }}</th><th class="r">{{ __('AMOUNT') }}</th></tr>
        @foreach($paymentMethodRows as $r)
            <tr><td>{{ $r['name'] }}</td><td class="r">{{ $r['count'] }}</td><td class="r">{{ $fmt($r['amount']) }}</td></tr>
        @endforeach
    </table>
    <div class="line"></div>
    <div class="row b"><span>{{ __('Total') }}</span><span>{{ $fmt($totalSales) }}</span></div>

    <h3>{{ __('CASH RECEIVED') }}</h3>
    <div class="row"><span>{{ __('Cash from sales') }}</span><span>{{ $fmt($cashTotal ?? 0) }}</span></div>
    @if(isset($cashFromBalance) && $cashFromBalance > 0)
        <div class="row"><span>{{ __('Cash from balance payments') }}</span><span>{{ $fmt($cashFromBalance) }}</span></div>
    @endif
    <div class="row b"><span>{{ __('Total cash received') }}</span><span>{{ $fmt($totalCashReceived) }}</span></div>

    @if(!empty($accountsReceivableRows) && count($accountsReceivableRows) > 0)
    <h3>{{ __('pos.accounts_receivable') }}</h3>
    @foreach($accountsReceivableRows as $ar)
        <div class="row"><span>{{ __('Invoice') }}: {{ $ar['invoice_id'] }}</span><span>{{ $fmt($ar['outstanding']) }}</span></div>
    @endforeach
    <div class="row b"><span>{{ __('pos.total_outstanding') }}</span><span>{{ $fmt($accountsReceivableTotal) }}</span></div>
    @endif

    <h3>{{ __('SALES PER EMPLOYEE') }}</h3>
    <table>
        <tr><th>{{ __('Employee') }}</th><th class="r">{{ __('NO.') }}</th><th class="r">{{ __('AMOUNT') }}</th></tr>
        @foreach($salesPerEmployee as $r)
            <tr><td>{{ $r['name'] }}</td><td class="r">{{ $r['count'] }}</td><td class="r">{{ $fmt($r['amount']) }}</td></tr>
        @endforeach
    </table>
    <div class="line"></div>
    <div class="row b"><span>{{ __('Total') }}</span><span>{{ $fmt($totalSales) }}</span></div>

    @if(count($categorySummary ?? []) > 0)
    <h3>{{ __('SALES BY SECTION') }}</h3>
    <table>
        <tr><th>{{ __('SECTION') }}</th><th class="r">{{ __('Items') }}</th><th class="r">{{ __('AMOUNT') }}</th><th class="r">%</th></tr>
        @php $totalCat = array_sum(array_map(fn ($c) => (float) $c['amount'], $categorySummary)); @endphp
        @foreach($categorySummary as $catName => $row)
            <tr><td>{{ $catName }}</td><td class="r">{{ number_format((float) $row['qty'], 0) }}</td><td class="r">{{ $fmt($row['amount']) }}</td><td class="r">{{ $totalCat > 0 ? number_format($row['amount'] / $totalCat * 100, 1) : '0.0' }}</td></tr>
        @endforeach
    </table>
    @endif

    @if(count($taxSummary ?? []) > 0)
    <h3>{{ __('TAXES') }}</h3>
    <table>
        <tr><th>{{ __('TAX') }}</th><th class="r">{{ __('BASE') }}</th><th class="r">{{ __('QUOTA') }}</th><th class="r">{{ __('TOTAL') }}</th></tr>
        @foreach($taxSummary as $taxName => $r)
            <tr><td>{{ $taxName }}</td><td class="r">{{ $fmt($r['base']) }}</td><td class="r">{{ $fmt($r['quota']) }}</td><td class="r">{{ $fmt($r['total']) }}</td></tr>
        @endforeach
    </table>
    @endif

    @if(count($itemsSold ?? []) > 0)
    <h3>{{ __('SUMMARY OF ITEMS SOLD') }}</h3>
    <table>
        <tr><th>{{ __('ITEM') }}</th><th class="r">{{ __('Qty') }}</th><th class="r">{{ __('AMOUNT') }}</th></tr>
        @foreach($itemsSold as $itemName => $row)
            <tr><td>{{ $itemName }}</td><td class="r">{{ number_format((float) $row['qty'], 2) }}</td><td class="r">{{ $fmt($row['amount']) }}</td></tr>
        @endforeach
    </table>
    <div class="line"></div>
    <div class="row b"><span>{{ __('Total') }}</span><span>{{ $fmt($totalSales) }}</span></div>
    @endif

    <div class="solid"></div>
    <div class="c">{{ __('Cashier') }}: {{ $salespersonName }}</div>
    <div class="c" style="margin-top:16px;">________________________</div>
    <div class="c">{{ __('Manager Signature') }}</div>
    <div class="c" style="margin-top:10px;">*** {{ __('END OF REPORT') }} ***</div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
