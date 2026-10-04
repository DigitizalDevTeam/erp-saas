@php
    $fmtUser = \Auth::user();
    $fmt = fn ($v) => $fmtUser ? $fmtUser->priceFormat((float) $v) : \App\Models\Utility::priceFormat($settings ?? [], (float) $v);
    $reportDate = \Carbon\Carbon::parse($date);
    $totalCashReceived = (float) ($cashTotal ?? 0) + (float) ($cashFromBalance ?? 0);
    $itemsQtyTotal = 0.0; foreach (($itemsSold ?? []) as $row) { $itemsQtyTotal += (float) ($row['qty'] ?? 0); }
@endphp
<div class="eod-a4-page">
    <div class="eod-a4-center">
        <div class="eod-a4-company">{{ $companyName }}</div>
        @if(!empty($vatNumber))<div class="eod-a4-small">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
        @if(!empty($companyAddress))<div class="eod-a4-small">{{ $companyAddress }}</div>@endif
        @if(!empty($companyTelephone) || !empty($companyEmail))<div class="eod-a4-small">@if(!empty($companyTelephone))Tel: {{ $companyTelephone }}@endif @if(!empty($companyEmail)) · {{ $companyEmail }}@endif</div>@endif
    </div>

    <div class="eod-a4-title">{{ __('End of Day (EOD) Report') }}</div>
    <div class="eod-a4-subtitle">EOD · {{ $reportDate->format('d/m/Y') }}</div>

    <hr>

    <table class="eod-a4-meta">
        <tr>
            <td class="eod-a4-key">{{ __('Report Date') }}:</td><td>{{ $reportDate->format('l, d M Y') }}</td>
            <td class="eod-a4-key">{{ __('Cash Register') }}:</td><td>1</td>
        </tr>
        <tr>
            <td class="eod-a4-key">{{ __('Tickets') }}:</td><td>{{ sprintf('%09d', $ticketFrom ?? 0) }} {{ __('To') }} {{ sprintf('%09d', $ticketTo ?? 0) }}</td>
            <td class="eod-a4-key">{{ __('Receipts Issued') }}:</td><td>{{ $receiptsCount }}</td>
        </tr>
        <tr>
            <td class="eod-a4-key">{{ __('SALESPERSON') }}:</td><td>{{ $salespersonName }}</td>
            <td class="eod-a4-key">{{ __('Printed') }}:</td><td>{{ date('d/m/Y H:i:s') }}</td>
        </tr>
    </table>

    <table class="eod-a4-meta">
        <tr>
            <td class="eod-a4-key"><strong>{{ __('TOTAL SALES') }}:</strong></td><td><strong>{{ $fmt($totalSales) }}</strong></td>
            <td class="eod-a4-key">{{ __('Cash Received') }}:</td><td>{{ $fmt($totalCashReceived) }}</td>
        </tr>
        <tr>
            <td class="eod-a4-key">{{ __('Customers') }}:</td><td>{{ $customersCount }}</td>
            <td class="eod-a4-key">{{ __('Average X Receipt') }}:</td><td>{{ $fmt($avgPerReceipt) }}</td>
        </tr>
        <tr>
            <td class="eod-a4-key">{{ __('Average X Customer') }}:</td><td>{{ $fmt($avgPerCustomer) }}</td>
            <td class="eod-a4-key"></td><td></td>
        </tr>
    </table>

    <h2 class="eod-a4-section">{{ __('SALES BY PAYMENT METHOD') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('PAYMENT METHOD') }}</th><th class="eod-a4-num">{{ __('Receipts') }}</th><th class="eod-a4-num">{{ __('AMOUNT') }}</th><th class="eod-a4-num">{{ __('Share') }}</th></tr></thead>
        <tbody>
        @foreach($paymentMethodRows as $r)
            <tr>
                <td>{{ $r['name'] }}</td>
                <td class="eod-a4-num">{{ $r['count'] }}</td>
                <td class="eod-a4-num">{{ $fmt($r['amount']) }}</td>
                <td class="eod-a4-num">{{ $totalSales > 0 ? number_format($r['amount'] / $totalSales * 100, 1) : '0.0' }}%</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total Sales') }}</td><td class="eod-a4-num">{{ $receiptsCount }}</td><td class="eod-a4-num">{{ $fmt($totalSales) }}</td><td class="eod-a4-num">100%</td></tr></tfoot>
    </table>

    <h2 class="eod-a4-section">{{ __('CASH RECEIVED') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <tbody>
            <tr><td>{{ __('Cash from sales') }}</td><td class="eod-a4-num" colspan="3">{{ $fmt($cashTotal ?? 0) }}</td></tr>
            @if(isset($cashFromBalance) && $cashFromBalance > 0)
                <tr><td>{{ __('Cash from balance payments') }}</td><td class="eod-a4-num" colspan="3">{{ $fmt($cashFromBalance) }}</td></tr>
            @endif
        </tbody>
        <tfoot><tr><td>{{ __('Total cash received') }}</td><td class="eod-a4-num" colspan="3">{{ $fmt($totalCashReceived) }}</td></tr></tfoot>
    </table>

    @if(!empty($accountsReceivableRows) && count($accountsReceivableRows) > 0)
    <h2 class="eod-a4-section">{{ __('pos.accounts_receivable') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('Invoice') }}</th><th class="eod-a4-num">{{ __('Total') }}</th><th class="eod-a4-num" colspan="2">{{ __('Outstanding') }}</th></tr></thead>
        <tbody>
        @foreach($accountsReceivableRows as $ar)
            <tr><td>{{ $ar['invoice_id'] }}</td><td class="eod-a4-num">{{ $fmt($ar['total']) }}</td><td class="eod-a4-num" colspan="2">{{ $fmt($ar['outstanding']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('pos.total_outstanding') }}</td><td class="eod-a4-num"></td><td class="eod-a4-num" colspan="2">{{ $fmt($accountsReceivableTotal) }}</td></tr></tfoot>
    </table>
    @endif

    <h2 class="eod-a4-section">{{ __('SALES PER EMPLOYEE') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('Employee') }}</th><th class="eod-a4-num">{{ __('Receipts') }}</th><th class="eod-a4-num" colspan="2">{{ __('AMOUNT') }}</th></tr></thead>
        <tbody>
        @foreach($salesPerEmployee as $r)
            <tr><td>{{ $r['name'] }}</td><td class="eod-a4-num">{{ $r['count'] }}</td><td class="eod-a4-num" colspan="2">{{ $fmt($r['amount']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="eod-a4-num">{{ $receiptsCount }}</td><td class="eod-a4-num" colspan="2">{{ $fmt($totalSales) }}</td></tr></tfoot>
    </table>

    @if(count($categorySummary ?? []) > 0)
    <h2 class="eod-a4-section">{{ __('SALES BY SECTION') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('SECTION') }}</th><th class="eod-a4-num">{{ __('Items') }}</th><th class="eod-a4-num">{{ __('AMOUNT') }}</th><th class="eod-a4-num">{{ __('Share') }}</th></tr></thead>
        <tbody>
        @php $totalCat = array_sum(array_map(fn ($c) => (float) $c['amount'], $categorySummary)); @endphp
        @foreach($categorySummary as $catName => $row)
            <tr>
                <td>{{ $catName }}</td>
                <td class="eod-a4-num">{{ number_format((float) $row['qty'], 0) }}</td>
                <td class="eod-a4-num">{{ $fmt($row['amount']) }}</td>
                <td class="eod-a4-num">{{ $totalCat > 0 ? number_format($row['amount'] / $totalCat * 100, 1) : '0.0' }}%</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="eod-a4-num">{{ number_format($itemsQtyTotal, 0) }}</td><td class="eod-a4-num">{{ $fmt($totalCat) }}</td><td class="eod-a4-num">100%</td></tr></tfoot>
    </table>
    @endif

    @if(count($taxSummary ?? []) > 0)
    <h2 class="eod-a4-section">{{ __('TAXES') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('TAX') }}</th><th class="eod-a4-num">{{ __('BASE') }}</th><th class="eod-a4-num">{{ __('QUOTA') }}</th><th class="eod-a4-num">{{ __('TOTAL') }}</th></tr></thead>
        <tbody>
        @foreach($taxSummary as $taxName => $r)
            <tr><td>{{ $taxName }}</td><td class="eod-a4-num">{{ $fmt($r['base']) }}</td><td class="eod-a4-num">{{ $fmt($r['quota']) }}</td><td class="eod-a4-num">{{ $fmt($r['total']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif

    @if(count($itemsSold ?? []) > 0)
    <h2 class="eod-a4-section">{{ __('SUMMARY OF ITEMS SOLD') }}</h2>
    <table class="eod-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('ITEM') }}</th><th class="eod-a4-num">{{ __('Qty') }}</th><th class="eod-a4-num" colspan="2">{{ __('AMOUNT') }}</th></tr></thead>
        <tbody>
        @foreach($itemsSold as $itemName => $row)
            <tr><td>{{ $itemName }}</td><td class="eod-a4-num">{{ number_format((float) $row['qty'], 2) }}</td><td class="eod-a4-num" colspan="2">{{ $fmt($row['amount']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="eod-a4-num">{{ number_format($itemsQtyTotal, 2) }}</td><td class="eod-a4-num" colspan="2">{{ $fmt($totalSales) }}</td></tr></tfoot>
    </table>
    @endif

    <table class="eod-a4-sign">
        <tr>
            <td><div class="eod-a4-sign-line">{{ __('Cashier') }} — {{ $salespersonName }}</div></td>
            <td><div class="eod-a4-sign-line">{{ __('Manager Signature') }}</div></td>
            <td><div class="eod-a4-sign-line">{{ __('Date / Time') }}</div></td>
        </tr>
    </table>

    <div class="eod-a4-foot">{{ $companyName }} · {{ __('End of Day (EOD) Report') }} · {{ $reportDate->format('d/m/Y') }}</div>
</div>
