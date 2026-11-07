@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen Cost Alerts') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.cost-control.index') }}">{{ __('Cost Control') }}</a></li>
    <li class="breadcrumb-item">{{ __('Alerts') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <form method="POST" action="{{ route('kitchen.cost-alerts.refresh') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="ti ti-refresh"></i> {{ __('Refresh dish alerts') }}
            </button>
        </form>
    </div>
@endsection

@section('content')
    @php
        $bandBadge = [
            'excellent' => 'bg-success',
            'good' => 'bg-primary',
            'good-' => 'bg-info',
            'bad' => 'bg-warning',
            'very_bad' => 'bg-danger',
        ];
    @endphp

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($openCount > 0)
                        <div class="alert alert-warning">
                            {{ __(':count open alert(s). A dish or order is in a bad or very bad food-cost band, or an order came out worse than planned.', ['count' => $openCount]) }}
                        </div>
                    @endif
                    <p class="text-muted">{{ __('Order alerts fire when an order completes. Dish alerts are raised by the Refresh button above, from the recipe cost at today\'s ingredient prices.') }}</p>

                    <form method="GET" action="{{ route('kitchen.cost-alerts.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <select name="alert_type" class="form-control">
                                <option value="">{{ __('All types') }}</option>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" {{ ($filters['alert_type'] ?? '') === $type ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Dish') }}</th>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Food cost %') }}</th>
                                <th>{{ __('Band') }}</th>
                                <th>{{ __('Planned band') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($alerts as $alert)
                                <tr>
                                    <td>{{ ucfirst(str_replace('_', ' ', $alert->alert_type)) }}</td>
                                    <td>{{ $alert->product ? $alert->product->name : '-' }}</td>
                                    <td>{{ $alert->order ? ($alert->order->order_number ?? ('#'.$alert->order->id)) : '-' }}</td>
                                    <td>{{ $alert->food_cost_percent !== null ? number_format((float) $alert->food_cost_percent, 2).'%' : '-' }}</td>
                                    <td>
                                        @if ($alert->band)
                                            <span class="badge {{ $bandBadge[$alert->band] ?? 'bg-secondary' }}">{{ $bandLabel($alert->band) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if ($alert->planned_food_cost_band)
                                            <span class="badge {{ $bandBadge[$alert->planned_food_cost_band] ?? 'bg-secondary' }}">{{ $bandLabel($alert->planned_food_cost_band) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ ucfirst($alert->status) }}</td>
                                    <td>
                                        @if ($alert->isOpen())
                                            <form method="POST" action="{{ route('kitchen.cost-alerts.acknowledge', $alert->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">{{ __('Acknowledge') }}</button>
                                            </form>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center">{{ __('No cost alerts yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $alerts->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
