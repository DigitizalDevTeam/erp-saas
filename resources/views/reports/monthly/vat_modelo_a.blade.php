@extends('layouts.admin')

@section('page-title')
    {{ __('VAT Report (Modelo A IVA)') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Monthly Reports') }}</li>
    <li class="breadcrumb-item">{{ __('VAT (Modelo A IVA)') }}</li>
@endsection

@push('script-page')
    <script>
        function printMonthlyVat() {
            const printContents = document.getElementById('printableArea').innerHTML;
            const originalContents = document.body.innerHTML;
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
            window.location.reload();
        }
    </script>
@endpush

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="printMonthlyVat()" class="btn btn-sm btn-primary-subtle me-1" data-bs-toggle="tooltip"
            title="{{ __('Print / Save PDF') }}"><i class="ti ti-printer"></i></a>
    </div>

    <div class="float-end me-2">
        <a href="#" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="{{ __('Filter') }}"
            onclick="document.getElementById('monthly_report_filter').classList.toggle('d-none'); return false;">
            <i class="ti ti-filter"></i>
        </a>
    </div>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card d-none" id="monthly_report_filter">
                <div class="card-body">
                    {{ Form::open(['route' => ['monthly.reports.vat-modelo-a'], 'method' => 'GET']) }}
                    <div class="row align-items-end">
                        <div class="col-md-4">
                            <div class="btn-box">
                                {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                                {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="btn-box">
                                {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                                {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="ti ti-search"></i> {{ __('Apply') }}
                                </button>
                                <a href="{{ route('monthly.reports.vat-modelo-a') }}" class="btn btn-sm btn-danger">
                                    <i class="ti ti-refresh"></i> {{ __('Reset') }}
                                </a>
                            </div>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center" id="printableArea">
        <div class="col-md-10">
            <div class="card">
                <div class="card-body">
                    {{-- GOVERNMENT FORMAT AREA (EDIT THIS VIEW FREELY) --}}
                    <div class="text-center mb-4">
                        <div class="fw-bold" style="font-size: 16px;">{{ __('MODELO A IVA') }}</div>
                        <div style="font-size: 12px;">
                            {{ __('Period') }}: {{ $filter['startDateRange'] }} {{ __('to') }} {{ $filter['endDateRange'] }}
                        </div>
                        <div style="font-size: 12px;">
                            {{ __('VAT Tax') }}: {{ $filter['vatTaxName'] }} ({{ rtrim(rtrim(number_format($vatRate, 2), '0'), '.') }}%)
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded p-3">
                                <div class="fw-bold mb-2">{{ __('Sales (VAT Output)') }}</div>
                                <div class="d-flex justify-content-between">
                                    <div>{{ __('Taxable Base') }}</div>
                                    <div>{{ \Auth::user()->priceFormat($sales['taxable_base']) }}</div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <div>{{ __('VAT Output') }}</div>
                                    <div>{{ \Auth::user()->priceFormat($sales['vat_output']) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded p-3">
                                <div class="fw-bold mb-2">{{ __('Purchases (VAT Input)') }}</div>
                                <div class="d-flex justify-content-between">
                                    <div>{{ __('Taxable Base') }}</div>
                                    <div>{{ \Auth::user()->priceFormat($purchases['taxable_base']) }}</div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <div>{{ __('VAT Input') }}</div>
                                    <div>{{ \Auth::user()->priceFormat($purchases['vat_input']) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="border rounded p-3">
                                <div class="fw-bold mb-2">{{ __('Summary') }}</div>
                                <div class="d-flex justify-content-between">
                                    <div>{{ __('VAT Payable (Output - Input)') }}</div>
                                    <div>{{ \Auth::user()->priceFormat($summary['vat_payable']) }}</div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <div>{{ __('VAT Credit (Input - Output)') }}</div>
                                    <div>{{ \Auth::user()->priceFormat($summary['vat_credit']) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{-- END GOVERNMENT FORMAT AREA --}}
                </div>
            </div>
        </div>
    </div>
@endsection

