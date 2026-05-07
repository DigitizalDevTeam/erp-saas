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
        <div class="col-xl-2 col-md-4 col-6">
            @include('hotelmanagement::partials.kpi_card', [
                'title' => __('Available Rooms'),
                'value' => $data['roomsAvailable'],
                'subtitle' => __('of') . ' ' . $data['roomsTotal'] . ' ' . __('total'),
                'accent' => '#10B981',
                'series' => $data['availableSeries'],
                'trendPct' => $data['trends']['available'] ?? null,
                'icon' => 'ti ti-bed',
            ])
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            @include('hotelmanagement::partials.kpi_card', [
                'title' => __('Occupied Rooms'),
                'value' => $data['roomsOccupied'],
                'subtitle' => $data['occupancyRate'] . '% ' . __('occupancy'),
                'accent' => '#EF4444',
                'series' => $data['occupiedSeries'],
                'trendPct' => $data['trends']['occupied'] ?? null,
                'icon' => 'ti ti-door',
            ])
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            @include('hotelmanagement::partials.kpi_card', [
                'title' => __('Check-ins Today'),
                'value' => $data['arrivalsToday'],
                'subtitle' => __('Cleaning') . ': ' . $data['roomsCleaning'],
                'accent' => '#F59E0B',
                'series' => $data['arrivalsSeries'],
                'trendPct' => $data['trends']['arrivals'] ?? null,
                'icon' => 'ti ti-login',
            ])
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            @include('hotelmanagement::partials.kpi_card', [
                'title' => __('Check-outs Today'),
                'value' => $data['departuresToday'],
                'subtitle' => __('Maintenance') . ': ' . $data['roomsMaintenance'],
                'accent' => '#3B82F6',
                'series' => $data['departuresSeries'],
                'trendPct' => $data['trends']['departures'] ?? null,
                'icon' => 'ti ti-logout',
            ])
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            @include('hotelmanagement::partials.kpi_card', [
                'title' => __('Overbooking / Alerts'),
                'value' => $data['overbookingAlerts'],
                'subtitle' => __('Pending room assignment'),
                'accent' => '#DC2626',
                'icon' => 'ti ti-alert-triangle',
            ])
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            @include('hotelmanagement::partials.kpi_card', [
                'title' => __('Today Revenue'),
                'value' => Auth::user()->priceFormat($data['todayRevenue']),
                'subtitle' => __('In-House') . ': ' . $data['inHouse'] . ' • ' . __('Open balances') . ': ' . ($data['openFolioBalances'] ?? 0),
                'accent' => '#8B5CF6',
                'series' => $data['revenueSeries'],
                'trendPct' => $data['trends']['revenue'] ?? null,
                'icon' => 'ti ti-cash',
            ])
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0 {{ ($data['smart']['low_occupancy'] ?? false) ? 'border-warning' : '' }}" style="border-left: 4px solid #6366F1 !important;">
                <div class="card-body">
                    <h6 class="text-muted mb-2">{{ __('Smart insights') }}</h6>
                    <p class="mb-1"><strong>{{ __('Occupancy forecast (7d trend)') }}:</strong> {{ $data['smart']['forecast_occupancy_pct'] ?? 0 }}%</p>
                    @if (!empty($data['smart']['low_occupancy']))
                        <p class="text-warning mb-1"><i class="ti ti-alert-triangle"></i> {{ __('Low demand signal — consider marketing or rate review.') }}</p>
                    @endif
                    <p class="mb-0 text-muted small">{{ $data['smart']['pricing_hint'] ?? '' }}</p>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">{{ __('Mapa de Quartos') }}</h5>
                        <small class="text-muted">{{ __('Real-time room status by floor') }}</small>
                    </div>
                    <a href="{{ route('hotel.rooms.map') }}" class="btn btn-sm btn-outline-primary">
                        <i class="ti ti-arrows-maximize me-1"></i>{{ __('Open full map') }}
                    </a>
                </div>
                <div class="card-body">
                    @include('hotelmanagement::partials.floor_grid', [
                        'rooms' => $data['mapRooms'],
                        'interactive' => true,
                        'compact' => true,
                    ])
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
