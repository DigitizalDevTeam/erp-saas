@extends('layouts.admin')
@section('page-title'){{ $reservation->reservation_no }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a></li>
    <li class="breadcrumb-item">{{ __('Detail') }}</li>
@endsection
@section('action-btn')
@if($canManage ?? false)
<a href="{{ route('hotel.reservations.edit', $reservation->id) }}" class="btn btn-sm btn-primary"><i class="ti ti-pencil"></i> {{ __('Edit') }}</a>
@endif
@if($reservation->status === \Modules\HotelManagement\Entities\HotelReservation::STATUS_CHECKED_IN)
<a href="{{ route('hotel.frontdesk.checkout', $reservation->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Check-out') }}</a>
@endif
@endsection
@section('content')
@php $folio = $reservation->stay?->folio; @endphp
<div class="row">
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3"><div class="card-body">
            <h5 class="mb-3">{{ __('Reservation') }} {{ $reservation->reservation_no }}</h5>
            <p class="mb-1"><strong>{{ __('Guest') }}:</strong>
                <a href="{{ route('hotel.guests.show', $reservation->guest->id) }}">{{ $reservation->guest->fullName() }}</a>
                @if($reservation->guest->is_vip)<span class="badge bg-warning text-dark ms-1">{{ __('VIP') }}</span>@endif
            </p>
            <p class="mb-1"><strong>{{ __('Status') }}:</strong> {{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $reservation->status))) }}</p>
            <p class="mb-1"><strong>{{ __('Check-in') }}:</strong> {{ $reservation->check_in_date }} {{ $reservation->check_in_at ? \Carbon\Carbon::parse($reservation->check_in_at)->format('H:i') : '' }}</p>
            <p class="mb-1"><strong>{{ __('Check-out') }}:</strong> {{ $reservation->check_out_date }} {{ $reservation->check_out_at ? \Carbon\Carbon::parse($reservation->check_out_at)->format('H:i') : '' }}</p>
            <p class="mb-1"><strong>{{ __('Room') }}:</strong> {{ $reservation->room->room_no ?? '—' }} ({{ $reservation->roomType->name ?? '—' }})</p>
            <p class="mb-1"><strong>{{ __('Rate') }}:</strong> {{ Auth::user()->priceFormat($reservation->rate_per_night) }} / {{ __($reservation->billing_mode === 'monthly' ? 'month' : 'night') }}</p>
            <p class="mb-1"><strong>{{ __('Quoted total') }}:</strong> {{ Auth::user()->priceFormat($totals['grand_total'] ?? 0) }} ({{ $totals['units'] ?? 0 }} {{ strtolower($totals['unit_label'] ?? '') }})</p>
            @if($reservation->group)
                <p class="mb-0"><strong>{{ __('Group') }}:</strong> <a href="{{ route('hotel.groups.show', $reservation->group->id) }}">{{ $reservation->group->name }}</a></p>
            @endif
            @if($reservation->notes)
                <hr><p class="small text-muted mb-0">{{ $reservation->notes }}</p>
            @endif
        </div></div>
        @if($folio)
        <div class="card shadow-sm"><div class="card-body">
            <h6>{{ __('Room bill') }}</h6>
            <p class="mb-1">{{ $folio->folio_no }} — {{ __(ucfirst($folio->paymentStatusLabel())) }}</p>
            <p class="mb-2"><strong>{{ __('Grand total') }}:</strong> {{ Auth::user()->priceFormat($folio->grand_total) }}</p>
            <a href="{{ route('hotel.folios.show', $folio->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Open folio') }}</a>
        </div></div>
        @endif
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm"><div class="card-header"><h6 class="mb-0">{{ __('Timeline') }}</h6></div>
        <div class="card-body">
            @if(count($timeline))
            <ul class="list-unstyled mb-0">
                @foreach($timeline as $event)
                <li class="d-flex gap-3 mb-3 pb-3 border-bottom">
                    <div class="text-muted"><i class="ti {{ $event['icon'] }}"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $event['label'] }}</div>
                        @if($event['detail'])<div class="small text-muted">{{ $event['detail'] }}</div>@endif
                    </div>
                    <div class="small text-muted text-nowrap">{{ $event['at'] }}</div>
                </li>
                @endforeach
            </ul>
            @else
            <p class="text-muted small mb-0">{{ __('No events yet.') }}</p>
            @endif
        </div></div>
    </div>
</div>
@endsection
