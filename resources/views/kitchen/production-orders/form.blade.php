@extends('layouts.admin')
@section('page-title')
    {{ $order->exists ? __('Edit Production Order') : __('Create Production Order') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.production-orders.index') }}">{{ __('Production Orders') }}</a></li>
    <li class="breadcrumb-item">{{ $order->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($order->exists)
                        {{ Form::model($order, ['route' => ['kitchen.production-orders.update', $order->id], 'method' => 'PUT', 'class' => 'needs-validation', 'novalidate']) }}
                    @else
                        {{ Form::open(['route' => 'kitchen.production-orders.store', 'method' => 'POST', 'class' => 'needs-validation', 'novalidate']) }}
                    @endif

                    <div class="row">
                        <div class="form-group col-md-6">
                            {{ Form::label('kitchen_recipe_id', __('Recipe'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('kitchen_recipe_id', $recipes->mapWithKeys(fn ($r) => [$r->id => $r->name.' ('.$r->code.' v'.$r->version.')']), null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Select an approved recipe')]) }}
                            <small class="text-muted">{{ __('Only approved recipes can be produced. The order locks the recipe version it starts with.') }}</small>
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('production_date', __('Production Date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('production_date', $order->production_date ? $order->production_date->format('Y-m-d') : date('Y-m-d'), ['class' => 'form-control', 'required' => 'required']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('section', __('Kitchen / Section'), ['class' => 'form-label']) }}
                            {{ Form::select('section', ['hot_kitchen' => __('Hot Kitchen'), 'pastry' => __('Pastry'), 'cold_kitchen' => __('Cold Kitchen')], null, ['class' => 'form-control', 'placeholder' => __('Select section')]) }}
                        </div>

                        <div class="form-group col-md-3">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::select('branch_id', $branches->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('Select branch')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('warehouse_id', __('Kitchen Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('warehouse_id', $warehouses->pluck('name', 'id'), null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Select kitchen warehouse')]) }}
                            <small class="text-muted">{{ __('Ingredients are deducted here and the finished batch is received here.') }}</small>
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('planned_quantity', __('Planned Quantity'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::number('planned_quantity', null, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0.0001', 'required' => 'required', 'placeholder' => __('e.g. 20')]) }}
                            <small class="text-muted">{{ __('In portions or in the recipe yield unit — pick the unit below.') }}</small>
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('planned_unit_id', __('Planned Unit'), ['class' => 'form-label']) }}
                            {{ Form::select('planned_unit_id', $units->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('Portions (default)')]) }}
                        </div>

                        <div class="form-group col-md-4">
                            {{ Form::label('responsible_user_id', __('Responsible Person'), ['class' => 'form-label']) }}
                            {{ Form::select('responsible_user_id', $users->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('Select person')]) }}
                        </div>
                        <div class="form-group col-md-8">
                            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
                            {{ Form::text('notes', null, ['class' => 'form-control', 'placeholder' => __('e.g. For the Saturday buffet')]) }}
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-12 text-end">
                            <a href="{{ route('kitchen.production-orders.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                            <button type="submit" class="btn btn-primary">{{ $order->exists ? __('Update Order') : __('Create Draft Order') }}</button>
                        </div>
                    </div>

                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
