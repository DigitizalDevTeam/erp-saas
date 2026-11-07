@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen Dashboard') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Kitchen Dashboard') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('kitchen.reports.index') }}" class="btn btn-sm btn-primary">{{ __('All reports') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-3 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Production today') }}</h6>
                <h3>{{ $todayOrdersCount }}</h3>
                <p class="text-muted">{{ __(':count completed or validated', ['count' => $todayCompletedCount]) }}</p>
            </div></div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Plans waiting approval') }}</h6>
                <h3>{{ $pendingPlans->count() }}</h3>
                <p class="text-muted">{{ __('Production plans in pending approval') }}</p>
            </div></div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Losses today') }}</h6>
                <h3>{{ $lossesTodayCount }}</h3>
                <p class="text-muted">{{ __('Cost') }}: {{ number_format((float) $lossesTodayCost, 2) }}</p>
            </div></div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Open alerts') }}</h6>
                <h3>{{ $openAlerts }}</h3>
                <p class="text-muted">{{ __('Dishes flagged') }}: {{ $flaggedDishes->count() }}</p>
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Today\'s production') }} ({{ $today }})</h6>
                @forelse ($todayOrders as $order)
                    <p class="mb-1">
                        <strong>{{ $order->order_number ?? ('#'.$order->id) }}</strong>
                        {{ $order->recipe && $order->recipe->product ? $order->recipe->product->name : '' }}
                        — {{ ucfirst(str_replace('_', ' ', $order->status)) }}
                        ({{ __('planned') }} {{ number_format((float) ($order->planned_quantity ?? 0), 2) }})
                    </p>
                @empty
                    <p class="text-muted">{{ __('No production planned for today.') }}</p>
                @endforelse
            </div></div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Batches near expiry') }}</h6>
                @if ($expiredCount > 0)
                    <div class="alert alert-danger">{{ __(':count batch(es) already expired with stock left.', ['count' => $expiredCount]) }}</div>
                @endif
                @forelse ($nearExpiry as $batch)
                    <p class="mb-1">
                        <strong>{{ $batch->batch_number }}</strong>
                        {{ $batch->product ? $batch->product->name : '' }}
                        — {{ __('expires') }} {{ $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : '-' }}
                        ({{ __('left') }} {{ number_format((float) ($batch->quantity_remaining ?? 0), 2) }})
                    </p>
                @empty
                    <p class="text-muted">{{ __('No batches expiring in the next 7 days.') }}</p>
                @endforelse
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Plans waiting approval') }}</h6>
                @forelse ($pendingPlans as $plan)
                    <p class="mb-1">
                        <strong>{{ $plan->plan_number }}</strong>
                        {{ $plan->plan_date ? $plan->plan_date->format('Y-m-d') : '' }}
                        {{ ucfirst((string) $plan->meal_service) }}
                    </p>
                @empty
                    <p class="text-muted">{{ __('No plans waiting approval.') }}</p>
                @endforelse
            </div></div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card"><div class="card-body">
                <h6>{{ __('Dishes over the food-cost target') }}</h6>
                @forelse ($flaggedDishes as $row)
                    <p class="mb-1">
                        <strong>{{ $row['product']->name }}</strong>
                        — {{ __('expected') }} {{ $row['expected']['food_cost_percent'] !== null ? number_format((float) $row['expected']['food_cost_percent'], 2).'%' : '-' }}
                        @if ($row['actual_food_cost_percent'] !== null)
                            · {{ __('actual') }} {{ number_format((float) $row['actual_food_cost_percent'], 2).'%' }}
                        @endif
                    </p>
                @empty
                    <p class="text-muted">{{ __('No dishes in a bad or very bad band.') }}</p>
                @endforelse
                <p class="text-muted mt-2">{{ __('Open requisitions') }}: {{ $openRequisitions }}</p>
            </div></div>
        </div>
    </div>
@endsection
