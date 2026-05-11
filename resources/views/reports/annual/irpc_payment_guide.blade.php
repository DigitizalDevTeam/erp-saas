@extends('layouts.admin')

@section('page-title')
    {{ __('IRPC Payment Guide') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('IRPC Payment Guide') }}</li>
@endsection

@php
    $profitLossUrl = route('report.profit.loss', [
        'start_date' => $reportData['period_start'],
        'end_date' => $reportData['period_end'],
    ]);
    $trialBalanceUrl = route('trial.balance', [
        'start_date' => $reportData['period_start'],
        'end_date' => $reportData['period_end'],
    ]);
    $dreUrl = route('report.dre.comparativa', [
        'year1' => $reportData['fiscal_year'],
        'year2' => $reportData['previous_fiscal_year'],
    ]);
@endphp

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('annual.reports.irpc-payment-guide', ['year' => $reportData['fiscal_year'], 'download' => 'pdf']) }}"
            class="btn btn-sm btn-primary">
            <i class="ti ti-download"></i> {{ __('Download PDF') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-body">
                    {{ Form::open(['route' => ['annual.reports.irpc-payment-guide'], 'method' => 'GET', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-md-3">
                        {{ Form::label('year', __('Fiscal year'), ['class' => 'form-label']) }}
                        {{ Form::number('year', $reportData['fiscal_year'], ['class' => 'form-control', 'min' => 2000, 'max' => 2100]) }}
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        <a href="{{ route('annual.reports.irpc-payment-guide') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                    </div>
                    <div class="col-md-auto ms-md-auto d-flex flex-wrap gap-2">
                        <a href="{{ $dreUrl }}" class="btn btn-outline-info">{{ __('Open DRE') }}</a>
                        <a href="{{ $profitLossUrl }}" class="btn btn-outline-info">{{ __('Open Profit & Loss') }}</a>
                        <a href="{{ $trialBalanceUrl }}" class="btn btn-outline-info">{{ __('Open Trial Balance') }}</a>
                    </div>
                    {{ Form::close() }}
                    <p class="small text-muted mb-0 mt-2">
                        {{ __('This annual IRPC guide is calculated directly from accounting statements for the selected fiscal year.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">{{ __('Total revenue') }}</div>
                    <div class="fs-4 fw-bold">{{ \Auth::user()->priceFormat($reportData['total_revenue']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">{{ __('Expenses before tax') }}</div>
                    <div class="fs-4 fw-bold">{{ \Auth::user()->priceFormat($reportData['total_expenses_before_tax']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">{{ __('IRPC payable') }}</div>
                    <div class="fs-4 fw-bold">{{ \Auth::user()->priceFormat($reportData['net_payable']) }}</div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Company Details') }}</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('Company') }}</dt>
                        <dd class="col-sm-8">{{ $reportData['company_name'] ?: '-' }}</dd>

                        <dt class="col-sm-4">{{ __('NUIT') }}</dt>
                        <dd class="col-sm-8">{{ $reportData['company_nuit'] ?: '-' }}</dd>

                        <dt class="col-sm-4">{{ __('Period') }}</dt>
                        <dd class="col-sm-8">{{ $reportData['period_label'] }}</dd>

                        <dt class="col-sm-4">{{ __('Address') }}</dt>
                        <dd class="col-sm-8">
                            {{ $reportData['company_address'] ?: '-' }}
                            @if (!empty($reportData['company_city']))
                                <br>{{ $reportData['company_city'] }}
                            @endif
                        </dd>

                        <dt class="col-sm-4">{{ __('Phone') }}</dt>
                        <dd class="col-sm-8">{{ $reportData['company_phone'] ?: '-' }}</dd>

                        <dt class="col-sm-4">{{ __('Email') }}</dt>
                        <dd class="col-sm-8">{{ $reportData['company_email'] ?: '-' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Accounting Integration') }}</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <tbody>
                                <tr>
                                    <th>{{ __('Accounting source') }}</th>
                                    <td>{{ __('Comparative DRE + Trial Balance') }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Fiscal year') }}</th>
                                    <td>{{ $reportData['fiscal_year'] }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Trial balance period') }}</th>
                                    <td>{{ $reportData['source_report']['trial_balance']['start_date'] }} - {{ $reportData['source_report']['trial_balance']['end_date'] }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('DRE comparison years') }}</th>
                                    <td>{{ $reportData['source_report']['dre']['year1'] }} / {{ $reportData['source_report']['dre']['year2'] }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Configured IRPC rate') }}</th>
                                    <td>{{ number_format($reportData['irpc_rate_percent'], 2) }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('IRPC Calculation Summary') }}</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Line') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td>{{ __('Revenue from accounting statements') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['total_revenue']) }}</td>
                                </tr>
                                <tr>
                                    <td>2</td>
                                    <td>{{ __('Operating costs') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['operating_costs']) }}</td>
                                </tr>
                                <tr>
                                    <td>3</td>
                                    <td>{{ __('Financial costs') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['financial_costs']) }}</td>
                                </tr>
                                <tr>
                                    <td>4</td>
                                    <td>{{ __('Extraordinary costs') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['extraordinary_costs']) }}</td>
                                </tr>
                                <tr>
                                    <td>5</td>
                                    <td>{{ __('Accounting profit before tax') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['accounting_profit_before_tax']) }}</td>
                                </tr>
                                <tr>
                                    <td>6</td>
                                    <td>{{ __('Taxable income') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['taxable_income']) }}</td>
                                </tr>
                                <tr>
                                    <td>7</td>
                                    <td>{{ __('IRPC rate') }}</td>
                                    <td class="text-end">{{ number_format($reportData['irpc_rate_percent'], 2) }}%</td>
                                </tr>
                                <tr>
                                    <td>8</td>
                                    <td>{{ __('IRPC payable') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['irpc_payable']) }}</td>
                                </tr>
                                <tr>
                                    <td>9</td>
                                    <td>{{ __('Prior payments / adjustments') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['prior_payments']) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="2">{{ __('Net amount payable') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($reportData['net_payable']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="small text-muted mb-0">
                        {{ __('Taxable income is currently derived from accounting profit before tax for the selected fiscal year. No additional tax adjustments or prior payments are applied in this first version.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
