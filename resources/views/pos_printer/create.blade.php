@extends('layouts.admin')
@section('page-title')
    {{ __('Add Printer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-printer.index') }}">{{ __('Printers') }}</a></li>
    <li class="breadcrumb-item">{{ __('Add') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'pos-printer.store', 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-12">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('branch_id', $branches, request('branch_id'), ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select Branch')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => 'Kitchen 1']) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('type', __('Type'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('type', ['kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'restaurant' => __('Restaurant')], 'kitchen', ['class' => 'form-control select', 'required' => 'required']) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('connection', __('Connection'), ['class' => 'form-label']) }}
                                {{ Form::text('connection', 'browser', ['class' => 'form-control']) }}
                                <small class="text-muted">{{ __('Use browser for print dialog or IP:port for network printer.') }}</small>
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('sort_order', __('Sort Order'), ['class' => 'form-label']) }}
                                {{ Form::number('sort_order', 0, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                        <a href="{{ route('pos-printer.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
