@extends('layouts.admin')
@section('page-title'){{ __('Hotel — Operational report') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Operational') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
    @include('hotelmanagement::reports._date_filter', ['from' => $from, 'to' => $to])
    <div class="mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.reports.operational.export', request()->query()) }}">{{ __('Export Excel') }}</a>
        <a class="btn btn-sm btn-outline-danger" href="{{ route('hotel.reports.operational.pdf', request()->query()) }}">{{ __('Export PDF') }}</a>
    </div>
    <p class="text-muted small">{{ __('Rooms total') }}: {{ $roomsTotal }} · {{ __('Avg stay (nights) in range') }}: {{ $avgStay }}</p>
    <div class="table-responsive">
        <table class="table table-striped table-sm">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Occupancy %') }}</th><th>{{ __('Check-ins') }}</th><th>{{ __('Check-outs') }}</th></tr></thead>
            <tbody>
            @foreach($daily as $row)
                <tr>
                    <td><a href="{{ route('hotel.reservations.index') }}">{{ $row['date'] }}</a></td>
                    <td>{{ $row['occupancy_pct'] }}%</td>
                    <td>{{ $row['check_ins'] }}</td>
                    <td>{{ $row['check_outs'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <h6>{{ __('Room status snapshot') }}</h6>
    <ul class="list-inline">
        @foreach($roomStatus as $st => $c)
            <li class="list-inline-item"><strong>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $st))) }}:</strong> {{ $c }}</li>
        @endforeach
    </ul>
</div></div>
@endsection
