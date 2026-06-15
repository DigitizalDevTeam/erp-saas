@extends('layouts.admin')
@section('page-title'){{ __('Hotel — Occupancy report') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reports.index') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Occupancy') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
    @include('hotelmanagement::reports._nav')
    @include('hotelmanagement::reports._date_filter', ['from' => $from, 'to' => $to])
    <p class="text-muted small">{{ __('Rooms total') }}: {{ $roomsTotal }}</p>
    <div class="row">
        <div class="col-lg-8 mb-3">
            <h6>{{ __('Occupancy heat (daily %)') }}</h6>
            <div id="hotel-occ-heatmap" style="min-height:280px;"></div>
        </div>
        <div class="col-lg-4">
            <h6>{{ __('Busiest check-in days') }}</h6>
            <ul class="small">
                @foreach($busiest as $b)
                    <li>{{ $b->d }} — {{ $b->c }} {{ __('arrivals') }}</li>
                @endforeach
            </ul>
            <h6>{{ __('Top rooms') }}</h6>
            <ul class="small">
                @foreach($topRooms as $tr)
                    <li>{{ __('Room') }} #{{ $tr->hotel_room_id }} — {{ $tr->c }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    <h6>{{ __('Guests by nationality') }}</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>{{ __('Nationality') }}</th><th>{{ __('Count') }}</th></tr></thead>
            <tbody>
            @foreach($nationality as $n)
                <tr><td>{{ $n->nationality }}</td><td>{{ $n->c }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection
@push('script-page')
<script>
(function () {
    var heatmap = @json($heatmap);
    var categories = heatmap.map(function (h) { return h.date; });
    var series = [{ name: '{{ __("Occupancy %") }}', data: heatmap.map(function (h) { return h.rate; }) }];
    var options = {
        chart: { type: 'bar', height: 280, toolbar: { show: false } },
        plotOptions: { bar: { borderRadius: 2, columnWidth: '85%' } },
        dataLabels: { enabled: false },
        series: series,
        xaxis: { categories: categories, labels: { rotate: -45, hideOverlappingLabels: true } },
        colors: ['#6366F1'],
        grid: { strokeDashArray: 4 }
    };
    if (document.querySelector('#hotel-occ-heatmap')) {
        new ApexCharts(document.querySelector('#hotel-occ-heatmap'), options).render();
    }
})();
</script>
@endpush
