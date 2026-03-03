@extends('layouts.admin')
@section('page-title')
    {{ __('End of Day (EOD) Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('End of Day Report') }}</li>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success mb-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mb-3">{{ session('error') }}</div>
    @endif
    @if(!empty($openShiftsForDate) && $openShiftsForDate->count() > 0)
        <div class="alert alert-warning mb-3">
            <strong>{{ __('Shift(s) still open') }}</strong>: {{ __('Please close all shifts before closing the day.') }}
            <ul class="mb-0 mt-1">
                @foreach($openShiftsForDate ?? [] as $s)
                    <li>{{ $s->branch ? $s->branch->name : '-' }} — {{ $s->user ? $s->user->name : '-' }} ({{ __('opened') }} {{ $s->opened_at ? $s->opened_at->format('H:i') : '-' }})</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if(!empty($isDayClosed))
        <div class="alert alert-info mb-3">{{ __('Day is closed. No new shifts can be opened for this date until an administrator reopens.') }}</div>
    @endif
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.pos.eod', 'method' => 'get', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-auto">
                        {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}
                        {{ Form::date('date', $date, ['class' => 'form-control']) }}
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">{{ __('Show') }}</button>
                    </div>
                    <div class="col-auto ms-auto">
                        <a href="{{ route('report.pos.eod.export', ['date' => $date]) }}" class="btn btn-success"><i class="ti ti-file-spreadsheet"></i> {{ __('Export Excel') }}</a>
                        <button type="button" class="btn btn-secondary" onclick="window.print();"><i class="ti ti-printer"></i> {{ __('Print') }}</button>
                        @if(!empty($canCloseDay))
                            @if(empty($isDayClosed))
                                @if(empty($openShiftsForDate) || $openShiftsForDate->count() === 0)
                                    {{ Form::open(['route' => 'report.pos.close.day', 'method' => 'post', 'class' => 'd-inline']) }}
                                    {{ Form::hidden('date', $date) }}
                                    @csrf
                                    <button type="submit" class="btn btn-danger"><i class="ti ti-lock"></i> {{ __('Close day') }}</button>
                                    {{ Form::close() }}
                                @endif
                            @else
                                {{ Form::open(['route' => 'report.pos.reopen.day', 'method' => 'post', 'class' => 'd-inline']) }}
                                {{ Form::hidden('date', $date) }}
                                @csrf
                                <button type="submit" class="btn btn-outline-success"><i class="ti ti-lock-open"></i> {{ __('Reopen day') }}</button>
                                {{ Form::close() }}
                            @endif
                        @endif
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row no-print">
        <div class="col-12">
            <p class="text-muted small">{{ __('Use Print to print the report below. Use Export Excel to download as spreadsheet.') }}</p>
        </div>
    </div>

    <div class="no-print mb-2">
        <label class="me-2">{{ __('Print width') }}:</label>
        <select id="eod-print-width" class="form-select form-select-sm d-inline-block" style="width: auto;">
            <option value="full">{{ __('Full page') }}</option>
            <option value="80">{{ __('80mm receipt') }}</option>
            <option value="83">{{ __('83mm receipt') }}</option>
            <option value="58">{{ __('58mm receipt') }}</option>
        </select>
    </div>
    <div id="eod-printable" class="eod-report bg-white p-4" style="font-family: 'Courier New', monospace; font-size: 13px;">
        <div class="border-bottom pb-2 mb-2">
            <strong>----------------------------------------</strong><br>
            {{ __('Company') }}: {{ $companyName }}<br>
            {{ __('VAT NR') }}: {{ $vatNumber }}<br>
            {{ __('Address') }}: {{ $companyAddress }}<br>
            {{ __('Total Daily') }}: {{ \Auth::user()->priceFormat($totalSales) }} &nbsp; {{ __('Cash Register') }}: 1<br>
            {{ __('Ticket From') }}: {{ sprintf('%09d', $ticketFrom ?? 0) }} {{ __('To') }}: {{ sprintf('%09d', $ticketTo ?? 0) }}<br>
            {{ __('Receipts Issued') }}: {{ $receiptsCount }}<br>
            {{ __('Date') }}: {{ \Carbon\Carbon::parse($date)->format('m/d/Y') }} &nbsp; {{ __('Time') }}: {{ date('H:i:s') }}<br>
            {{ __('SALESPERSON') }}: {{ $salespersonName }}<br>
            <strong>----------------------------------------</strong>
        </div>

        <div class="mb-3">
            <strong>{{ __('SALES BY PAYMENT METHOD') }}</strong><br>
            <table class="table table-sm table-bordered mb-0" style="font-size: 12px;">
                <thead>
                    <tr>
                        <th>{{ __('PAYMENT METHOD') }}</th>
                        <th class="text-end">{{ __('AMOUNT') }}</th>
                        <th class="text-end">{{ __('NO.') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($paymentMethodRows as $r)
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td class="text-end">{{ \Auth::user()->priceFormat($r['amount']) }}</td>
                            <td class="text-end">{{ $r['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td></td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($totalSales) }}</td>
                        <td class="text-end">{{ $receiptsCount }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mb-2">
            <strong>{{ __('CASH RECEIVED') }}</strong><br>
            {{ __('Cash from sales') }} &nbsp; {{ \Auth::user()->priceFormat($cashTotal ?? 0) }}<br>
            @if(isset($cashFromBalance) && $cashFromBalance > 0)
                {{ __('Cash from balance payments') }} &nbsp; {{ \Auth::user()->priceFormat($cashFromBalance) }}<br>
            @endif
            @if(!empty($receivedCreditPayments) && count($receivedCreditPayments) > 0)
                {{ __('pos.credit_received_label') ?: 'Credit Received' }}:<br>
                @foreach($receivedCreditPayments as $rp)
                    {{ __('Invoice') }}: {{ $rp['invoice_id'] }} &nbsp; {{ $rp['payment_method'] }} &nbsp; {{ \Auth::user()->priceFormat($rp['amount']) }}<br>
                @endforeach
            @endif
            {{ __('Total cash received') }} &nbsp; {{ \Auth::user()->priceFormat(($cashTotal ?? 0) + ($cashFromBalance ?? 0)) }}<br>
            ----------------------------------------
        </div>
        @if(!empty($accountsReceivableRows) && count($accountsReceivableRows) > 0)
        <div class="mb-3">
            <strong>{{ __('pos.accounts_receivable') }}</strong><br>
            @foreach($accountsReceivableRows as $ar)
                {{ __('Invoice') }}: {{ $ar['invoice_id'] }} &nbsp;
                {{ __('Total') }}: {{ \Auth::user()->priceFormat($ar['total']) }} &nbsp;
                {{ __('Outstanding') }}: {{ \Auth::user()->priceFormat($ar['outstanding']) }}<br>
            @endforeach
            <strong>{{ __('pos.total_outstanding') }}:</strong> {{ \Auth::user()->priceFormat($accountsReceivableTotal) }}<br>
            ----------------------------------------
        </div>
        @endif
        <div class="mb-3">
            <strong>{{ __('CASH REGISTER CHART') }}</strong><br>
            @foreach($paymentMethodRows as $r)
                {{ $r['name'] }}<br>
                ----------------------------------------------------<br>
                {{ $r['count'] }} {{ __('SALES (Positive)') }} &nbsp; {{ \Auth::user()->priceFormat($r['amount']) }}<br>
            @endforeach
            {{ __('TOTAL SALES') }} &nbsp; {{ \Auth::user()->priceFormat($totalSales) }}<br>
            ----------------------------------------------------
        </div>

        <div class="mb-3">
            <strong>{{ __('SUMMARY') }}</strong><br>
            ----------------------------------------<br>
            {{ __('PAYMENT METHOD') }} &nbsp; {{ __('DECLARED.') }} &nbsp; {{ __('CALCULATION') }} &nbsp; {{ __('DIFFERENCE') }}<br>
            ----------------------------------------<br>
            @foreach($paymentMethodRows as $r)
                {{ $r['name'] }} &nbsp; 0.00 &nbsp; {{ \Auth::user()->priceFormat($r['amount']) }} &nbsp; {{ \Auth::user()->priceFormat(-$r['amount']) }}<br>
            @endforeach
            {{ __('TOTAL') }}: MT &nbsp; 0.00 &nbsp; {{ \Auth::user()->priceFormat($totalSales) }} &nbsp; {{ \Auth::user()->priceFormat(-$totalSales) }}<br>
            ----------------------------------------<br>
            {{ __('SALES (Pos.)') }} &nbsp; {{ __('CREDITS') }} &nbsp; {{ __('TOTAL SALES') }}<br>
            ----------------------------------------<br>
            {{ \Auth::user()->priceFormat($totalSales) }} &nbsp; 0.00 &nbsp; {{ \Auth::user()->priceFormat($totalSales) }}
        </div>

        <div class="mb-3">
            <strong>{{ __('STATEMENT BY PAYMENT METHOD') }}</strong><br>
            {{ __('POS') }}<br>
            N. &nbsp; {{ __('TIKET') }} &nbsp; {{ __('COUNT') }} &nbsp; {{ __('TIME') }} &nbsp; {{ __('Employee') }} &nbsp; {{ __('AMOUNT') }}<br>
            ----------------------------------------<br>
            @foreach($statementRows as $idx => $r)
                T001 &nbsp; {{ $r['ticket'] }} &nbsp; {{ $r['count'] }} &nbsp; {{ $r['time'] }} &nbsp; {{ $r['employee'] }} &nbsp; {{ \Auth::user()->priceFormat($r['amount']) }}<br>
            @endforeach
            =======<br>
            {{ \Auth::user()->priceFormat($totalSales) }}
        </div>

        <div class="mb-3">
            <strong>{{ __('SALES PER EMPLOYEE') }}</strong><br>
            {{ __('Employee') }} &nbsp; {{ __('AMOUNT') }} &nbsp; N.O<br>
            -----------------------------------------------------------------------------<br>
            @foreach($salesPerEmployee as $r)
                {{ $r['name'] }} &nbsp; {{ \Auth::user()->priceFormat($r['amount']) }} &nbsp; {{ $r['count'] }}<br>
            @endforeach
            ======= &nbsp; =====<br>
            {{ \Auth::user()->priceFormat($totalSales) }} &nbsp; {{ $receiptsCount }}
        </div>

        <div class="mb-3">
            <strong>{{ __('SALES SUMMARY') }}</strong><br>
            BAR &nbsp; TABLES &nbsp; TOTAL<br>
            ----------------------------------------<br>
            {{ __('SALES') }} &nbsp; {{ \Auth::user()->priceFormat($totalSales) }} &nbsp; 0.00 &nbsp; {{ \Auth::user()->priceFormat($totalSales) }}<br>
            {{ __('NUMBER OF RECEIPTS') }} &nbsp; {{ $receiptsCount }} &nbsp; 0 &nbsp; {{ $receiptsCount }}<br>
            {{ __('NUMBER CUSTOMERS') }} &nbsp; {{ $customersCount }} &nbsp; 0 &nbsp; {{ $customersCount }}<br>
            {{ __('Average X Receipt') }} &nbsp; {{ \Auth::user()->priceFormat($avgPerReceipt) }} &nbsp; 0.00 &nbsp; {{ \Auth::user()->priceFormat($avgPerReceipt) }}<br>
            {{ __('Average X Customer') }} &nbsp; {{ \Auth::user()->priceFormat($avgPerCustomer) }} &nbsp; 0.00 &nbsp; {{ \Auth::user()->priceFormat($avgPerCustomer) }}<br>
            {{ __('AMOUNT RETURNED') }} &nbsp; 0.00 &nbsp; 0.00 &nbsp; 0.00<br>
            {{ __('NUMBER OF RETURNS') }} &nbsp; 0 &nbsp; 0 &nbsp; 0
        </div>

        @if(count($categorySummary) > 0)
            <div class="mb-3">
                <strong>{{ __('SALES BY SECTION') }}</strong><br>
                {{ __('SECTION') }} &nbsp; N. ART &nbsp; {{ __('AMOUNT') }} &nbsp; %<br>
                ----------------------------------------<br>
                @php $totalCat = array_sum(array_map(fn($c) => $c['amount'], $categorySummary)); @endphp
                @foreach($categorySummary as $catName => $row)
                    @php $pct = $totalCat > 0 ? round($row['amount'] / $totalCat * 100, 2) : 0; @endphp
                    {{ $catName }} &nbsp; {{ number_format($row['qty'], 2) }} &nbsp; {{ \Auth::user()->priceFormat($row['amount']) }} &nbsp; {{ $pct }}%<br>
                @endforeach
            </div>
        @endif

        @if(count($taxSummary) > 0)
            <div class="mb-3">
                <strong>{{ __('TAXES') }}</strong><br>
                {{ __('TAX') }} &nbsp; {{ __('BASE') }} &nbsp; {{ __('QUOTA') }} &nbsp; {{ __('TOTAL') }}<br>
                ----------------------------------------<br>
                @foreach($taxSummary as $taxName => $r)
                    {{ $taxName }} &nbsp; {{ \Auth::user()->priceFormat($r['base']) }} &nbsp; {{ \Auth::user()->priceFormat($r['quota']) }} &nbsp; {{ \Auth::user()->priceFormat($r['total']) }}<br>
                @endforeach
            </div>
        @endif

        @if(count($itemsSold) > 0)
            <div class="mb-3">
                <strong>{{ __('SUMMARY OF ITEMS SOLD') }}</strong><br>
                {{ __('ITEM') }} &nbsp; QTY &nbsp; {{ __('AMOUNT') }}<br>
                ----------------------------------------<br>
                @foreach($itemsSold as $itemName => $row)
                    {{ $itemName }} &nbsp; {{ number_format($row['qty'], 2) }} &nbsp; {{ \Auth::user()->priceFormat($row['amount']) }}<br>
                @endforeach
                ======= &nbsp; ==========<br>
                {{ number_format(array_sum(array_column($itemsSold, 'qty')), 2) }} &nbsp; {{ \Auth::user()->priceFormat($totalSales) }}
            </div>
        @endif

        <div class="mb-3">
            <strong>{{ __('PREPAID CARD SUMMARY') }}</strong><br>
            {{ __('ORDER NUMBER') }} &nbsp; {{ __('AMOUNT') }}<br>
            ----------------------------------------<br>
            {{ __('RECHARGES') }} &nbsp; 0 &nbsp; 0.00<br>
            {{ __('SALES') }} &nbsp; 0 &nbsp; 0.00
        </div>
    </div>

@endsection

@push('script-page')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('eod-print-width');
    var el = document.getElementById('eod-printable');
    if (sel && el) {
        sel.addEventListener('change', function() {
            el.classList.remove('eod-print-80', 'eod-print-83', 'eod-print-58');
            if (this.value === '80') el.classList.add('eod-print-80');
            else if (this.value === '83') el.classList.add('eod-print-83');
            else if (this.value === '58') el.classList.add('eod-print-58');
        });
    }
});
</script>
@endpush

@push('css-page')
<style>
/* EOD printable: align for thermal receipt 80mm (302px), 83mm (314px) or 58mm (219px) */
#eod-printable.eod-print-80 { max-width: 302px; margin: 0 auto; }
#eod-printable.eod-print-83 { max-width: 314px; margin: 0 auto; }
#eod-printable.eod-print-58 { max-width: 219px; margin: 0 auto; font-size: 10px !important; }
#eod-printable.eod-print-80 .table { font-size: 10px; }
#eod-printable.eod-print-83 .table { font-size: 10px; }
#eod-printable.eod-print-58 .table { font-size: 9px; }
@media print {
    .no-print, .card .btn, .breadcrumb, nav, .dash-hasmenu, .dash-submenu, .sidebar, header { display: none !important; }
    #eod-printable { font-size: 11px; }
    body { background: #fff; }
    .content-wrapper { padding: 0 !important; }
    /* Receipt width: use 80mm (302px), 83mm (314px) or 58mm (219px) via class on #eod-printable */
    #eod-printable.eod-print-80 { max-width: 302px; margin: 0 auto; width: 302px; font-size: 10px; }
    #eod-printable.eod-print-83 { max-width: 314px; margin: 0 auto; width: 314px; font-size: 10px; }
    #eod-printable.eod-print-58 { max-width: 219px; margin: 0 auto; width: 219px; font-size: 9px; }
    #eod-printable.eod-print-80 .table, #eod-printable.eod-print-80 table { font-size: 9px; }
    #eod-printable.eod-print-83 .table, #eod-printable.eod-print-83 table { font-size: 9px; }
    #eod-printable.eod-print-58 .table, #eod-printable.eod-print-58 table { font-size: 8px; }
    /* default page size; use printer dialog to choose A3 if needed */
    @page { size: auto; }
}

</style>
@endpush
