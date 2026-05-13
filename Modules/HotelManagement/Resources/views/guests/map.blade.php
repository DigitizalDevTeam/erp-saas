@extends('layouts.admin')
@section('page-title'){{ __('Guest map') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Guest map') }}</li>
@endsection
@section('content')
<div class="row g-3">
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('In-house guests'),
            'value' => $kpis['in_house'] ?? 0,
            'subtitle' => __('Currently in-house'),
            'accent' => '#3B82F6',
            'icon' => 'ti ti-users',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Check-ins Today'),
            'value' => $kpis['arrivals_today'] ?? 0,
            'subtitle' => __('Arrivals today'),
            'accent' => '#10B981',
            'trendPct' => $trendArrivals ?? null,
            'icon' => 'ti ti-login',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Check-outs Today'),
            'value' => $kpis['departures_today'] ?? 0,
            'subtitle' => __('Departures today'),
            'accent' => '#F59E0B',
            'trendPct' => $trendDepartures ?? null,
            'icon' => 'ti ti-logout',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('VIP guests'),
            'value' => $kpis['vip_in_house'] ?? 0,
            'subtitle' => ($kpis['vip_total'] ?? 0) . ' ' . __('total VIPs'),
            'accent' => '#8B5CF6',
            'icon' => 'ti ti-star-filled',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Nationalities'),
            'value' => $kpis['nationalities'] ?? 0,
            'subtitle' => __('Distinct nationalities'),
            'accent' => '#06B6D4',
            'icon' => 'ti ti-world',
            'href' => route('hotel.reports.occupancy'),
        ])
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-0">{{ __('Guest map') }}</h5>
                    <small class="text-muted">{{ __('Real-time occupancy and guest map') }}</small>
                </div>
                <a href="{{ route('hotel.rooms.map') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-arrows-maximize me-1"></i>{{ __('Open room map') }}
                </a>
            </div>
            <div class="card-body">
                @include('hotelmanagement::partials.floor_grid', [
                    'rooms' => $rooms,
                    'interactive' => true,
                    'compact' => false,
                ])
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ __('Current guests') }}</h5>
                <a class="small" href="{{ route('hotel.frontdesk.in-house') }}">{{ __('See all') }}</a>
            </div>
            <div class="card-body p-0">
                @if($inHouseList->isEmpty())
                    <p class="text-muted small p-3 mb-0">{{ __('No guests currently in house.') }}</p>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($inHouseList as $stay)
                            @php
                                $g = $stay->reservation->guest ?? null;
                                $name = $g ? trim(($g->first_name ?? '').' '.($g->last_name ?? '')) : __('Guest');
                                $room = $stay->reservation->room->room_no ?? '—';
                                $exp = $stay->expected_check_out_at ? \Carbon\Carbon::parse($stay->expected_check_out_at)->format('d/m') : '—';
                                $isCheckOutToday = $stay->expected_check_out_at && \Carbon\Carbon::parse($stay->expected_check_out_at)->isToday();
                            @endphp
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <span class="badge bg-light text-dark d-flex align-items-center justify-content-center" style="width:32px;height:32px;border-radius:50%;flex-shrink:0;font-weight:700;">
                                    {{ strtoupper(substr($g->first_name ?? '?', 0, 1)) }}
                                </span>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <strong class="small d-block">{{ $name }}
                                        @if($g && !empty($g->is_vip))<i class="ti ti-star-filled text-warning ms-1" title="{{ __('VIP') }}"></i>@endif
                                    </strong>
                                    <small class="text-muted d-block">{{ __('Room') }} {{ $room }} · {{ __('Out') }}: {{ $exp }}</small>
                                </div>
                                @if($isCheckOutToday)
                                    <span class="badge bg-warning">{{ __('Check-out today') }}</span>
                                @else
                                    <span class="badge bg-success">{{ __('Checked-in') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header"><h5 class="mb-0">{{ __('Guest statistics') }}</h5></div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-primary d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;border-radius:50%;"><i class="ti ti-users"></i></span>
                    <div class="flex-grow-1"><small class="text-muted">{{ __('Total in house') }}</small><strong class="d-block">{{ $stats['total'] }}</strong></div>
                </div>
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-success d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;border-radius:50%;"><i class="ti ti-user"></i></span>
                    <div class="flex-grow-1"><small class="text-muted">{{ __('Adults') }}</small><strong class="d-block">{{ $stats['adults'] }}</strong></div>
                </div>
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-info d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;border-radius:50%;"><i class="ti ti-mood-smile"></i></span>
                    <div class="flex-grow-1"><small class="text-muted">{{ __('Children') }}</small><strong class="d-block">{{ $stats['children'] }}</strong></div>
                </div>
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-warning d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;border-radius:50%;"><i class="ti ti-star-filled"></i></span>
                    <div class="flex-grow-1"><small class="text-muted">{{ __('VIPs in house') }}</small><strong class="d-block">{{ $stats['vips'] }}</strong></div>
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-secondary d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;border-radius:50%;"><i class="ti ti-world"></i></span>
                    <div class="flex-grow-1"><small class="text-muted">{{ __('Nationalities') }}</small><strong class="d-block">{{ $stats['nationalities'] }}</strong></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
