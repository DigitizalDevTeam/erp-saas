@extends('layouts.admin')
@section('page-title')
    {{ __('Gross Profit') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Gross Profit') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Start date') }}</label>
                            <input type="date" name="start_date" class="form-control" value="{{ $start }}">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('End date') }}</label>
                            <input type="date" name="end_date" class="form-control" value="{{ $end }}">
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        </div>
                    </form>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="p-3 border rounded">
                                <small class="text-muted">{{ __('Revenue') }}</small>
                                <div class="fs-4">{{ Auth::user()->priceFormat($revenue) }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 border rounded">
                                <small class="text-muted">{{ __('Cost') }}</small>
                                <div class="fs-4">{{ Auth::user()->priceFormat($cost) }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 border rounded">
                                <small class="text-muted">{{ __('Gross Profit') }}</small>
                                <div class="fs-4">{{ Auth::user()->priceFormat($profit) }}</div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mt-3">{{ __('Cost is based on product purchase price.') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
