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

        function updateModelo19Total() {
            const fields = ['line10_manual', 'line11', 'line12', 'line13', 'line14', 'line15', 'line16'];
            const toNumber = (v) => {
                const n = parseFloat((v || '0').toString().replace(',', '.'));
                return Number.isFinite(n) ? n : 0;
            };
            const sum = fields.reduce((acc, id) => acc + toNumber(document.getElementById(id)?.value), 0);
            const output = document.getElementById('line_total_display');
            if (output) {
                output.value = sum.toFixed(2);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateModelo19Total();
            ['line10_manual', 'line11', 'line12', 'line13', 'line14', 'line15', 'line16'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', updateModelo19Total);
                }
            });
        });
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
                        <div class="col-md-3">
                            <div class="btn-box">
                                {{ Form::label('month', __('Month'), ['class' => 'form-label']) }}
                                {{ Form::month('month', $filter['month'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="row g-2">
                                <div class="col-md-3">{{ Form::label('line10_manual', '10 - Salaries', ['class' => 'form-label']) }}{{ Form::number('line10_manual', $reportData['line10'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line10_manual']) }}</div>
                                <div class="col-md-3">{{ Form::label('line11', '11 - Pension', ['class' => 'form-label']) }}{{ Form::number('line11', $reportData['line11'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line11']) }}</div>
                                <div class="col-md-3">{{ Form::label('line12', '12 - Professional', ['class' => 'form-label']) }}{{ Form::number('line12', $reportData['line12'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line12']) }}</div>
                                <div class="col-md-3">{{ Form::label('line13', '13 - Business', ['class' => 'form-label']) }}{{ Form::number('line13', $reportData['line13'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line13']) }}</div>
                                <div class="col-md-3">{{ Form::label('line14', '14 - Games', ['class' => 'form-label']) }}{{ Form::number('line14', $reportData['line14'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line14']) }}</div>
                                <div class="col-md-3">{{ Form::label('line15', '15 - Capital gains', ['class' => 'form-label']) }}{{ Form::number('line15', $reportData['line15'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line15']) }}</div>
                                <div class="col-md-3">{{ Form::label('line16', '16 - Rental', ['class' => 'form-label']) }}{{ Form::number('line16', $reportData['line16'], ['class' => 'form-control', 'step' => '0.01', 'id' => 'line16']) }}</div>
                                <div class="col-md-3">{{ Form::label('line20', '20 - Advance payment', ['class' => 'form-label']) }}{{ Form::number('line20', $reportData['line20'], ['class' => 'form-control', 'step' => '0.01']) }}</div>
                                <div class="col-md-3">{{ Form::label('line21', '21 - Interest', ['class' => 'form-label']) }}{{ Form::number('line21', $reportData['line21'], ['class' => 'form-control', 'step' => '0.01']) }}</div>
                                <div class="col-12">
                                    <small class="text-muted">
                                        {{ __('Line 10 is auto-filled from payroll and line 12 from accounting revenues for the selected month. You can overwrite any value manually.') }}
                                    </small>
                                </div>
                            </div>
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
                    <div class="text-center mb-4">
                        <div class="fw-bold" style="font-size: 16px;">{{ __('MODELO 19 - IRPS (Monthly)') }}</div>
                        <div style="font-size: 12px;">{{ __('Reference') }}: {{ $reportData['month_year'] }}</div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6"><strong>{{ __('Company') }}:</strong> {{ $reportData['company_name'] ?: '-' }}</div>
                        <div class="col-md-6"><strong>{{ __('NUIT') }}:</strong> {{ $reportData['company_nuit'] ?: '-' }}</div>
                        <div class="col-md-12"><strong>{{ __('Address') }}:</strong> {{ $reportData['address'] ?: '-' }}</div>
                        <div class="col-md-6"><strong>{{ __('Phone') }}:</strong> {{ $reportData['phone'] ?: '-' }}</div>
                        <div class="col-md-6"><strong>{{ __('Email') }}:</strong> {{ $reportData['email'] ?: '-' }}</div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 80px;">{{ __('Code') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th class="text-end" style="width: 180px;">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>10</td><td>{{ __('Salaries') }}</td><td class="text-end">{{ number_format((float) $reportData['line10'], 2, '.', ',') }}</td></tr>
                                <tr><td>11</td><td>{{ __('Pension') }}</td><td class="text-end">{{ number_format((float) $reportData['line11'], 2, '.', ',') }}</td></tr>
                                <tr><td>12</td><td>{{ __('Professional income') }}</td><td class="text-end">{{ number_format((float) $reportData['line12'], 2, '.', ',') }}</td></tr>
                                <tr><td>13</td><td>{{ __('Business income') }}</td><td class="text-end">{{ number_format((float) $reportData['line13'], 2, '.', ',') }}</td></tr>
                                <tr><td>14</td><td>{{ __('Games income') }}</td><td class="text-end">{{ number_format((float) $reportData['line14'], 2, '.', ',') }}</td></tr>
                                <tr><td>15</td><td>{{ __('Capital gains') }}</td><td class="text-end">{{ number_format((float) $reportData['line15'], 2, '.', ',') }}</td></tr>
                                <tr><td>16</td><td>{{ __('Rental income') }}</td><td class="text-end">{{ number_format((float) $reportData['line16'], 2, '.', ',') }}</td></tr>
                                <tr class="table-secondary">
                                    <td colspan="2"><strong>{{ __('Total (10-16)') }}</strong></td>
                                    <td class="text-end"><strong><input id="line_total_display" class="form-control form-control-sm text-end" value="{{ number_format((float) $reportData['line_total'], 2, '.', '') }}" readonly></strong></td>
                                </tr>
                                <tr><td>20</td><td>{{ __('Advance payment') }}</td><td class="text-end">{{ number_format((float) $reportData['line20'], 2, '.', ',') }}</td></tr>
                                <tr><td>21</td><td>{{ __('Interest') }}</td><td class="text-end">{{ number_format((float) $reportData['line21'], 2, '.', ',') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <a href="{{ route('monthly.reports.irps-modelo-19', array_merge(request()->query(), ['download' => 'pdf'])) }}"
                            class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="{{ __('Download PDF') }}">
                            <i class="ti ti-download"></i> {{ __('Download PDF') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

