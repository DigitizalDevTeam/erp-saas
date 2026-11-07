@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Dashboard') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Dashboard') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('treasury-reports.index') }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-report"></i> {{ __('Reports') }}
        </a>
        <a href="{{ route('treasury-audit-trail.index') }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-list"></i> {{ __('Audit Trail') }}
        </a>
    </div>
@endsection

@section('content')
    @php $cur = $dashboard['base_currency']; @endphp
    <div class="row">
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <small class="text-muted">{{ __('Posted balance (base)') }}</small>
                <div><strong>{{ number_format((float) $dashboard['total_posted_base'], 2) }} {{ $cur }}</strong></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <small class="text-muted">{{ __('Available balance (base)') }}</small>
                <div><strong>{{ number_format((float) $dashboard['total_available_base'], 2) }} {{ $cur }}</strong></div>
                <small class="text-muted">{{ __('Reserved') }} {{ number_format((float) $dashboard['total_reserved_base'], 2) }}</small>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <small class="text-muted">{{ __('Expected receipts — 30 days') }}</small>
                <div class="text-success"><strong>{{ number_format((float) $dashboard['expected'][30]['inflow'], 2) }} {{ $cur }}</strong></div>
                <small class="text-muted">{{ __('7 days') }} {{ number_format((float) $dashboard['expected'][7]['inflow'], 2) }} · {{ __('15 days') }} {{ number_format((float) $dashboard['expected'][15]['inflow'], 2) }}</small>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <small class="text-muted">{{ __('Expected payments — 30 days') }}</small>
                <div class="text-danger"><strong>{{ number_format((float) $dashboard['expected'][30]['outflow'], 2) }} {{ $cur }}</strong></div>
                <small class="text-muted">{{ __('7 days') }} {{ number_format((float) $dashboard['expected'][7]['outflow'], 2) }} · {{ __('15 days') }} {{ number_format((float) $dashboard['expected'][15]['outflow'], 2) }}</small>
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <h5>{{ __('Balances by account') }}</h5>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Posted') }}</th><th>{{ __('Available') }}</th></tr></thead>
                    <tbody>
                    @forelse ($dashboard['accounts'] as $account)
                        <tr>
                            <td>{{ $account['code'] }}@if ($account['holder'] !== '') — {{ $account['holder'] }}@endif</td>
                            <td>{{ $account['currency'] }}</td>
                            <td>{{ number_format((float) $account['posted'], 2) }}</td>
                            <td>{{ number_format((float) $account['available'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">{{ __('No active Treasury accounts yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>

                <h5 class="mt-4">{{ __('30-day forecast') }}</h5>
                <p>
                    {{ __('Opening') }} <strong>{{ number_format((float) $dashboard['forecast_30']['opening_balance'], 2) }} {{ $cur }}</strong> ·
                    {{ __('Inflow') }} <span class="text-success">{{ number_format((float) $dashboard['forecast_30']['inflow'], 2) }}</span> ·
                    {{ __('Outflow') }} <span class="text-danger">{{ number_format((float) $dashboard['forecast_30']['outflow'], 2) }}</span> ·
                    {{ __('Closing') }} <strong>{{ number_format((float) $dashboard['forecast_30']['closing'], 2) }} {{ $cur }}</strong>
                </p>
                @if ($dashboard['forecast_30']['first_day_below_threshold'])
                    <p class="text-danger">{{ __('Forecast falls below the threshold on') }} {{ $dashboard['forecast_30']['first_day_below_threshold']['date'] }} ({{ number_format((float) $dashboard['forecast_30']['first_day_below_threshold']['closing'], 2) }} {{ $cur }}).</p>
                @else
                    <p class="text-muted">{{ __('Forecast stays above the threshold for the next 30 days.') }}</p>
                @endif
            </div></div>
        </div>

        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <h5>{{ __('Overdue') }}</h5>
                <p>
                    {{ __('Overdue receipts') }}: <strong>{{ $dashboard['overdue_receipts']['count'] }}</strong> · {{ number_format((float) $dashboard['overdue_receipts']['amount_base'], 2) }} {{ $cur }}<br>
                    {{ __('Overdue payments') }}: <strong>{{ $dashboard['overdue_payments']['count'] }}</strong> · {{ number_format((float) $dashboard['overdue_payments']['amount_base'], 2) }} {{ $cur }}
                </p>

                <h5 class="mt-4">{{ __('Pending approvals') }}</h5>
                <p>
                    {{ __('Payment requests') }}: <strong>{{ $dashboard['pending_approvals']['payment_requests']['count'] }}</strong> · {{ number_format((float) $dashboard['pending_approvals']['payment_requests']['amount_base'], 2) }} {{ $cur }}<br>
                    {{ __('Advances') }}: <strong>{{ $dashboard['pending_approvals']['advances']['count'] }}</strong> · {{ number_format((float) $dashboard['pending_approvals']['advances']['amount_base'], 2) }} {{ $cur }}<br>
                    {{ __('Cash variances awaiting approval') }}: <strong>{{ $dashboard['pending_approvals']['cash_variances'] }}</strong>
                </p>

                <h5 class="mt-4">{{ __('Advances outstanding') }}</h5>
                <p>
                    {{ __('Open advances') }}: <strong>{{ $dashboard['advances']['count'] }}</strong> ·
                    {{ __('Outstanding') }} <strong>{{ number_format((float) $dashboard['advances']['total_base'], 2) }} {{ $cur }}</strong> ·
                    {{ __('Overdue') }}: <strong>{{ $dashboard['advances']['overdue_count'] }}</strong>
                </p>
                @if (! empty($dashboard['advances']['by_recipient']))
                    <div class="table-responsive"><table class="table">
                        <thead><tr><th>{{ __('Recipient') }}</th><th>{{ __('Advances') }}</th><th>{{ __('Outstanding') }}</th></tr></thead>
                        <tbody>
                        @foreach ($dashboard['advances']['by_recipient'] as $row)
                            <tr><td>{{ $row['recipient'] }}</td><td>{{ $row['count'] }}</td><td>{{ number_format((float) $row['outstanding_base'], 2) }} {{ $cur }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif

                <h5 class="mt-4">{{ __('Alerts') }}</h5>
                <p>
                    {{ __('Active alerts') }}: <strong>{{ $dashboard['alerts']['total'] }}</strong> ·
                    {{ __('Critical') }}: <strong>{{ $dashboard['alerts']['critical'] }}</strong>
                    @foreach ($dashboard['alerts']['by_type'] as $type => $count)
                        <br>{{ $type }}: {{ $count }}
                    @endforeach
                </p>

                <h5 class="mt-4">{{ __('Other') }}</h5>
                <p>
                    {{ __('Funds in transit') }}: <strong>{{ $dashboard['funds_in_transit']['count'] }}</strong> · {{ number_format((float) $dashboard['funds_in_transit']['amount_base'], 2) }} {{ $cur }}<br>
                    {{ __('Cash sessions open') }}: <strong>{{ $dashboard['cash_sessions_open'] }}</strong>
                </p>
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card"><div class="card-body">
                <h5>{{ __('Bank reconciliation state') }}</h5>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>{{ __('Bank') }}</th><th>{{ __('Period') }}</th><th>{{ __('Status') }}</th><th>{{ __('Statement') }}</th><th>{{ __('Book') }}</th><th>{{ __('Difference') }}</th><th>{{ __('Matched') }}</th></tr></thead>
                    <tbody>
                    @forelse ($dashboard['reconciliation'] as $session)
                        <tr>
                            <td>{{ $session['bank'] }}</td>
                            <td>{{ $session['period_from'] }} → {{ $session['period_to'] }}</td>
                            <td>{{ $session['status'] }}</td>
                            <td>{{ number_format((float) $session['statement_balance'], 2) }}</td>
                            <td>{{ number_format((float) $session['book_balance'], 2) }}</td>
                            <td>{{ number_format((float) $session['difference'], 2) }}</td>
                            <td>{{ $session['matched_lines'] }} / {{ $session['total_lines'] }}@if ($session['matched_pct'] !== null) ({{ $session['matched_pct'] }}%)@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">{{ __('No reconciliation sessions yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div></div>
        </div>
    </div>
@endsection
