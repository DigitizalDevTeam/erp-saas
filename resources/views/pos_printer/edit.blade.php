@extends('layouts.admin')
@section('page-title')
    {{ __('Edit Printer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-printer.index') }}">{{ __('Printers') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::model($pos_printer, ['route' => ['pos-printer.update', $pos_printer], 'method' => 'put', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-12">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('branch_id', $branches, null, ['class' => 'form-control select', 'required' => 'required']) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required']) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('type', __('Type'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('type', ['kitchen' => __('Kitchen'), 'bar' => __('Bar')], null, ['class' => 'form-control select', 'required' => 'required']) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('connection', __('Connection'), ['class' => 'form-label']) }}
                                {{ Form::text('connection', null, ['class' => 'form-control', 'placeholder' => __('browser or IP:port')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('sort_order', __('Sort Order'), ['class' => 'form-label']) }}
                                {{ Form::number('sort_order', null, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                        <a href="{{ route('pos-printer.index', ['branch_id' => $pos_printer->branch_id]) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
