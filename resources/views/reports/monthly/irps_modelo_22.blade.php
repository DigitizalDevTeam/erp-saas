@extends('layouts.admin')

@section('page-title')
    {{ __('IRPS / Payroll report (Model 22)') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Monthly Reports') }}</li>
    <li class="breadcrumb-item">{{ __('IRPS (Model 22)') }}</li>
@endsection

@push('script-page')
    <script>
        function printModel22() {
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
    <div class="float-end d-flex flex-wrap gap-1 justify-content-end">
        <a href="{{ route('monthly.reports.irps-modelo-22', ['month' => $filter['month'], 'download' => 'pdf']) }}"
            class="btn btn-sm btn-primary"
            data-bs-toggle="tooltip"
            title="{{ __('Download full report as PDF (all employees)') }}"
            target="_blank" rel="noopener noreferrer">
            <i class="ti ti-file"></i> {{ __('All PDF') }}
        </a>
        <a href="#" onclick="printModel22(); return false;" class="btn btn-sm btn-primary-subtle" data-bs-toggle="tooltip"
            title="{{ __('Print') }}"><i class="ti ti-printer"></i> {{ __('Print') }}</a>
    </div>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card" id="monthly_report_filter">
                <div class="card-body">
                    {{ Form::open(['route' => ['monthly.reports.irps-modelo-22'], 'method' => 'GET']) }}
                    <div class="row align-items-end">
                        <div class="col-md-5">
                            <div class="btn-box">
                                {{ Form::label('month', __('Payroll month'), ['class' => 'form-label']) }}
                                {{ Form::month('month', $filter['month'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-report-analytics"></i> {{ __('Generate report') }}
                                </button>
                                <a href="{{ route('monthly.reports.irps-modelo-22') }}" class="btn btn-outline-danger">
                                    <i class="ti ti-refresh"></i> {{ __('Reset') }}
                                </a>
                            </div>
                            <small class="text-muted d-block mt-2">{{ __('Uses payslip data for the selected month (IRPS, INSS, salary components, deductions). No manual figures.') }}</small>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center" id="printableArea">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="fw-bold" style="font-size: 17px;">{{ __('MODELO 22 — IRPS / Payroll (monthly summary)') }}</div>
                        <div class="text-muted">{{ __('Reference month') }}: <strong>{{ $filter['month'] }}</strong></div>
                    </div>

                    @if (count($rows) === 0)
                        <div class="alert alert-warning mb-0">
                            {{ __('No payslip records for this month. Generate or run payroll for this period first.') }}
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle" style="font-size: 11px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Emp. ID') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('NUIT') }}</th>
                                        <th class="text-center">{{ __('Dep.') }}</th>
                                        <th class="text-center d-print-none" style="width: 3rem;">{{ __('PDF') }}</th>
                                        <th class="text-end">{{ __('Basic') }}</th>
                                        <th class="text-end">{{ __('Allow.') }}</th>
                                        <th class="text-end">{{ __('Comm.') }}</th>
                                        <th class="text-end">{{ __('OT') }}</th>
                                        <th class="text-end">{{ __('Other') }}</th>
                                        <th class="text-end">{{ __('Σ earn.') }}</th>
                                        <th class="text-end">{{ __('Loan') }}</th>
                                        <th class="text-end">{{ __('Sat.') }}</th>
                                        <th class="text-end">{{ __('INSS emp') }}</th>
                                        <th class="text-end">{{ __('IRPS') }}</th>
                                        <th class="text-end">{{ __('Gross*') }}</th>
                                        <th class="text-end">{{ __('Net') }}</th>
                                        <th class="text-end">{{ __('INSS er') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $r)
                                        <tr>
                                            <td>{{ $r['employee_id'] }}</td>
                                            <td>{{ $r['name'] }}</td>
                                            <td>{{ $r['tax_payer_id'] }}</td>
                                            <td class="text-center">{{ $r['dependents'] }}</td>
                                            <td class="text-center d-print-none">
                                                <a href="{{ route('monthly.reports.irps-modelo-22', ['month' => $filter['month'], 'download' => 'pdf', 'payslip_id' => $r['payslip_id']]) }}"
                                                    class="btn btn-sm btn-outline-primary py-0 px-2"
                                                    data-bs-toggle="tooltip"
                                                    title="{{ __('Download PDF (this employee)') }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer">
                                                    <i class="ti ti-file"></i>
                                                </a>
                                            </td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['basic']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['allowance']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['commission']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['overtime']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['other_payment']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['total_earnings_components']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['loan']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['saturation']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['inss_employee']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['irps']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['gross_irps_base']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['net']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($r['inss_employer']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="fw-bold table-secondary">
                                        <td colspan="4" class="d-none d-print-table-cell">{{ __('TOTAL') }}</td>
                                        <td colspan="5" class="d-print-none">{{ __('TOTAL') }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['basic']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['allowance']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['commission']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['overtime']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['other_payment']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['total_earnings_components']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['loan']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['saturation']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['inss_employee']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['irps']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['gross_irps_base']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['net']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['inss_employer']) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p class="small text-muted mb-0 mt-2">
                            * {{ __('Gross (IRPS base)') }} = {{ __('Basic') }} + {{ __('Allowances') }} + {{ __('Commission') }} + {{ __('Overtime') }} + {{ __('Other payments') }}
                            ({{ __('before loans and other deductions') }}).
                            {{ __('IRPS column: stored payslip withholding amount.') }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
