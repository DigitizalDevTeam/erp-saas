@extends('layouts.admin')
@section('page-title')
    {{ __('Create Branch Sales Target') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('branch-sales-targets.index') }}">{{ __('Branch Sales Targets') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'branch-sales-targets.store', 'method' => 'post', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="month" class="form-label">{{ __('Month') }}</label><x-required></x-required>
                                <input type="month" name="month" id="month" class="form-control" value="{{ date('Y-m') }}" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}<x-required></x-required>
                                <select name="branch_id" class="form-control select" required>
                                    <option value="">{{ __('Select Branch') }}</option>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {{ Form::label('target_amount', __('Target Amount'), ['class' => 'form-label']) }}<x-required></x-required>
                                {{ Form::number('target_amount', 0, ['class' => 'form-control', 'min' => 0, 'step' => '0.01', 'required' => 'required']) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                        <a href="{{ route('branch-sales-targets.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
