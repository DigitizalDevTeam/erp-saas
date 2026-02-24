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
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="period_type" class="form-label">{{ __('Period') }}</label><x-required></x-required>
                                <select name="period_type" id="period_type" class="form-control select" required>
                                    <option value="day" {{ $branch_sales_target->period_type === 'day' ? 'selected' : '' }}>{{ __('Day') }}</option>
                                    <option value="week" {{ $branch_sales_target->period_type === 'week' ? 'selected' : '' }}>{{ __('Week') }}</option>
                                    <option value="month" {{ $branch_sales_target->period_type === 'month' ? 'selected' : '' }}>{{ __('Month') }}</option>
                                    <option value="year" {{ $branch_sales_target->period_type === 'year' ? 'selected' : '' }}>{{ __('Year') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3" id="wrap_period_value">
                            <div class="form-group">
                                <label for="period_value" class="form-label">{{ __('Period value') }}</label><x-required></x-required>
                                <input type="month" name="{{ $branch_sales_target->period_type === 'month' ? 'period_value' : '' }}" id="period_value_month" class="form-control {{ $branch_sales_target->period_type !== 'month' ? 'd-none' : '' }}" value="{{ $branch_sales_target->period_type === 'month' ? $branch_sales_target->period_value : date('Y-m') }}">
                                <input type="date" name="{{ $branch_sales_target->period_type === 'day' ? 'period_value' : '' }}" id="period_value_day" class="form-control {{ $branch_sales_target->period_type !== 'day' ? 'd-none' : '' }}" value="{{ $branch_sales_target->period_type === 'day' ? $branch_sales_target->period_value : date('Y-m-d') }}">
                                <input type="date" name="{{ $branch_sales_target->period_type === 'week' ? 'period_value' : '' }}" id="period_value_week" class="form-control {{ $branch_sales_target->period_type !== 'week' ? 'd-none' : '' }}" value="{{ $branch_sales_target->period_type === 'week' ? $branch_sales_target->period_value : date('Y-m-d') }}" title="{{ __('Pick any day in the week') }}">
                                <input type="number" name="{{ $branch_sales_target->period_type === 'year' ? 'period_value' : '' }}" id="period_value_year" class="form-control {{ $branch_sales_target->period_type !== 'year' ? 'd-none' : '' }}" min="2000" max="2100" value="{{ $branch_sales_target->period_type === 'year' ? $branch_sales_target->period_value : date('Y') }}" placeholder="{{ __('Year') }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}<x-required></x-required>
                                <select name="branch_id" class="form-control select" required>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}" {{ $b->id == $branch_sales_target->branch_id ? 'selected' : '' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var periodType = document.getElementById('period_type');
            var monthInput = document.getElementById('period_value_month');
            var dayInput = document.getElementById('period_value_day');
            var weekInput = document.getElementById('period_value_week');
            var yearInput = document.getElementById('period_value_year');
            function syncPeriodInputs() {
                var v = periodType.value;
                monthInput.classList.add('d-none'); monthInput.removeAttribute('name');
                dayInput.classList.add('d-none'); dayInput.removeAttribute('name');
                weekInput.classList.add('d-none'); weekInput.removeAttribute('name');
                yearInput.classList.add('d-none'); yearInput.removeAttribute('name');
                if (v === 'month') { monthInput.classList.remove('d-none'); monthInput.setAttribute('name', 'period_value'); }
                else if (v === 'day') { dayInput.classList.remove('d-none'); dayInput.setAttribute('name', 'period_value'); }
                else if (v === 'week') { weekInput.classList.remove('d-none'); weekInput.setAttribute('name', 'period_value'); }
                else if (v === 'year') { yearInput.classList.remove('d-none'); yearInput.setAttribute('name', 'period_value'); }
            }
            periodType.addEventListener('change', syncPeriodInputs);
            syncPeriodInputs();
        });
    </script>
@endsection
