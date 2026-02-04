@extends('layouts.admin')
@section('page-title')
    {{ __('Create Table') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-table.index') }}">{{ __('POS Tables') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'pos-table.store', 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-12">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('branch_id', $branches, null, ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select Branch')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('area_id', __('Area'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('area_id', $areas, null, ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select Area')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('name', __('Table Name'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('e.g. Table 1')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('capacity', __('Capacity'), ['class' => 'form-label']) }}
                                {{ Form::number('capacity', 4, ['class' => 'form-control', 'min' => 1]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('status', __('Status'), ['class' => 'form-label']) }}
                                {{ Form::select('status', ['available' => __('Available'), 'occupied' => __('Occupied'), 'reserved' => __('Reserved')], 'available', ['class' => 'form-control select']) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                        <a href="{{ route('pos-table.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
