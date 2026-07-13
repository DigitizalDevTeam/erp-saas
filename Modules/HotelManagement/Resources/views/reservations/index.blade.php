@extends('layouts.admin')
@section('page-title'){{ __('Reservations') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reservations') }}</li>
@endsection
@section('action-btn')
@can('hotel.manage.reservations')
<a href="{{ route('hotel.reservations.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Reservation') }}</a>
@endcan
@endsection
@section('content')
<div class="row g-3">
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Reservations Today'),
            'value' => $kpis['today'] ?? 0,
            'subtitle' => __('Today'),
            'accent' => '#3B82F6',
            'icon' => 'ti ti-calendar',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Confirmed'),
            'value' => $kpis['confirmed'] ?? 0,
            'subtitle' => __('Confirmed + Checked-in'),
            'accent' => '#10B981',
            'icon' => 'ti ti-check',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Pending'),
            'value' => $kpis['pending'] ?? 0,
            'subtitle' => __('Awaiting confirmation'),
            'accent' => '#F59E0B',
            'icon' => 'ti ti-clock',
        ])
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('No-Shows'),
            'value' => $kpis['no_shows'] ?? 0,
            'subtitle' => __('Pending but past arrival'),
            'accent' => '#EF4444',
            'icon' => 'ti ti-user-off',
        ])
    </div>
    <div class="col-xl-4 col-md-8 col-12">
        @include('hotelmanagement::partials.kpi_card', [
            'title' => __('Reservation Revenue'),
            'value' => Auth::user()->priceFormat($kpis['revenue'] ?? 0),
            'subtitle' => __('Confirmed/checked-in/-out folios'),
            'accent' => '#8B5CF6',
            'icon' => 'ti ti-cash',
        ])
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ __('All reservations') }}</h5>
            </div>
            <div class="card-body table-border-style">
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead><tr><th>{{ __('No') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Room') }}</th><th>{{ __('Dates') }}</th><th>{{ __('Source') }}</th><th>{{ __('Status') }}</th><th>{{ __('Payment') }}</th><th>{{ __('Action') }}</th></tr></thead>
                        <tbody>
                        @foreach($reservations as $r)
                            <tr>
                                <td>{{ $r->reservation_no }}</td>
                                <td>{{ trim(($r->guest->first_name ?? '').' '.($r->guest->last_name ?? '')) }}</td>
                                <td>{{ $r->room->room_no ?? '-' }}</td>
                                <td>{{ $r->check_in_date }} - {{ $r->check_out_date }}</td>
                                <td><span class="badge bg-light text-dark">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $r->source ?? 'manual'))) }}</span></td>
                                <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $r->status))) }}</td>
                                <td>
                                    @php $folio = $r->stay?->folio; @endphp
                                    @if($folio)
                                        @php $ps = $folio->paymentStatusLabel(); @endphp
                                        <span class="badge bg-{{ $ps === 'paid' ? 'success' : ($ps === 'partial' ? 'warning' : 'secondary') }}">{{ __(ucfirst($ps)) }}</span>
                                        <a class="small" href="{{ route('hotel.folios.show', $folio->id) }}">{{ __('Folio') }}</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.reservations.show', $r->id) }}" title="{{ __('View') }}"><i class="ti ti-eye"></i></a>
                                    @can('hotel.manage.reservations')
                                    <a class="btn btn-sm btn-info" href="{{ route('hotel.reservations.edit', $r->id) }}"><i class="ti ti-pencil text-white"></i></a>
                                    @if($r->status !== 'checked_in' && $r->status !== 'cancelled')
                                        {!! Form::open(['method' => 'POST', 'route' => ['hotel.frontdesk.check-in', $r->id], 'class' => 'd-inline']) !!}
                                        <button class="btn btn-sm btn-success">{{ __('Check-in') }}</button>
                                        {!! Form::close() !!}
                                    @endif
                                    @if($r->status === 'checked_in')
                                        <a href="{{ route('hotel.frontdesk.checkout', $r->id) }}" class="btn btn-sm btn-warning" title="{{ __('Check-out') }}">
                                            <i class="ti ti-logout me-1"></i>{{ __('Check-out') }}
                                        </a>
                                        <a href="{{ route('hotel.frontdesk.receipt', $r->id) }}" class="btn btn-sm btn-outline-success" title="{{ __('Payment') }}">
                                            <i class="ti ti-cash me-1"></i>{{ __('Payment') }}
                                        </a>
                                    @endif
                                    @else
                                    <span class="text-muted small">{{ __('View only') }}</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header"><h5 class="mb-0">{{ __('Recent Reservations') }}</h5></div>
            <div class="card-body p-0">
                @if($recent->isEmpty())
                    <p class="text-muted small p-3 mb-0">{{ __('No reservations yet.') }}</p>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($recent as $r)
                            @php
                                $g = $r->guest;
                                $name = $g ? trim(($g->first_name ?? '').' '.($g->last_name ?? '')) : __('Guest');
                            @endphp
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <div class="flex-grow-1" style="min-width:0;">
                                    <strong class="small d-block">{{ $name }}</strong>
                                    <small class="text-muted d-block">{{ $r->reservation_no }} · {{ $r->check_in_date }} → {{ $r->check_out_date }}</small>
                                </div>
                                <small class="text-muted text-nowrap">{{ __('Room') }} {{ $r->room->room_no ?? '—' }}</small>
                                <span class="badge bg-light text-dark">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $r->status))) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header"><h5 class="mb-0">{{ __('Booking Channels') }}</h5></div>
            <div class="card-body">
                @if($totalSource > 0)
                    <div id="hotel-channels-pie"></div>
                @else
                    <p class="text-muted small mb-0">{{ __('No source data yet.') }}</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header"><h5 class="mb-0">{{ __('Day Summary') }}</h5></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span class="text-muted small"><i class="ti ti-login me-1"></i>{{ __('Check-ins') }}</span><strong>{{ $daySummary['check_ins'] }}</strong></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted small"><i class="ti ti-logout me-1"></i>{{ __('Check-outs') }}</span><strong>{{ $daySummary['check_outs'] }}</strong></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted small"><i class="ti ti-calendar-plus me-1"></i>{{ __('Reservations') }}</span><strong>{{ $daySummary['reservations'] }}</strong></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted small">{{ __('Revenue') }}</span><strong>{{ Auth::user()->priceFormat($daySummary['revenue']) }}</strong></div>
                <div class="d-flex justify-content-between"><span class="text-muted small">{{ __('Occupancy rate') }}</span><strong>{{ $daySummary['occupancy_rate'] }}%</strong></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script-page')
<script>
(function () {
    var totalSource = {{ (int) $totalSource }};
    if (totalSource > 0 && document.querySelector('#hotel-channels-pie')) {
        var pieOptions = {
            chart: { type: 'donut', height: 240 },
            labels: @json($sourceLabels),
            series: @json($sourceSeries),
            legend: { position: 'right', fontSize: '12px' },
            colors: ['#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444', '#06B6D4'],
            dataLabels: { enabled: true, formatter: function (val, opts) { return opts.w.config.series[opts.seriesIndex]; } },
            plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: '{{ __("Total") }}' } } } } }
        };
        new ApexCharts(document.querySelector('#hotel-channels-pie'), pieOptions).render();
    }
})();
</script>
@endpush
