@extends('hotelmanagement::public.layout')
@section('title', __('Available rooms') . ' — ' . $site->display_name)

@php
    $nights = 1;
    try {
        $nights = max(1, \Carbon\Carbon::parse($checkIn)->diffInDays(\Carbon\Carbon::parse($checkOut)));
    } catch (\Throwable) {}
@endphp

@section('content')
<div class="hb-page-banner">
    <div class="hb-wrap">
        <h1>{{ __('Available rooms') }}</h1>
        <p>
            {{ \Carbon\Carbon::parse($checkIn)->format('M j') }} → {{ \Carbon\Carbon::parse($checkOut)->format('M j, Y') }}
            · {{ $nights }} {{ $nights === 1 ? __('night') : __('nights') }}
            · {{ $adults }} {{ __('adults') }}@if($children > 0), {{ $children }} {{ __('children') }}@endif
        </p>
    </div>
</div>

<div class="hb-wrap" style="padding-bottom:4rem;">
    <div class="hb-card hb-search-widget hb-search-widget--compact" style="margin-bottom:2rem;">
        @include('hotelmanagement::public.partials.search-widget', [
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'adults' => $adults,
            'children' => $children,
            'submitLabel' => __('Update search'),
            'compact' => true,
        ])
    </div>

    @if($error)
        <div class="hb-alert hb-alert-error">{{ $error }}</div>
    @endif

    @if(empty($results) && !$error)
        <div class="hb-card hb-empty-state">
            <div class="hb-empty-state__icon">🏨</div>
            <h2>{{ __('No availability') }}</h2>
            <p>{{ __('No rooms available for these dates. Try different dates or contact us.') }}</p>
            @if($site->contact_phone)
                <a href="tel:{{ $site->contact_phone }}" class="hb-btn hb-btn-outline">{{ $site->contact_phone }}</a>
            @endif
        </div>
    @else
        <p class="hb-results-count">
            {{ count($results) }} {{ count($results) === 1 ? __('room type available') : __('room types available') }}
        </p>
        <div class="hb-room-grid">
            @foreach($results as $room)
                @include('hotelmanagement::public.partials.room-card', [
                    'site' => $site,
                    'room' => $room,
                    'checkIn' => $checkIn,
                    'checkOut' => $checkOut,
                    'adults' => $adults,
                    'children' => $children,
                ])
            @endforeach
        </div>
    @endif
</div>
@endsection
