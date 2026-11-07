@extends('layouts.admin')
@section('page-title')
    {{ __('Cash-Flow Alerts') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-flow.index') }}">{{ __('Cash Flow') }}</a></li>
    <li class="breadcrumb-item">{{ __('Alerts') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-cash-flow-alerts.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryCashFlowAlert::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst($state) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="type" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All types') }}</option>
                                @foreach (\App\Models\TreasuryCashFlowAlert::TYPES as $type)
                                    <option value="{{ $type }}" {{ request('type') === $type ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($alerts->isEmpty())
                        <p class="text-muted">{{ __('No alerts. Alerts are recomputed by the daily forecast job and when the cash-flow screen loads.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Severity') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Alert') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Occurred') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($alerts as $alert)
                                        <tr>
                                            <td><span class="badge bg-{{ $alert->severity === 'critical' ? 'danger' : ($alert->severity === 'warning' ? 'warning' : 'info') }}">{{ ucfirst($alert->severity) }}</span></td>
                                            <td>{{ ucfirst(str_replace('_', ' ', $alert->type)) }}</td>
                                            <td>
                                                <strong>{{ $alert->title }}</strong>
                                                <div class="text-muted"><small>{{ $alert->detail }}</small></div>
                                            </td>
                                            <td>{{ ucfirst($alert->state) }}</td>
                                            <td>{{ $alert->occurred_at ? $alert->occurred_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td>
                                                @if ($alert->state === 'active')
                                                    <form method="POST" action="{{ route('treasury-cash-flow-alerts.acknowledge', $alert->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Acknowledge') }}</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $alerts->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
