@extends('layouts.admin')
@section('page-title')
    {{ __('Open Shift') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Open Shift') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'pos-shift.store', 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-12">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}<x-required></x-required>
                                {{ Form::select('branch_id', $branches, null, ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select Branch')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('user_id', __('Waiter / User'), ['class' => 'form-label']) }}<x-required></x-required>
                                {{ Form::select('user_id', $users, null, ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select User')]) }}
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="form-group">
                                {{ Form::label('opening_balance', __('Opening Balance'), ['class' => 'form-label']) }}
                                {{ Form::number('opening_balance', 0, ['class' => 'form-control', 'min' => 0, 'step' => '0.01']) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Open Shift') }}</button>
                        <a href="{{ route('pos-shift.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
