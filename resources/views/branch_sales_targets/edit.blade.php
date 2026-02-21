@extends('layouts.admin')
@section('page-title')
    {{ __('Edit Branch Sales Target') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('branch-sales-targets.index') }}">{{ __('Branch Sales Targets') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::model($branch_sales_target, ['route' => ['branch-sales-targets.update', $branch_sales_target], 'method' => 'put', 'class' => 'needs-validation', 'novalidate']) }}
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                {{ Form::label('month', __('Month'), ['class' => 'form-label']) }}<x-required></x-required>
                                <input type="month" name="month" class="form-control" value="{{ $branch_sales_target->month }}" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}<x-required></x-required>
                                <select name="branch_id" class="form-control select" required>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}" {{ $b->id == $branch_sales_target->branch_id ? 'selected' : '' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {{ Form::label('target_amount', __('Target Amount'), ['class' => 'form-label']) }}<x-required></x-required>
                                {{ Form::number('target_amount', $branch_sales_target->target_amount, ['class' => 'form-control', 'min' => 0, 'step' => '0.01', 'required' => 'required']) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                        <a href="{{ route('branch-sales-targets.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
