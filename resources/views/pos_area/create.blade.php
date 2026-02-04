@extends('layouts.admin')
@section('page-title')
    {{ __('Create Area') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-area.index') }}">{{ __('POS Areas') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'pos-area.store', 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-12">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::select('branch_id', $branches, null, ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select Branch')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('name', __('Area Name'), ['class' => 'form-label']) }} <x-required></x-required>
                                {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('e.g. Bar, Garden, Room 1')]) }}
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
                        <a href="{{ route('pos-area.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
