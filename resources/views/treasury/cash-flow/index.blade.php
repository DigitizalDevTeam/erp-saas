@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Cash Flow') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Cash Flow') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('treasury-cash-flow.projections') }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-plus"></i> {{ __('Projections') }}
        </a>
        <a href="{{ route('treasury-cash-flow-alerts.index') }}" class="btn btn-sm btn-warning">
            <i class="ti ti-bell"></i> {{ __('Alerts') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-cash-flow.index') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <select name="days" class="form-control" onchange="this.form.submit()">
                                @foreach ([7, 15, 30, 90] as $option)
                                    <option value="{{ $option }}" {{ (int) $days === $option ? 'selected' : '' }}>{{ $option }} {{ __('days') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="group" class="form-control" onchange="this.form.submit()">
                                @foreach (['day' => __('By day'), 'week' => __('By week'), 'month' => __('By month')] as $value => $label)
                                    <option value="{{ $value }}" {{ $group === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="scenario" class="form-control" onchange="this.form.submit()">
                                @foreach (['baseline' => __('Baseline'), 'late_receipts' => __('Late receipts (+7 days)'), 'higher_expenses' => __('Higher expenses (+10%)'), 'earlier_payments' => __('Earlier payments (−7 days)')] as $value => $label)
                                    <option value="{{ $value }}" {{ $scenario === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <small class="text-muted">{{ __('Opening balance') }}</small>
                            <div><strong>{{ number_format((float) $calendar['opening_balance'], 2) }} {{ $baseCurrency }}</strong></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">{{ __('Forecast inflow') }}</small>
                            <div class="text-success"><strong>{{ number_format((float) $calendar['totals']['inflow'], 2) }} {{ $baseCurrency }}</strong></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">{{ __('Forecast outflow') }}</small>
                            <div class="text-danger"><strong>{{ number_format((float) $calendar['totals']['outflow'], 2) }} {{ $baseCurrency }}</strong></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">{{ __('Closing balance') }}</small>
                            <div><strong>{{ number_format((float) $calendar['totals']['closing'], 2) }} {{ $baseCurrency }}</strong></div>
                        </div>
                    </div>

                    @if ($firstBelow !== null)
                        <div class="alert alert-danger">
                            {{ __('Forecast closing balance falls below the threshold of :threshold :currency on :date (closing :closing :currency).', ['threshold' => number_format((float) $threshold, 2), 'currency' => $baseCurrency, 'date' => $firstBelow['date'], 'closing' => number_format((float) $firstBelow['closing'], 2)]) }}
                        </div>
                    @endif

                    @if (empty($calendar['periods']))
                        <p class="text-muted">{{ __('No forecast movements in this horizon.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Period') }}</th>
                                        <th class="text-end">{{ __('Inflow') }}</th>
                                        <th class="text-end">{{ __('Outflow') }}</th>
                                        <th class="text-end">{{ __('Closing') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($calendar['periods'] as $row)
                                        <tr class="{{ $row['below_threshold'] ? 'table-danger' : '' }}">
                                            <td>{{ $row['label'] }}</td>
                                            <td class="text-end text-success">{{ number_format((float) $row['inflow'], 2) }}</td>
                                            <td class="text-end text-danger">{{ number_format((float) $row['outflow'], 2) }}</td>
                                            <td class="text-end"><strong>{{ number_format((float) $row['closing'], 2) }}</strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <p class="text-muted"><small>{{ __('Forecast and actual are shown separately: the table above is the forecast. Confirmed movements below are actuals, already counted in the opening balance.') }}</small></p>

                    @if (! empty($calendar['actuals']['items']))
                        <h5 class="mt-4">{{ __('Actual movements in this horizon') }}</h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Date') }}</th>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($calendar['actuals']['items'] as $actual)
                                        <tr>
                                            <td>{{ $actual['date'] }}</td>
                                            <td>{{ $actual['reference'] }}</td>
                                            <td>{{ ucfirst(str_replace('_', ' ', $actual['type'])) }}</td>
                                            <td class="text-end {{ $actual['direction'] === 'inflow' ? 'text-success' : 'text-danger' }}">{{ $actual['direction'] === 'inflow' ? '+' : '−' }}{{ number_format((float) $actual['amount_base'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($activeAlerts->isNotEmpty())
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Active alerts') }}</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            @foreach ($activeAlerts as $alert)
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge bg-{{ $alert->severity === 'critical' ? 'danger' : ($alert->severity === 'warning' ? 'warning' : 'info') }}">{{ ucfirst($alert->severity) }}</span>
                                        <strong>{{ $alert->title }}</strong>
                                        <div class="text-muted"><small>{{ $alert->detail }}</small></div>
                                    </div>
                                    <a href="{{ route('treasury-cash-flow-alerts.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('View') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
