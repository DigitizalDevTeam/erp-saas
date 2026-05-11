@extends('layouts.admin')
@section('page-title'){{ __('Hotel — Customer report') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Customers') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
    @include('hotelmanagement::reports._date_filter', ['from' => $from, 'to' => $to])
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">{{ __('VIP guests') }}</small><h4 class="mb-0">{{ $vipCount }}</h4></div></div>
        <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">{{ __('Returning (2+ stays in range)') }}</small><h4 class="mb-0">{{ $returning }}</h4></div></div>
        <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">{{ __('New guest profiles') }}</small><h4 class="mb-0">{{ $newGuests }}</h4></div></div>
        <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">{{ __('Guests with alerts') }}</small><h4 class="mb-0">{{ $guestsWithAlerts }}</h4></div></div>
        <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">{{ __('Parking requests') }}</small><h4 class="mb-0">{{ $parkingRequests }}</h4></div></div>
        <div class="col-md-3"><div class="border rounded p-2"><small class="text-muted">{{ __('Reservations with ETA') }}</small><h4 class="mb-0">{{ $etaReservations }}</h4></div></div>
    </div>
    <h6>{{ __('Frequent guests (range)') }}</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Stays') }}</th><th>{{ __('VIP') }}</th></tr></thead>
            <tbody>
            @foreach($frequent as $g)
                <tr>
                    <td>{{ trim(($g->first_name ?? '').' '.($g->last_name ?? '')) }}</td>
                    <td>{{ $g->reservations_count }}</td>
                    <td>{{ $g->is_vip ? __('Yes') : __('No') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <h6 class="mt-3">{{ __('Origin (nationality)') }}</h6>
    <ul class="small">
        @foreach($byOrigin as $o)
            <li>{{ $o->nationality }} — {{ $o->c }}</li>
        @endforeach
    </ul>
</div></div>
@endsection
