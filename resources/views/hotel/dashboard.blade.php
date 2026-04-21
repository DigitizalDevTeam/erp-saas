@extends('layouts.admin')
@section('page-title')
    {{ __('Hotel Dashboard') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Hotel Management') }}</li>
@endsection
@section('content')
    <div class="row g-3">
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <p class="text-muted mb-1">{{ __('Total Rooms') }}</p>
                    <h3 class="mb-0">{{ $data['roomsTotal'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <p class="text-muted mb-1">{{ __('Occupied') }}</p>
                    <h3 class="mb-0">{{ $data['roomsOccupied'] }}</h3>
                    <small class="text-success">{{ $data['occupancyRate'] }}% {{ __('Occupancy') }}</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <p class="text-muted mb-1">{{ __('In-House Guests') }}</p>
                    <h3 class="mb-0">{{ $data['inHouse'] }}</h3>
                    <small class="text-muted">{{ __('Arrivals today') }}: {{ $data['arrivalsToday'] }}</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <p class="text-muted mb-1">{{ __('Today Revenue') }}</p>
                    <h4 class="mb-0">{{ Auth::user()->priceFormat($data['todayRevenue']) }}</h4>
                    <small class="text-muted">{{ __('Departures today') }}: {{ $data['departuresToday'] }}</small>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('Last 7 Days: Flow & Revenue') }}</h5>
                    <small class="text-muted">{{ __('Arrivals / Departures / Revenue') }}</small>
                </div>
                <div class="card-body">
                    <div id="hotel-flow-chart"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Reservation Status Mix') }}</h5>
                </div>
                <div class="card-body">
                    <div id="hotel-status-chart"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    (function () {
        var flowOptions = {
            chart: { type: 'line', height: 340, toolbar: { show: false } },
            stroke: { width: [3, 3, 3], curve: 'smooth' },
            dataLabels: { enabled: false },
            series: [
                { name: '{{ __("Arrivals") }}', data: @json($data['arrivalsSeries']) },
                { name: '{{ __("Departures") }}', data: @json($data['departuresSeries']) },
                { name: '{{ __("Revenue") }}', data: @json($data['revenueSeries']) },
            ],
            xaxis: { categories: @json($data['labels']) },
            yaxis: [{ title: { text: '{{ __("Count / Amount") }}' } }],
            colors: ['#3B82F6', '#10B981', '#F59E0B'],
            grid: { strokeDashArray: 4 },
            legend: { position: 'top' },
        };
        new ApexCharts(document.querySelector('#hotel-flow-chart'), flowOptions).render();

        var statusOptions = {
            chart: { type: 'donut', height: 320 },
            labels: @json($data['statusLabels']),
            series: @json($data['statusSeries']),
            legend: { position: 'bottom' },
            colors: ['#94A3B8', '#3B82F6', '#10B981', '#22C55E', '#EF4444']
        };
        new ApexCharts(document.querySelector('#hotel-status-chart'), statusOptions).render();
    })();
</script>
@endpush
