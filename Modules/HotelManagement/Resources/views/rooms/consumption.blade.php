@extends('layouts.admin')
@section('page-title'){{ __('Room consumption') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rooms.map') }}">{{ __('Room map') }}</a></li>
    <li class="breadcrumb-item">{{ __('Room') }} {{ $room->room_no }}</li>
@endsection
@section('content')
@php
    $guest = $stay->reservation->guest ?? null;
    $guestName = $guest ? trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) : '—';
@endphp
<div class="row">
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h5 class="mb-2">{{ __('Room') }} {{ $room->room_no }}</h5>
                <p class="mb-1"><strong>{{ __('Guest') }}:</strong> {{ $guestName }}</p>
                <p class="mb-1"><strong>{{ __('Folio') }}:</strong>
                    <a href="{{ route('hotel.folios.show', $folio->id) }}">{{ $folio->folio_no }}</a>
                </p>
                <p class="mb-0 text-muted small">{{ __('Charges post to the guest room bill immediately.') }}</p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0">{{ __('Add consumption') }}</h6></div>
            <div class="card-body">
                @include('hotelmanagement::partials.consumption_form', [
                    'formAction' => route('hotel.rooms.consumption.store', $room->id),
                    'chargeTypes' => $chargeTypes,
                    'minibarItems' => $minibarItems,
                    'redirectTo' => 'folio',
                ])
            </div>
        </div>
        <a href="{{ route('hotel.rooms.map') }}" class="btn btn-secondary mt-2">{{ __('Back to room map') }}</a>
        <a href="{{ route('hotel.folios.show', $folio->id) }}" class="btn btn-outline-primary mt-2">{{ __('View room bill') }}</a>
    </div>
</div>
@endsection
