@extends('hotelmanagement::public.layout')
@section('title', $site->display_name . ' — ' . __('Book your stay'))

@php
    $heroImage = $site->coverUrl() ?: 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=2000&q=85';
@endphp

@section('content')
<section class="hb-landing-hero" style="background-image:url('{{ $heroImage }}')">
    <div class="hb-landing-hero__overlay"></div>
    <div class="hb-wrap hb-landing-hero__inner">
        <div class="hb-landing-hero__copy">
            <span class="hb-hero-eyebrow">{{ __('Official direct booking') }}</span>
            <h1 class="hb-landing-hero__title">{{ $site->display_name }}</h1>
            <p class="hb-landing-hero__lead">
                @if($site->tagline)
                    {{ $site->tagline }}
                @else
                    {{ __('Luxury comfort, seamless booking — reserve your perfect stay in minutes with instant confirmation.') }}
                @endif
            </p>
            <div class="hb-hero-stats">
                <div><strong>{{ $site->check_in_time ?? '14:00' }}</strong><span>{{ __('Check-in') }}</span></div>
                <div><strong>{{ $site->check_out_time ?? '11:00' }}</strong><span>{{ __('Check-out') }}</span></div>
                <div><strong>24/7</strong><span>{{ __('Support') }}</span></div>
            </div>
        </div>
        <div class="hb-landing-hero__book">
            <div class="hb-booking-panel">
                <h2>{{ __('Find your room') }}</h2>
                <p>{{ __('Best available rate · No booking fees') }}</p>
                @include('hotelmanagement::public.partials.search-widget')
            </div>
        </div>
    </div>
</section>

@if(($featuredRoomTypes ?? collect())->isNotEmpty())
<section class="hb-section" id="rooms">
    <div class="hb-wrap">
        <div class="hb-section-head">
            <div class="hb-section-eyebrow">{{ __('Accommodations') }}</div>
            <h2 class="hb-section-title">{{ __('Our rooms & suites') }}</h2>
            <p class="hb-section-desc">{{ __('Handpicked spaces designed for rest, work, and unforgettable stays.') }}</p>
        </div>
        <div class="hb-room-grid">
            @foreach($featuredRoomTypes as $room)
                @include('hotelmanagement::public.partials.room-card', [
                    'site' => $site,
                    'room' => $room,
                    'ctaLabel' => __('Check rates'),
                    'bookUrl' => route('hotel.public.search', ['hotelSlug' => $site->slug]),
                ])
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="hb-section hb-section--alt">
    <div class="hb-wrap">
        <div class="hb-trust-grid">
            <div class="hb-trust-item">
                <div class="hb-trust-icon">✦</div>
                <h3>{{ __('Best direct rate') }}</h3>
                <p>{{ __('Book here for our lowest published rate — no third-party markups.') }}</p>
            </div>
            <div class="hb-trust-item">
                <div class="hb-trust-icon">⚡</div>
                <h3>{{ __('Instant confirmation') }}</h3>
                <p>{{ __('Your reservation syncs immediately with our front desk team.') }}</p>
            </div>
            <div class="hb-trust-item">
                <div class="hb-trust-icon">🔒</div>
                <h3>{{ __('Secure checkout') }}</h3>
                <p>{{ $site->payment_mode === 'prepay' ? __('Stripe-secured online payment.') : __('Flexible pay-at-hotel on arrival.') }}</p>
            </div>
        </div>
    </div>
</section>

@if($site->description)
<section class="hb-section" id="about">
    <div class="hb-wrap hb-about-split">
        <div class="hb-about-split__media">
            @if($site->coverUrl())
                <img src="{{ $site->coverUrl() }}" alt="{{ $site->display_name }}">
            @else
                <img src="https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?auto=format&fit=crop&w=1000&q=80" alt="{{ $site->display_name }}">
            @endif
        </div>
        <div class="hb-about-split__text">
            <div class="hb-section-eyebrow">{{ __('About the property') }}</div>
            <h2 class="hb-section-title" style="text-align:left;margin-bottom:1rem;">{{ __('Welcome to') }} {{ $site->display_name }}</h2>
            <p class="hb-about-copy">{!! nl2br(e($site->description)) !!}</p>
            <a href="{{ route('hotel.public.search', ['hotelSlug' => $site->slug]) }}" class="hb-btn hb-btn-primary" style="margin-top:1.25rem;">{{ __('View availability') }}</a>
        </div>
    </div>
</section>
@endif

@php $gallery = $site->galleryUrls(); @endphp
@if(!empty($gallery))
<section class="hb-section hb-section--alt">
    <div class="hb-wrap">
        <div class="hb-section-head">
            <div class="hb-section-eyebrow">{{ __('Gallery') }}</div>
            <h2 class="hb-section-title">{{ __('Experience the atmosphere') }}</h2>
        </div>
        <div class="hb-gallery">
            @foreach(array_slice($gallery, 0, 5) as $url)
                <div class="hb-gallery-item"><img src="{{ $url }}" alt="{{ $site->display_name }}"></div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if(!empty($site->amenities))
<section class="hb-section" id="amenities">
    <div class="hb-wrap">
        <div class="hb-section-head">
            <div class="hb-section-eyebrow">{{ __('Facilities') }}</div>
            <h2 class="hb-section-title">{{ __('Amenities & services') }}</h2>
        </div>
        <div class="hb-amenity-grid">
            @foreach($site->amenities as $amenity)
                <div class="hb-amenity-item"><span class="hb-amenity-dot"></span>{{ $amenity }}</div>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="hb-cta-band">
    <div class="hb-wrap hb-cta-band__inner">
        <div>
            <h2>{{ __('Plan your stay today') }}</h2>
            <p>{{ __('Limited availability — secure your preferred room now.') }}</p>
        </div>
        <a href="{{ route('hotel.public.search', ['hotelSlug' => $site->slug]) }}" class="hb-btn hb-btn-light">{{ __('Check availability') }}</a>
    </div>
</section>
@endsection
