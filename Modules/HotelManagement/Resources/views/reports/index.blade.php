@extends('layouts.admin')
@section('page-title'){{ __('Hotel Reports') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
@endsection
@section('content')
<div class="row g-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column">
                <div class="mb-2 text-primary"><i class="ti ti-chart-line" style="font-size:1.75rem;"></i></div>
                <h5 class="card-title">{{ __('Operational report') }}</h5>
                <p class="text-muted small flex-grow-1">{{ __('Daily occupancy, check-ins, check-outs, room status, and average length of stay.') }}</p>
                <a href="{{ route('hotel.reports.operational') }}" class="btn btn-primary btn-sm">{{ __('Open report') }}</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column">
                <div class="mb-2 text-success"><i class="ti ti-currency-dollar" style="font-size:1.75rem;"></i></div>
                <h5 class="card-title">{{ __('Financial report') }}</h5>
                <p class="text-muted small flex-grow-1">{{ __('Revenue, payments received vs pending, payment methods, and discounts.') }}</p>
                <a href="{{ route('hotel.reports.financial') }}" class="btn btn-success btn-sm">{{ __('Open report') }}</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column">
                <div class="mb-2 text-warning"><i class="ti ti-id" style="font-size:1.75rem;"></i></div>
                <h5 class="card-title">{{ __('Immigration report') }}</h5>
                <p class="text-muted small flex-grow-1">{{ __('Guest register with nationality, ID/passport, stay dates, and room — for authorities.') }}</p>
                <a href="{{ route('hotel.reports.immigration') }}" class="btn btn-warning btn-sm">{{ __('Open report') }}</a>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-body">
        <h6 class="text-muted text-uppercase small fw-bold mb-3">{{ __('More reports') }}</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('hotel.reports.occupancy') }}" class="btn btn-outline-secondary btn-sm">{{ __('Occupancy') }}</a>
            <a href="{{ route('hotel.reports.customers') }}" class="btn btn-outline-secondary btn-sm">{{ __('Customers') }}</a>
            <a href="{{ route('hotel.reports.alerts') }}" class="btn btn-outline-secondary btn-sm">{{ __('Alerts') }}</a>
        </div>
    </div>
</div>
@endsection
