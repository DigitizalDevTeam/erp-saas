@extends('layouts.admin')
@section('page-title')
    {{ __('Complete Production') }} — {{ $order->order_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.production-orders.index') }}">{{ __('Production Orders') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.production-orders.show', $order->id) }}">{{ $order->order_number }}</a></li>
    <li class="breadcrumb-item">{{ __('Complete') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h5>{{ $order->recipe ? $order->recipe->name : '-' }} — {{ __('what was actually used') }}</h5>
                    <p class="text-muted">{{ __('Completing deducts the actual ingredients from the kitchen warehouse once and receives the preparation as one batch. A completed order cannot be completed again.') }}</p>

                    @if ($shortages !== [])
                        <div class="alert alert-warning">
                            <strong>{{ __('Shortage in the kitchen warehouse') }}:</strong>
                            <ul class="mb-0">
                                @foreach ($shortages as $shortage)
                                    <li>{{ $shortage['ingredient_name'] }} — {{ __('need') }} {{ number_format($shortage['needed'], 2) }}, {{ __('have') }} {{ number_format($shortage['available'], 2) }}</li>
                                @endforeach
                            </ul>
                            <p class="mb-0 mt-2">{{ __('Lower the actual quantity below, or move stock into the kitchen warehouse first — completion is blocked while a shortage stands.') }}</p>
                        </div>
                    @endif

                    {{ Form::open(['route' => ['kitchen.production-orders.complete.store', $order->id], 'method' => 'POST', 'class' => 'needs-validation', 'novalidate']) }}

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Planned') }}</th>
                                <th>{{ __('Actual Used') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($order->lines as $line)
                                <tr>
                                    <td>{{ $line->ingredient ? $line->ingredient->name : '-' }}</td>
                                    <td>{{ number_format((float) $line->planned_quantity, 2) }} {{ $line->plannedUnit ? $line->plannedUnit->name : '' }}</td>
                                    <td>
                                        <div class="input-group" style="max-width: 220px;">
                                            {{ Form::number('actuals['.$line->id.']', (float) $line->planned_quantity, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0', 'required' => 'required']) }}
                                            <span class="input-group-text">{{ $line->plannedUnit ? $line->plannedUnit->name : '' }}</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="row mt-3">
                        <div class="form-group col-md-3">
                            {{ Form::label('actual_yield_quantity', __('Actual Yield'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::number('actual_yield_quantity', (float) $order->planned_quantity, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0.0001', 'required' => 'required']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('actual_yield_unit_id', __('Yield Unit'), ['class' => 'form-label']) }}
                            {{ Form::select('actual_yield_unit_id', $units->pluck('name', 'id'), $order->planned_unit_id, ['class' => 'form-control', 'placeholder' => __('Same as planned')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('expiry_date', __('Batch Expiry Date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('expiry_date', $defaultExpiry, ['class' => 'form-control', 'required' => 'required', 'min' => date('Y-m-d')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('notes', __('Notes / Losses'), ['class' => 'form-label']) }}
                            {{ Form::text('notes', $order->notes, ['class' => 'form-control', 'placeholder' => __('e.g. 200 g burnt')]) }}
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-12 text-end">
                            <a href="{{ route('kitchen.production-orders.show', $order->id) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                            <button type="submit" class="btn btn-success">{{ __('Complete & Receive Batch') }}</button>
                        </div>
                    </div>

                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
