@extends('layouts.admin')
@section('page-title')
    {{ $order->order_number ?? __('Draft Production Order') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.production-orders.index') }}">{{ __('Production Orders') }}</a></li>
    <li class="breadcrumb-item">{{ $order->order_number ?? __('Draft') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen production orders')
            @if ($order->isDraft())
                <a href="{{ route('kitchen.production-orders.edit', $order->id) }}" class="btn btn-sm btn-primary"><i class="ti ti-pencil"></i> {{ __('Edit') }}</a>
            @endif
            @if ($order->isApproved())
                {{ Form::open(['route' => ['kitchen.production-orders.start', $order->id], 'method' => 'POST', 'class' => 'd-inline']) }}
                    <button type="submit" class="btn btn-sm btn-warning">{{ __('Start Preparation') }}</button>
                {{ Form::close() }}
            @endif
            @if ($order->isInPreparation())
                <a href="{{ route('kitchen.production-orders.complete', $order->id) }}" class="btn btn-sm btn-success">{{ __('Complete Production') }}</a>
            @endif
        @endcan
        @can('approve kitchen production orders')
            @if ($order->isDraft())
                {{ Form::open(['route' => ['kitchen.production-orders.approve', $order->id], 'method' => 'POST', 'class' => 'd-inline']) }}
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                {{ Form::close() }}
            @endif
            @if ($order->isCompleted())
                {{ Form::open(['route' => ['kitchen.production-orders.validate', $order->id], 'method' => 'POST', 'class' => 'd-inline']) }}
                    <button type="submit" class="btn btn-sm btn-dark">{{ __('Validate') }}</button>
                {{ Form::close() }}
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5>{{ $order->recipe ? $order->recipe->name : '-' }} <small class="text-muted">({{ $order->recipe ? $order->recipe->code : '-' }} v{{ $order->recipe_version }})</small></h5>
                    <p class="mb-1"><strong>{{ __('Order No') }}:</strong> {{ $order->order_number ?? __('Draft — number is allocated on approval') }}</p>
                    <p class="mb-1"><strong>{{ __('Production Date') }}:</strong> {{ $order->production_date ? $order->production_date->format('Y-m-d') : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Section') }}:</strong> {{ $order->section ? ucfirst(str_replace('_', ' ', $order->section)) : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Branch') }}:</strong> {{ $order->branch ? $order->branch->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Kitchen Warehouse') }}:</strong> {{ $order->warehouse ? $order->warehouse->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Responsible Person') }}:</strong> {{ $order->responsibleUser ? $order->responsibleUser->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Planned Quantity') }}:</strong> {{ number_format((float) $order->planned_quantity, 2) }} {{ $order->plannedUnit ? $order->plannedUnit->name : __('portions') }}</p>
                    @if ($order->actual_yield_quantity !== null)
                        <p class="mb-1"><strong>{{ __('Actual Yield') }}:</strong> {{ number_format((float) $order->actual_yield_quantity, 2) }} {{ $order->actualYieldUnit ? $order->actualYieldUnit->name : '' }}</p>
                    @endif
                    @if ($order->notes)
                        <p class="mb-1"><strong>{{ __('Notes') }}:</strong> {{ $order->notes }}</p>
                    @endif
                    <p class="mb-0"><strong>{{ __('Status') }}:</strong>
                        @php
                            $badge = ['draft' => 'bg-secondary', 'approved' => 'bg-info', 'in_preparation' => 'bg-warning', 'completed' => 'bg-success', 'validated' => 'bg-dark'][$order->status] ?? 'bg-secondary';
                        @endphp
                        <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span>
                    </p>
                </div>
            </div>

            @if ($shortages !== [])
                <div class="alert alert-warning">
                    <strong>{{ __('Shortage in the kitchen warehouse') }}:</strong>
                    <ul class="mb-0">
                        @foreach ($shortages as $shortage)
                            <li>{{ $shortage['ingredient_name'] }} — {{ __('need') }} {{ number_format($shortage['needed'], 2) }}, {{ __('have') }} {{ number_format($shortage['available'], 2) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Ingredients — Planned vs Actual') }}</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Planned') }}</th>
                                <th>{{ __('Actual') }}</th>
                                <th>{{ __('Difference') }}</th>
                                <th>{{ __('Planned Cost') }}</th>
                                <th>{{ __('Actual Cost') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($order->lines as $line)
                                <tr>
                                    <td>{{ $line->ingredient ? $line->ingredient->name : '-' }}</td>
                                    <td>{{ number_format((float) $line->planned_quantity, 2) }} {{ $line->plannedUnit ? $line->plannedUnit->name : '' }}</td>
                                    <td>{{ $line->actual_quantity !== null ? number_format((float) $line->actual_quantity, 2).' '.($line->plannedUnit ? $line->plannedUnit->name : '') : '-' }}</td>
                                    <td>
                                        @if ($line->difference() !== null)
                                            @php $diff = $line->difference(); @endphp
                                            <span class="{{ $diff > 0 ? 'text-danger' : ($diff < 0 ? 'text-success' : '') }}">{{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 2) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ number_format((float) $line->planned_cost, 2) }}</td>
                                    <td>{{ $line->actual_cost !== null ? number_format((float) $line->actual_cost, 2) : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center">{{ __('No ingredients planned.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if ($order->batch)
                <div class="card">
                    <div class="card-body">
                        <h5>{{ __('Produced Batch') }}</h5>
                        <p class="mb-1"><strong>{{ __('Batch No') }}:</strong> <a href="{{ route('kitchen.stock.show', $order->batch->id) }}">{{ $order->batch->batch_number }}</a></p>
                        <p class="mb-1"><strong>{{ __('Produced') }}:</strong> {{ number_format((float) $order->batch->quantity_produced, 2) }} {{ $order->batch->unit ? $order->batch->unit->name : '' }}</p>
                        <p class="mb-1"><strong>{{ __('Remaining') }}:</strong> {{ number_format((float) $order->batch->quantity_remaining, 2) }} {{ $order->batch->unit ? $order->batch->unit->name : '' }}</p>
                        <p class="mb-1"><strong>{{ __('Expiry Date') }}:</strong> {{ $order->batch->expiry_date ? $order->batch->expiry_date->format('Y-m-d') : '-' }}</p>
                        <p class="mb-0"><strong>{{ __('Batch Cost') }}:</strong> {{ $order->batch->total_cost !== null ? number_format((float) $order->batch->total_cost, 2) : '-' }}</p>

                        @php $usages = $order->lines->flatMap(fn ($l) => $l->lotUsages); @endphp
                        @if ($usages->isNotEmpty())
                            <h6 class="mt-3">{{ __('Traceability — Ingredient Lots Used') }}</h6>
                            <ul class="mb-0">
                                @foreach ($usages as $usage)
                                    <li>{{ $usage->lot ? $usage->lot->batch_no : __('Lot') }} — {{ number_format((float) $usage->quantity, 2) }} ({{ $usage->line && $usage->line->ingredient ? $usage->line->ingredient->name : '-' }})</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Cost — Expected vs Actual') }}</h5>
                    <p class="mb-1"><strong>{{ __('Planned Cost') }}:</strong> {{ number_format((float) $order->planned_cost, 2) }}</p>
                    <p class="mb-1"><strong>{{ __('Actual Cost') }}:</strong> {{ $order->actual_cost !== null ? number_format((float) $order->actual_cost, 2) : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Cost per Portion') }}:</strong> {{ $order->cost_per_portion !== null ? number_format((float) $order->cost_per_portion, 2) : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Food Cost %') }}:</strong> {{ $order->food_cost_percent !== null ? $order->food_cost_percent.'%' : '-' }}</p>
                    <p class="mb-0"><strong>{{ __('Band') }}:</strong> {{ $bandLabel }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
