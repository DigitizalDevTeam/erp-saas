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

@push('css-page')
<style>
@media print {
    .no-print, .card .btn, .breadcrumb, nav, .dash-hasmenu, .dash-submenu, .sidebar, header { display: none !important; }
    #eod-printable { font-size: 11px; }
    body { background: #fff; }
    .content-wrapper { padding: 0 !important; }
}
</style>
@endpush
