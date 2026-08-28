@extends('layouts.admin')

@section('page-title')
    {{ __('VAT (Modelo A IVA)') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Monthly Reports') }}</li>
    <li class="breadcrumb-item">{{ __('VAT (Modelo A IVA)') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-body">
                    {{ Form::open(['route' => ['monthly.reports.vat-modelo-a'], 'method' => 'GET', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-md-2">
                        {{ Form::label('month', __('Month'), ['class' => 'form-label']) }}
                        {{ Form::selectMonth('month', $period['month'], ['class' => 'form-select']) }}
                    </div>
                    <div class="col-md-2">
                        {{ Form::label('year', __('Year'), ['class' => 'form-label']) }}
                        {{ Form::number('year', $period['year'], ['class' => 'form-control', 'min' => 2000, 'max' => 2100]) }}
                    </div>
                    <div class="col-md-3">
                        {{ Form::label('inside_deadline', __('Deadline status'), ['class' => 'form-label d-block']) }}
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="inside_deadline" id="deadlineInside" value="1"
                                {{ $insideDeadline ? 'checked' : '' }}>
                            <label class="form-check-label" for="deadlineInside">{{ __('Inside deadline') }}</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="inside_deadline" id="deadlineOutside" value="0"
                                {{ !$insideDeadline ? 'checked' : '' }}>
                            <label class="form-check-label" for="deadlineOutside">{{ __('Outside deadline') }}</label>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="row g-2">
                            <div class="col-4">
                                {{ Form::label('manual_03', '03', ['class' => 'form-label']) }}
                                {{ Form::text('manual_03', $manualInputs['manual_03'] ?? '', ['class' => 'form-control']) }}
                            </div>
                            <div class="col-4">
                                {{ Form::label('manual_04', '04', ['class' => 'form-label']) }}
                                {{ Form::text('manual_04', $manualInputs['manual_04'] ?? '', ['class' => 'form-control']) }}
                            </div>
                            <div class="col-4">
                                {{ Form::label('manual_08', '08', ['class' => 'form-label']) }}
                                {{ Form::text('manual_08', $manualInputs['manual_08'] ?? '', ['class' => 'form-control']) }}
                            </div>
                            <div class="col-4">
                                {{ Form::label('manual_09', '09', ['class' => 'form-label']) }}
                                {{ Form::text('manual_09', $manualInputs['manual_09'] ?? '', ['class' => 'form-control']) }}
                            </div>
                            <div class="col-4">
                                {{ Form::label('manual_10', '10', ['class' => 'form-label']) }}
                                {{ Form::text('manual_10', $manualInputs['manual_10'] ?? '', ['class' => 'form-control']) }}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        {{ Form::label('manual_16', '16', ['class' => 'form-label']) }}
                        {{ Form::text('manual_16', $manualInputs['manual_16'] ?? '', ['class' => 'form-control']) }}
                    </div>
                    <div class="col-md-2">
                        {{ Form::label('manual_19', '19', ['class' => 'form-label']) }}
                        {{ Form::text('manual_19', $manualInputs['manual_19'] ?? '', ['class' => 'form-control']) }}
                    </div>
                    <div class="col-md-8">
                        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        <a href="{{ route('monthly.reports.vat-modelo-a') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                        <a href="{{ route('monthly.reports.vat-modelo-a.export', request()->query()) }}" class="btn btn-success">
                            <i class="ti ti-file-spreadsheet"></i> {{ __('Export Modelo A (Excel)') }}
                        </a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>

            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between">
                    <strong>{{ __('VAT Modelo A') }}</strong>
                    <span class="text-muted small">{{ __('Period') }}: {{ $period['start'] }} → {{ $period['end'] }}</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4"><strong>{{ __('Company') }}:</strong> {{ $company['name'] }}</div>
                        <div class="col-md-4"><strong>{{ __('NUIT') }}:</strong> {{ $company['nuit'] }}</div>
                        <div class="col-md-4"><strong>{{ __('Address') }}:</strong> {{ $company['address'] }}</div>
                    </div>

                    <p class="text-muted small mb-2">
                        {{ __('Fields 02 and 05–07 show operational VAT for the month (aligned with Balancete Debit/Credit columns). Internal VAT settlement journals to 4435 are excluded.') }}
                    </p>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm" style="font-size: 12px;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 12%">{{ __('Field') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th class="text-end" style="width: 20%">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>01</td><td>{{ __('Sales (71/72)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f01']) }}</td></tr>
                                <tr><td>03</td><td>{{ __('Exempt sales (manual)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f03']) }}</td></tr>
                                <tr><td>04</td><td>{{ __('Other exempt operations (manual)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f04']) }}</td></tr>
                                <tr><td>02</td><td>{{ __('VAT collected (44331)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f02']) }}</td></tr>
                                <tr><td>05</td><td>{{ __('VAT on assets (44322)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f05']) }}</td></tr>
                                <tr><td>06</td><td>{{ __('VAT on inventory (44321)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f06']) }}</td></tr>
                                <tr><td>07</td><td>{{ __('Other VAT (44323)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f07']) }}</td></tr>
                                <tr><td>08</td><td>{{ __('Import VAT (manual)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f08']) }}</td></tr>
                                <tr><td>09</td><td>{{ __('Adjustments in favor of taxpayer') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f09']) }}</td></tr>
                                <tr><td>10</td><td>{{ __('Adjustments in favor of State') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f10']) }}</td></tr>
                                <tr class="table-secondary"><td>12</td><td>{{ __('Input VAT (5+6+7+8+9)') }}</td><td class="text-end fw-bold">{{ \Auth::user()->priceFormat($report['f12']) }}</td></tr>
                                <tr class="table-secondary"><td>13</td><td>{{ __('Output VAT (2+10)') }}</td><td class="text-end fw-bold">{{ \Auth::user()->priceFormat($report['f13']) }}</td></tr>
                                <tr><td>11</td><td>{{ __('Somatório dos campos (1+3+4)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f11']) }}</td></tr>
                                <tr><td>14</td><td>{{ __('VAT payable (if output > input)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f14']) }}</td></tr>
                                <tr><td>15</td><td>{{ __('VAT recoverable (if input > output)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f15']) }}</td></tr>
                                <tr><td>16</td><td>{{ __('Credits from previous period') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f16']) }}</td></tr>
                                <tr><td>17</td><td>{{ __('Recoverable after credit use') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f17']) }}</td></tr>
                                <tr><td>18</td><td>{{ __('VAT to deliver (main line)') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f18']) }}</td></tr>
                                <tr><td>19</td><td>{{ __('Interest / penalties') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f19']) }}</td></tr>
                                <tr class="table-secondary"><td>20</td><td>{{ __('Total payable (18+19)') }}</td><td class="text-end fw-bold">{{ \Auth::user()->priceFormat($report['f20']) }}</td></tr>
                                <tr><td>21</td><td>{{ __('Credit to carry forward') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($report['f21']) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

