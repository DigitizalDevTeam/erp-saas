@extends('layouts.admin')
@section('page-title')
    {{ __('POS Restaurant Settings') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Restaurant Settings') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Phase 6: Discount above this % requires manager password. Low stock report shows products below threshold.') }}</p>
                    {{ Form::open(['route' => 'pos-restaurant-settings.store', 'method' => 'post']) }}
                    @csrf
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('Max discount % without manager approval') }}</label>
                                <input type="number" name="pos_max_discount_percent" class="form-control" min="0" max="100" step="0.01" value="{{ old('pos_max_discount_percent', $pos_max_discount_percent ?? '10') }}" placeholder="10">
                                <small class="text-muted">{{ __('Discount above this % requires manager password at payment.') }}</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('Low stock threshold') }}</label>
                                <input type="number" name="pos_low_stock_threshold" class="form-control" min="0" step="1" value="{{ old('pos_low_stock_threshold', $pos_low_stock_threshold ?? '10') }}" placeholder="10">
                                <small class="text-muted">{{ __('Products with quantity below this appear in Low Stock report.') }}</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">{{ __('Default preparation time (minutes)') }}</label>
                                <input type="number" name="pos_default_prep_minutes" class="form-control" min="1" max="480" step="1" value="{{ old('pos_default_prep_minutes', $pos_default_prep_minutes ?? '15') }}" placeholder="15">
                                <small class="text-muted">{{ __('Used when sending orders to kitchen/bar. Overtime before Ready triggers an alert.') }}</small>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        <a href="{{ route('pos-shift.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
