@extends('layouts.admin')
@section('page-title'){{ __('Hotel Reports') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
@endsection

@php
    $groups = [
        [
            'title' => __('Operations'),
            'icon' => 'ti ti-chart-line',
            'tone' => 'primary',
            'items' => [
                ['label' => __('Operational summary'), 'route' => 'hotel.reports.operational'],
                ['label' => __('Reservation Report'), 'route' => 'hotel.reports.ops.reservations'],
                ['label' => __('Check-in Report'), 'route' => 'hotel.reports.ops.check-in'],
                ['label' => __('Check-out Report'), 'route' => 'hotel.reports.ops.check-out'],
                ['label' => __('Occupancy Report'), 'route' => 'hotel.reports.occupancy'],
                ['label' => __('Room Status Report'), 'route' => 'hotel.reports.ops.room-status'],
                ['label' => __('Housekeeping Report'), 'route' => 'hotel.reports.ops.housekeeping'],
            ],
        ],
        [
            'title' => __('Financial'),
            'icon' => 'ti ti-currency-dollar',
            'tone' => 'success',
            'items' => [
                ['label' => __('Financial summary'), 'route' => 'hotel.reports.financial'],
                ['label' => __('Daily Revenue Report'), 'route' => 'hotel.reports.financial.daily-revenue'],
                ['label' => __('Monthly Revenue Report'), 'route' => 'hotel.reports.financial.monthly-revenue'],
                ['label' => __('Payment Collection Report'), 'route' => 'hotel.reports.financial.payment-collection'],
                ['label' => __('Outstanding Balance Report'), 'route' => 'hotel.reports.financial.outstanding'],
                ['label' => __('Cashier Closing Report'), 'route' => 'hotel.reports.financial.cashier-closing'],
                ['label' => __('Occupancy Revenue Report'), 'route' => 'hotel.reports.financial.occupancy-revenue'],
            ],
        ],
        [
            'title' => __('Guests & compliance'),
            'icon' => 'ti ti-id',
            'tone' => 'warning',
            'items' => [
                ['label' => __('Immigration Report'), 'route' => 'hotel.reports.immigration'],
                ['label' => __('Customer Report'), 'route' => 'hotel.reports.customers'],
                ['label' => __('Alerts & performance'), 'route' => 'hotel.reports.alerts'],
            ],
        ],
    ];
@endphp

@section('content')
@include('hotelmanagement::reports._nav')

<div class="row g-3">
    @foreach($groups as $group)
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="avtar avtar-s bg-light-{{ $group['tone'] }} text-{{ $group['tone'] }} rounded"><i class="{{ $group['icon'] }} f-20"></i></span>
                    <h5 class="mb-0">{{ $group['title'] }}</h5>
                </div>
                <div class="list-group list-group-flush">
                    @foreach($group['items'] as $item)
                        <a href="{{ route($item['route']) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span>{{ $item['label'] }}</span>
                            <i class="ti ti-chevron-right text-muted"></i>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="alert alert-light border small mt-3 mb-0">
    <i class="ti ti-info-circle me-1"></i>{{ __('Every report supports a date filter and can be exported to PDF and Excel.') }}
</div>
@endsection
