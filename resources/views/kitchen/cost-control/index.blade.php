@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen Cost Control') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Cost Control') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('kitchen.cost-alerts.index') }}" class="btn btn-sm btn-warning">
            <i class="ti ti-bell"></i> {{ __('Alerts') }} ({{ $openAlerts->count() }})
        </a>
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
            @if ($openAlerts->count() > 0)
                <div class="alert alert-danger">
                    {{ __(':count open cost alert(s): dishes or orders in a bad or very bad food-cost band. See the Alerts page.', ['count' => $openAlerts->count()]) }}
                    <a href="{{ route('kitchen.cost-alerts.index') }}" class="alert-link">{{ __('View alerts') }}</a>
                </div>
            @endif
            @if ($badDishes > 0)
                <div class="alert alert-warning">
                    {{ __(':count dish(es) are in a bad or very bad food-cost band right now (expected or actual).', ['count' => $badDishes]) }}
                </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen.cost-control.index') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <select name="group" class="form-control">
                                <option value="month" {{ ($filters['group'] ?? 'month') === 'month' ? 'selected' : '' }}>{{ __('By month') }}</option>
                                <option value="week" {{ ($filters['group'] ?? '') === 'week' ? 'selected' : '' }}>{{ __('By week') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="branch_id" class="form-control">
                                <option value="">{{ __('All branches') }}</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ (string) ($filters['branch_id'] ?? '') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="{{ __('Search dish') }}">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <h6>{{ __('Cost by dish') }}</h6>
                    <p class="text-muted">{{ __('Expected cost comes from the approved recipe at today\'s ingredient prices. Actual cost comes from the latest completed order in the period. Food cost % is cost per portion ÷ selling price excluding VAT.') }}</p>
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Dish') }}</th>
                                <th>{{ __('Recipe') }}</th>
                                <th>{{ __('Expected cost / portion') }}</th>
                                <th>{{ __('Expected food cost %') }}</th>
                                <th>{{ __('Latest order') }}</th>
                                <th>{{ __('Actual cost / portion') }}</th>
                                <th>{{ __('Actual food cost %') }}</th>
                                <th>{{ __('Orders in period') }}</th>
                                <th>{{ __('Avg food cost %') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($dishRows as $row)
                                <tr>
                                    <td>{{ $row['product']->name }}</td>
                                    <td>{{ $row['recipe']->code }} v{{ $row['recipe']->version }}</td>
                                    <td>{{ $row['expected']['cost_per_portion'] !== null ? \Auth::user()->priceFormat((float) $row['expected']['cost_per_portion']) : '-' }}</td>
                                    <td>
                                        @if ($row['expected']['food_cost_percent'] !== null)
                                            {{ number_format((float) $row['expected']['food_cost_percent'], 2) }}%
                                            <span class="badge {{ $bandBadge[$row['expected']['band']] ?? 'bg-secondary' }}">{{ $bandLabel($row['expected']['band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $row['latest_order'] ? ($row['latest_order']->order_number ?? ('#'.$row['latest_order']->id)) : '-' }}</td>
                                    <td>{{ $row['actual_cost_per_portion'] !== null ? \Auth::user()->priceFormat((float) $row['actual_cost_per_portion']) : '-' }}</td>
                                    <td>
                                        @if ($row['actual_food_cost_percent'] !== null)
                                            {{ number_format((float) $row['actual_food_cost_percent'], 2) }}%
                                            <span class="badge {{ $bandBadge[$row['actual_band']] ?? 'bg-secondary' }}">{{ $bandLabel($row['actual_band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $row['completed_orders_in_period'] }}</td>
                                    <td>{{ $row['average_food_cost_percent'] !== null ? number_format((float) $row['average_food_cost_percent'], 2).'%' : '-' }}</td>
                                    <td>
                                        <a href="{{ route('kitchen.cost-control.show', $row['product']->id) }}" class="btn btn-sm btn-primary">{{ __('History') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="text-center">{{ __('No dishes with an approved recipe yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Period summary') }} ({{ ($filters['group'] ?? 'month') === 'week' ? __('by week') : __('by month') }})</h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Period') }}</th>
                                <th>{{ __('Orders') }}</th>
                                <th>{{ __('Planned cost') }}</th>
                                <th>{{ __('Actual cost') }}</th>
                                <th>{{ __('Variance') }}</th>
                                <th>{{ __('Avg food cost %') }}</th>
                                <th>{{ __('Bad / very bad') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($summary as $period)
                                <tr>
                                    <td>{{ $period['label'] }}</td>
                                    <td>{{ $period['orders'] }}</td>
                                    <td>{{ \Auth::user()->priceFormat((float) $period['planned_cost']) }}</td>
                                    <td>{{ \Auth::user()->priceFormat((float) $period['actual_cost']) }}</td>
                                    <td class="{{ $period['variance'] > 0 ? 'text-danger' : 'text-success' }}">{{ \Auth::user()->priceFormat((float) $period['variance']) }}</td>
                                    <td>{{ $period['average_food_cost_percent'] !== null ? number_format((float) $period['average_food_cost_percent'], 2).'%' : '-' }}</td>
                                    <td>{{ $period['bad_dishes'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center">{{ __('No completed orders in this period.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Completed orders — expected vs actual') }}</h6>
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Dish') }}</th>
                                <th>{{ __('Completed') }}</th>
                                <th>{{ __('Planned cost') }}</th>
                                <th>{{ __('Actual cost') }}</th>
                                <th>{{ __('Variance') }}</th>
                                <th>{{ __('Planned band') }}</th>
                                <th>{{ __('Actual band') }}</th>
                                <th>{{ __('Flag') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($orders as $order)
                                @php $variance = $variances[(int) $order->id]; @endphp
                                <tr>
                                    <td>{{ $order->order_number ?? ('#'.$order->id) }}</td>
                                    <td>{{ $order->recipe && $order->recipe->product ? $order->recipe->product->name : '-' }}</td>
                                    <td>{{ $order->completed_at ? $order->completed_at->format('d M Y') : '-' }}</td>
                                    <td>{{ \Auth::user()->priceFormat((float) $variance['planned_cost']) }}</td>
                                    <td>{{ $variance['actual_cost'] !== null ? \Auth::user()->priceFormat((float) $variance['actual_cost']) : '-' }}</td>
                                    <td class="{{ ($variance['variance'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $variance['variance'] !== null ? \Auth::user()->priceFormat((float) $variance['variance']) : '-' }}
                                        @if ($variance['variance_percent'] !== null)
                                            ({{ number_format((float) $variance['variance_percent'], 1) }}%)
                                        @endif
                                    </td>
                                    <td>
                                        @if ($variance['planned_band'])
                                            <span class="badge {{ $bandBadge[$variance['planned_band']] ?? 'bg-secondary' }}">{{ $bandLabel($variance['planned_band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if ($variance['actual_band'])
                                            <span class="badge {{ $bandBadge[$variance['actual_band']] ?? 'bg-secondary' }}">{{ $bandLabel($variance['actual_band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if ($variance['worse_than_planned'])
                                            <span class="badge bg-danger">{{ __('Worse than planned') }}</span>
                                        @elseif ($variance['alert'])
                                            <span class="badge bg-warning">{{ __('Over target') }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-center">{{ __('No completed orders in this period.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $orders->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
