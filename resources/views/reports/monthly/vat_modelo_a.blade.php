@extends('layouts.admin')

@section('page-title')
    {{ __('VAT Report (Modelo A — Periodic declaration)') }}
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
        <div class="col-md-11">
            <div class="card d-none" id="monthly_report_filter">
                <div class="card-body">
                    {{ Form::open(['route' => ['monthly.reports.vat-modelo-a'], 'method' => 'GET']) }}
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                            {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                            {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('manual_06', '06', ['class' => 'form-label']) }}
                            {{ Form::text('manual_06', $manualInputs['manual_06'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('manual_11', '11', ['class' => 'form-label']) }}
                            {{ Form::text('manual_11', $manualInputs['manual_11'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('manual_12', '12', ['class' => 'form-label']) }}
                            {{ Form::text('manual_12', $manualInputs['manual_12'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('manual_13', '13', ['class' => 'form-label']) }}
                            {{ Form::text('manual_13', $manualInputs['manual_13'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('credits_communicated', '19', ['class' => 'form-label']) }}
                            {{ Form::text('credits_communicated', $manualInputs['credits_communicated'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('t9_additional_pay', __('Tabela 9 — pay'), ['class' => 'form-label']) }}
                            {{ Form::text('t9_additional_pay', $manualInputs['t9_additional_pay'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('t9_additional_recover', __('Tabela 9 — recover'), ['class' => 'form-label']) }}
                            {{ Form::text('t9_additional_recover', $manualInputs['t9_additional_recover'] ?? '', ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-12">
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
        <div class="col-md-11">
            <div class="card">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="fw-bold" style="font-size: 16px;">{{ __('MODELO A — Periodic VAT declaration') }}</div>
                        <div style="font-size: 12px;">{{ __('Table 8') }}</div>
                        <div style="font-size: 12px;">
                            {{ __('Period') }}: {{ $filter['startDateRange'] }} {{ __('to') }} {{ $filter['endDateRange'] }}
                        </div>
                    </div>

                    @isset($modeloANotes)
                        <style>
                            @media print {
                                .no-print {
                                    display: none !important;
                                }
                            }
                        </style>
                        <div class="alert alert-light border small text-start mb-3 no-print">
                            <div class="fw-semibold mb-2">{{ __('Notes') }}</div>
                            <ul class="mb-2 ps-3">
                                <li>{{ __('Sales: invoice lines in period (by send date, or issue date if send date is empty). Draft invoices are included.') }}</li>
                                <li>{{ __('Purchases: bill lines in period (bill date).') }}</li>
                                <li>{{ __('IVA column: tax named') }} <code>vat_tax_name</code> ({{ $modeloANotes['iva_tax_name'] }})
                                    @if (!($modeloANotes['iva_tax_matched'] ?? false))
                                        <span class="text-danger">{{ __('— no tax with this name found; any positive rate on the line still counts in 01–02.') }}</span>
                                    @else
                                        <span class="text-success">{{ __('— matched') }}</span>
                                    @endif
                                </li>
                                <li>{{ __('ICMS column: tax named') }} <code>modelo_a_icms_tax_name</code> ({{ $modeloANotes['icms_tax_name'] }})
                                    @if (!($modeloANotes['icms_tax_matched'] ?? false))
                                        <span class="text-muted">{{ __('— not found; rate :r% used as fallback.', ['r' => $modeloANotes['rates']['icms'] ?? '']) }}</span>
                                    @else
                                        <span class="text-success">{{ __('— matched') }}</span>
                                    @endif
                                </li>
                                <li>{{ __('Manual fields: 06, 11–13, 19 and Table 9 use the filter form.') }}</li>
                            </ul>
                            <div class="fw-semibold mb-1">{{ __('Diagnostics (this run)') }}</div>
                            <ul class="mb-0 ps-3">
                                <li>{{ __('Invoice lines in date range') }}: <strong>{{ $modeloANotes['stats']['invoice_lines_in_period'] ?? 0 }}</strong>
                                    — {{ __('skipped (no tax on line and no product tax)') }}: <strong>{{ $modeloANotes['stats']['invoice_lines_no_tax'] ?? 0 }}</strong></li>
                                <li>{{ __('Bill lines in date range') }}: <strong>{{ $modeloANotes['stats']['bill_lines_in_period'] ?? 0 }}</strong>
                                    — {{ __('skipped (no tax)') }}: <strong>{{ $modeloANotes['stats']['bill_lines_no_tax'] ?? 0 }}</strong></li>
                            </ul>
                        </div>
                    @endisset

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle" style="font-size: 12px;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 8%;">{{ __('Field') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th class="text-end" style="width: 18%;">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="fw-bold">01</td>
                                    <td>{{ __('Taxable base — VAT') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[1]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">02</td>
                                    <td>{{ __('VAT charged') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[2]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">03</td>
                                    <td>{{ __('Taxable base — ICMS') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[3]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">04</td>
                                    <td>{{ __('ICMS collected') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[4]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">05</td>
                                    <td>{{ __('Exempt sales with right to deduction') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[5]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">06</td>
                                    <td>{{ __('ICMS on import — not deductible') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[6]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">07</td>
                                    <td>{{ __('Exempt sales without right to deduction') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[7]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">08</td>
                                    <td>{{ __('Deductible tax — fixed assets') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[8]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">09</td>
                                    <td>{{ __('Deductible tax — inventory') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[9]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">10</td>
                                    <td>{{ __('Deductible tax — other goods and services') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[10]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">11</td>
                                    <td>{{ __('Deductible VAT on imports') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[11]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">12</td>
                                    <td>{{ __('Adjustments — taxpayer') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[12]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">13</td>
                                    <td>{{ __('Adjustments — State') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[13]) }}</td>
                                </tr>
                                <tr class="table-secondary">
                                    <td class="fw-bold">14</td>
                                    <td>{{ __('Total bases') }}</td>
                                    <td class="text-end fw-bold">{{ \Auth::user()->priceFormat($c[14]) }}</td>
                                </tr>
                                <tr class="table-secondary">
                                    <td class="fw-bold">15</td>
                                    <td>{{ __('Total deductions') }}</td>
                                    <td class="text-end fw-bold">{{ \Auth::user()->priceFormat($c[15]) }}</td>
                                </tr>
                                <tr class="table-secondary">
                                    <td class="fw-bold">16</td>
                                    <td>{{ __('Total charges') }}</td>
                                    <td class="text-end fw-bold">{{ \Auth::user()->priceFormat($c[16]) }}</td>
                                </tr>
                                <tr class="{{ $c[17] > 0 ? 'table-warning' : '' }}">
                                    <td class="fw-bold">17</td>
                                    <td>{{ __('VAT payable to the State') }}</td>
                                    <td class="text-end fw-bold">{{ \Auth::user()->priceFormat($c[17]) }}</td>
                                </tr>
                                <tr class="{{ $c[18] > 0 ? 'table-info' : '' }}">
                                    <td class="fw-bold">18</td>
                                    <td>{{ __('Credit in favour of the taxpayer') }}</td>
                                    <td class="text-end fw-bold">{{ \Auth::user()->priceFormat($c[18]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">19</td>
                                    <td>{{ __('Credits communicated') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($c[19]) }}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">20</td>
                                    <td>{{ __('Carry forward') }}</td>
                                    <td class="text-end fw-bold">{{ \Auth::user()->priceFormat($c[20]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        <div class="fw-bold mb-2" style="font-size: 14px;">{{ __('Table 9') }}</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm" style="font-size: 12px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Description') }}</th>
                                        <th class="text-end" style="width: 22%;">{{ __('Amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>{{ __('Additional tax to pay') }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($tabela9['additional_pay']) }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ __('Additional amount to recover') }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($tabela9['additional_recover']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
