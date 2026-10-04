<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('End of Day (EOD) Report') }} — {{ $date }}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color:#000; font-size:11px; line-height:1.4; }
    .page { padding: 10px 4px; }
    .center { text-align:center; }
    .company { font-size:15px; font-weight:bold; }
    .small { font-size:11px; }
    .title { font-size:14px; font-weight:bold; text-align:center; margin:8px 0 1px; text-transform:uppercase; }
    .subtitle { text-align:center; font-size:10px; margin-bottom:4px; }
    hr { border:none; border-top:1px solid #000; margin:6px 0; }
    .meta { width:100%; border-collapse:collapse; margin:3px 0; }
    .meta td { padding:1px 4px; vertical-align:top; }
    .meta td.k { width:18%; }
    h2.sec { font-size:11px; font-weight:bold; text-transform:uppercase; margin:12px 0 4px; }
    table.data { width:100%; border-collapse:collapse; table-layout:fixed; }
    table.data th, table.data td { border:1px solid #999; padding:3px 6px; text-align:left; }
    .meta td { white-space:nowrap; overflow:hidden; }
    table.data th { font-weight:bold; }
    .num { text-align:right !important; }
    tfoot td { font-weight:bold; }
    .sign { margin-top:34px; width:100%; border-collapse:collapse; }
    .sign td { width:33%; text-align:center; padding:0 20px; }
    .sign .line { border-top:1px solid #000; padding-top:5px; font-size:11px; }
    .foot { margin-top:16px; font-size:9px; text-align:center; }
    @page { size: A4; margin: 12mm; }
    @media print { .noprint { display:none !important; } }
</style>
</head>
<body>
@php
    $fmtUser = \Auth::user();
    $fmt = fn ($v) => $fmtUser ? $fmtUser->priceFormat((float) $v) : \App\Models\Utility::priceFormat($settings ?? [], (float) $v);
    $reportDate = \Carbon\Carbon::parse($date);
    $totalCashReceived = (float) ($cashTotal ?? 0) + (float) ($cashFromBalance ?? 0);
    $itemsQtyTotal = 0.0; foreach (($itemsSold ?? []) as $row) { $itemsQtyTotal += (float) ($row['qty'] ?? 0); }
@endphp
<div class="page">
    <div class="center">
        <div class="company">{{ $companyName }}</div>
        @if(!empty($vatNumber))<div class="small">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
        @if(!empty($companyAddress))<div class="small">{{ $companyAddress }}</div>@endif
        @if(!empty($companyTelephone) || !empty($companyEmail))<div class="small">@if(!empty($companyTelephone))Tel: {{ $companyTelephone }}@endif @if(!empty($companyEmail)) · {{ $companyEmail }}@endif</div>@endif
    </div>

    <div class="title">{{ __('End of Day (EOD) Report') }}</div>
    <div class="subtitle">EOD · {{ $reportDate->format('d/m/Y') }}</div>

    <hr>

    <table class="meta">
        <tr>
            <td class="k">{{ __('Report Date') }}:</td><td>{{ $reportDate->format('l, d M Y') }}</td>
            <td class="k">{{ __('Cash Register') }}:</td><td>1</td>
        </tr>
        <tr>
            <td class="k">{{ __('Tickets') }}:</td><td>{{ sprintf('%09d', $ticketFrom ?? 0) }} {{ __('To') }} {{ sprintf('%09d', $ticketTo ?? 0) }}</td>
            <td class="k">{{ __('Receipts Issued') }}:</td><td>{{ $receiptsCount }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('SALESPERSON') }}:</td><td>{{ $salespersonName }}</td>
            <td class="k">{{ __('Printed') }}:</td><td>{{ date('d/m/Y H:i:s') }}</td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="k"><strong>{{ __('TOTAL SALES') }}:</strong></td><td><strong>{{ $fmt($totalSales) }}</strong></td>
            <td class="k">{{ __('Cash Received') }}:</td><td>{{ $fmt($totalCashReceived) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('Customers') }}:</td><td>{{ $customersCount }}</td>
            <td class="k">{{ __('Average X Receipt') }}:</td><td>{{ $fmt($avgPerReceipt) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('Average X Customer') }}:</td><td>{{ $fmt($avgPerCustomer) }}</td>
            <td class="k"></td><td></td>
        </tr>
    </table>

    <h2 class="sec">{{ __('SALES BY PAYMENT METHOD') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
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

    <h2 class="sec">{{ __('CASH RECEIVED') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <tbody>
            <tr><td>{{ __('Cash from sales') }}</td><td class="num" colspan="3">{{ $fmt($cashTotal ?? 0) }}</td></tr>
            @if(isset($cashFromBalance) && $cashFromBalance > 0)
                <tr><td>{{ __('Cash from balance payments') }}</td><td class="num" colspan="3">{{ $fmt($cashFromBalance) }}</td></tr>
            @endif
        </tbody>
        <tfoot><tr><td>{{ __('Total cash received') }}</td><td class="num" colspan="3">{{ $fmt($totalCashReceived) }}</td></tr></tfoot>
    </table>

    @if(!empty($accountsReceivableRows) && count($accountsReceivableRows) > 0)
    <h2 class="sec">{{ __('pos.accounts_receivable') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('Invoice') }}</th><th class="num">{{ __('Total') }}</th><th class="num" colspan="2">{{ __('Outstanding') }}</th></tr></thead>
        <tbody>
        @foreach($accountsReceivableRows as $ar)
            <tr><td>{{ $ar['invoice_id'] }}</td><td class="num">{{ $fmt($ar['total']) }}</td><td class="num" colspan="2">{{ $fmt($ar['outstanding']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('pos.total_outstanding') }}</td><td class="num"></td><td class="num" colspan="2">{{ $fmt($accountsReceivableTotal) }}</td></tr></tfoot>
    </table>
    @endif

    <h2 class="sec">{{ __('SALES PER EMPLOYEE') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('Employee') }}</th><th class="num">{{ __('Receipts') }}</th><th class="num" colspan="2">{{ __('AMOUNT') }}</th></tr></thead>
        <tbody>
        @foreach($salesPerEmployee as $r)
            <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['count'] }}</td><td class="num" colspan="2">{{ $fmt($r['amount']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="num">{{ $receiptsCount }}</td><td class="num" colspan="2">{{ $fmt($totalSales) }}</td></tr></tfoot>
    </table>

    @if(count($categorySummary ?? []) > 0)
    <h2 class="sec">{{ __('SALES BY SECTION') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
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

    @if(count($taxSummary ?? []) > 0)
    <h2 class="sec">{{ __('TAXES') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('TAX') }}</th><th class="num">{{ __('BASE') }}</th><th class="num">{{ __('QUOTA') }}</th><th class="num">{{ __('TOTAL') }}</th></tr></thead>
        <tbody>
        @foreach($taxSummary as $taxName => $r)
            <tr><td>{{ $taxName }}</td><td class="num">{{ $fmt($r['base']) }}</td><td class="num">{{ $fmt($r['quota']) }}</td><td class="num">{{ $fmt($r['total']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif

    @if(count($itemsSold ?? []) > 0)
    <h2 class="sec">{{ __('SUMMARY OF ITEMS SOLD') }}</h2>
    <table class="data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('ITEM') }}</th><th class="num">{{ __('Qty') }}</th><th class="num" colspan="2">{{ __('AMOUNT') }}</th></tr></thead>
        <tbody>
        @foreach($itemsSold as $itemName => $row)
            <tr><td>{{ $itemName }}</td><td class="num">{{ number_format((float) $row['qty'], 2) }}</td><td class="num" colspan="2">{{ $fmt($row['amount']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="num">{{ number_format($itemsQtyTotal, 2) }}</td><td class="num" colspan="2">{{ $fmt($totalSales) }}</td></tr></tfoot>
    </table>
    @endif

    <table class="sign">
        <tr>
            <td><div class="line">{{ __('Cashier') }} — {{ $salespersonName }}</div></td>
            <td><div class="line">{{ __('Manager Signature') }}</div></td>
            <td><div class="line">{{ __('Date / Time') }}</div></td>
        </tr>
    </table>

    <div class="foot">{{ $companyName }} · {{ __('End of Day (EOD) Report') }} · {{ $reportDate->format('d/m/Y') }}</div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
