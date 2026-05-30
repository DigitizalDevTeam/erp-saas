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
            <div class="card shadow-sm border-0">
                <div class="card-body py-3">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-xl-2">
                            <a href="{{ route('hotel.frontdesk.in-house') }}" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                                <i class="ti ti-login fs-5"></i>
                                <span class="text-start lh-sm small">
                                    <strong class="d-block">{{ __('New check-in') }}</strong>
                                    <span class="opacity-75">{{ __('Register guest') }}</span>
                                </span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <a href="{{ $data['checkoutUrl'] ?? route('hotel.frontdesk.in-house') }}" class="btn btn-success w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                                <i class="ti ti-logout fs-5"></i>
                                <span class="text-start lh-sm small">
                                    <strong class="d-block">{{ __('Check-out') }}</strong>
                                    <span class="opacity-75">{{ __('Finish stay') }}</span>
                                </span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <a href="{{ route('hotel.reservations.create') }}" class="btn btn-info w-100 d-flex align-items-center justify-content-center gap-2 py-2 text-white">
                                <i class="ti ti-calendar-plus fs-5"></i>
                                <span class="text-start lh-sm small">
                                    <strong class="d-block">{{ __('New reservation') }}</strong>
                                    <span class="opacity-75">{{ __('Create reservation') }}</span>
                                </span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <a href="{{ route('hotel.rooms.map') }}" class="btn btn-warning w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                                <i class="ti ti-arrows-exchange fs-5"></i>
                                <span class="text-start lh-sm small">
                                    <strong class="d-block">{{ __('Change room') }}</strong>
                                    <span class="opacity-75">{{ __('Change room') }}</span>
                                </span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <a href="{{ route('hotel.frontdesk.in-house') }}" class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                                <i class="ti ti-credit-card fs-5"></i>
                                <span class="text-start lh-sm small">
                                    <strong class="d-block">{{ __('Payment') }}</strong>
                                    <span class="opacity-75">{{ __('Register payment') }}</span>
                                </span>
                            </a>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <a href="{{ route('hotel.reservations.index') }}" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                                <i class="ti ti-receipt fs-5"></i>
                                <span class="text-start lh-sm small">
                                    <strong class="d-block">{{ __('Invoice') }}</strong>
                                    <span class="opacity-75">{{ __('Issue invoice') }}</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
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

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">{{ __('Room map') }}</h5>
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
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('Alerts and Tasks') }}</h5>
                    <a class="small" href="{{ route('hotel.reports.alerts') }}">{{ __('See all') }}</a>
                </div>
                <div class="card-body p-0">
                    @if(empty($data['alerts']))
                        <p class="text-muted small p-3 mb-0">{{ __('No active alerts. Everything looks great!') }}</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach($data['alerts'] as $alert)
                                <li class="list-group-item d-flex align-items-start gap-2">
                                    <span class="badge bg-{{ $alert['level'] }} d-flex align-items-center justify-content-center" style="width:32px;height:32px;border-radius:50%;flex-shrink:0;">
                                        <i class="{{ $alert['icon'] }}"></i>
                                    </span>
                                    <div class="flex-grow-1" style="min-width:0;">
                                        <a class="d-block text-decoration-none text-body" href="{{ $alert['href'] }}">
                                            <strong class="small d-block">{{ $alert['title'] }}</strong>
                                            <small class="text-muted text-truncate d-block">{{ $alert['description'] }}</small>
                                        </a>
                                    </div>
                                    @if(!empty($alert['when']))
                                        <small class="text-muted text-nowrap">{{ $alert['when'] }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">{{ __('Current guests') }}</h5>
                        <small class="text-muted">{{ __('Top') }} {{ $data['inHouseGuests']->count() }} {{ __('of') }} {{ $data['inHouse'] }}</small>
                    </div>
                    <a class="small" href="{{ route('hotel.frontdesk.in-house') }}">{{ __('See all') }}</a>
                </div>
                <div class="card-body p-0">
                    @if($data['inHouseGuests']->isEmpty())
                        <p class="text-muted small p-3 mb-0">{{ __('No guests currently in house.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead><tr><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Check-out') }}</th><th>{{ __('Status') }}</th></tr></thead>
                                <tbody>
                                @foreach($data['inHouseGuests'] as $stay)
                                    @php
                                        $g = $stay->reservation->guest ?? null;
                                        $name = $g ? trim(($g->first_name ?? '').' '.($g->last_name ?? '')) : __('Guest');
                                        $room = $stay->reservation->room->room_no ?? '—';
                                        $exp = $stay->expected_check_out_at ? \Carbon\Carbon::parse($stay->expected_check_out_at)->format('d/m/Y') : '—';
                                        $isCheckOutToday = $stay->expected_check_out_at && \Carbon\Carbon::parse($stay->expected_check_out_at)->isToday();
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong class="small">{{ $name }}</strong>
                                            @if($g && !empty($g->is_vip)) <i class="ti ti-star-filled text-warning ms-1" title="{{ __('VIP') }}"></i> @endif
                                        </td>
                                        <td>{{ $room }}</td>
                                        <td>{{ $stay->check_in_at ? \Carbon\Carbon::parse($stay->check_in_at)->format('d/m/Y') : '—' }}</td>
                                        <td>{{ $exp }}</td>
                                        <td>
                                            @if($isCheckOutToday)
                                                <span class="badge bg-warning">{{ __('Check-out today') }}</span>
                                            @else
                                                <span class="badge bg-success">{{ __('Checked-in') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('Financial summary') }}</h5>
                    <a class="small" href="{{ route('hotel.reports.financial') }}">{{ __('See report') }}</a>
                </div>
                <div class="card-body">
                    @php
                        $fin = $data['financial'];
                        $finTrend = (float) ($fin['today_trend_pct'] ?? 0);
                        $finTrendIcon = $finTrend > 0.01 ? 'ti ti-trending-up' : ($finTrend < -0.01 ? 'ti ti-trending-down' : 'ti ti-minus');
                        $finTrendCls = $finTrend > 0.01 ? 'text-success' : ($finTrend < -0.01 ? 'text-danger' : 'text-muted');
                    @endphp
                    <p class="text-muted small mb-1">{{ __('Today Revenue') }}</p>
                    <h3 class="mb-0">{{ Auth::user()->priceFormat($fin['today_revenue']) }}</h3>
                    <small class="{{ $finTrendCls }}"><i class="{{ $finTrendIcon }} me-1"></i>{{ ($finTrend > 0 ? '+' : '') . number_format($finTrend, 1) }}% {{ __('vs yesterday') }}</small>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">{{ __('Pending payments') }}</span>
                        <span class="text-end">
                            <strong>{{ $fin['pending_count'] }}</strong>
                            <small class="text-muted d-block">{{ Auth::user()->priceFormat($fin['pending_amount']) }}</small>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small">{{ __('Total receivables') }}</span>
                        <strong>{{ Auth::user()->priceFormat($fin['total_receivables']) }}</strong>
                    </div>
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('Recent Activity') }}</h5>
                </div>
                <div class="card-body p-0">
                    @if(empty($data['recentActivity']))
                        <p class="text-muted small p-3 mb-0">{{ __('No recent activity yet.') }}</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach($data['recentActivity'] as $event)
                                <li class="list-group-item d-flex align-items-start gap-2">
                                    <span class="badge bg-{{ $event['level'] }} d-flex align-items-center justify-content-center" style="width:30px;height:30px;border-radius:50%;flex-shrink:0;">
                                        <i class="{{ $event['icon'] }}"></i>
                                    </span>
                                    <div class="flex-grow-1" style="min-width:0;">
                                        <strong class="small d-block">{{ $event['title'] }}</strong>
                                        <small class="text-muted text-truncate d-block">{{ $event['description'] }}</small>
                                    </div>
                                    @if(!empty($event['when']))
                                        <small class="text-muted text-nowrap">{{ \Carbon\Carbon::parse($event['when'])->diffForHumans() }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm border-0">
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
