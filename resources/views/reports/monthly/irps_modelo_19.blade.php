@extends('layouts.admin')

@section('page-title')
    {{ __('IRPS Report (Modelo 19)') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Monthly Reports') }}</li>
    <li class="breadcrumb-item">{{ __('IRPS (Modelo 19)') }}</li>
@endsection

@push('script-page')
    <script>
        function printMonthlyIrps() {
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
        <a href="#" onclick="printMonthlyIrps()" class="btn btn-sm btn-primary-subtle me-1" data-bs-toggle="tooltip"
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
                    {{ Form::open(['route' => ['monthly.reports.irps-modelo-19'], 'method' => 'GET']) }}
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <div class="btn-box">
                                {{ Form::label('month', __('Month'), ['class' => 'form-label']) }}
                                {{ Form::month('month', $filter['month'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="ti ti-search"></i> {{ __('Apply') }}
                                </button>
                                <a href="{{ route('monthly.reports.irps-modelo-19') }}" class="btn btn-sm btn-danger">
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
                        <div class="fw-bold" style="font-size: 16px;">{{ __('MODELO 19 - IRPS') }}</div>
                        <div style="font-size: 12px;">{{ __('Month') }}: {{ $filter['month'] }}</div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th>{{ __('Employee ID') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Tax Payer ID') }}</th>
                                    <th class="text-center">{{ __('Dependents') }}</th>
                                    <th class="text-end">{{ __('Gross') }}</th>
                                    <th class="text-end">{{ __('INSS (Employee)') }}</th>
                                    <th class="text-end">{{ __('INSS (Employer)') }}</th>
                                    <th class="text-end">{{ __('IRPS') }}</th>
                                    <th class="text-end">{{ __('Net') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr>
                                        <td>{{ $r['employee_id'] }}</td>
                                        <td>{{ $r['name'] }}</td>
                                        <td>{{ $r['tax_payer_id'] }}</td>
                                        <td class="text-center">{{ $r['dependents'] }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($r['gross']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($r['inss_employee']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($r['inss_employer']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($r['irps']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($r['net']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="4">{{ __('TOTAL') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['gross']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['inss_employee']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['inss_employer']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['irps']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['net']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    {{-- END GOVERNMENT FORMAT AREA --}}
                </div>
            </div>
        </div>
    </div>
@endsection

