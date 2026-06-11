@php
    $bookUrl = $bookUrl ?? route('hotel.public.book', [
        'hotelSlug' => $site->slug,
        'room_type_id' => $room['id'],
        'check_in' => $checkIn ?? now()->addDay()->toDateString(),
        'check_out' => $checkOut ?? now()->addDays(2)->toDateString(),
        'adults' => $adults ?? 2,
        'children' => $children ?? 0,
    ]);
    $ctaLabel = $ctaLabel ?? __('Select room');
@endphp
<article class="hb-room-card">
    <div class="hb-room-card__media">
        @include('hotelmanagement::public.partials.room-image', [
            'imageUrl' => $room['image_url'] ?? null,
            'roomName' => $room['name'],
            'alt' => $room['name'],
        ])
        @if(!empty($room['available_rooms']))
            <span class="hb-room-card__badge">{{ $room['available_rooms'] }} {{ __('available') }}</span>
        @endif
    </div>
    <div class="hb-room-card__body">
        <h3 class="hb-room-card__title">{{ $room['name'] }}</h3>
        <p class="hb-room-card__meta">
            {{ !empty($room['description']) ? Str::limit($room['description'], 130) : __('Comfortable accommodation tailored to your stay.') }}
        </p>
        <div class="hb-room-card__tags">
            <span class="hb-badge">{{ __('Up to') }} {{ $room['max_occupancy'] }} {{ __('guests') }}</span>
            @if(!empty($room['rate_plan_name']))
                <span class="hb-badge">{{ $room['rate_plan_name'] }}</span>
            @endif
        </div>
        <div class="hb-room-card__footer">
            <div class="hb-price">
                {{ $hbPrice($room['subtotal'] ?? ($room['from_price'] ?? 0)) }}
                @if(!empty($room['units']))
                    <small>{{ $room['units'] }} {{ strtolower($room['unit_label'] ?? __('nights')) }}</small>
                @else
                    <small>{{ __('from') }} / {{ __('night') }}</small>
                @endif
            </div>
            <a href="{{ $bookUrl }}" class="hb-btn hb-btn-primary">{{ $ctaLabel }}</a>
        </div>
    </div>
</article>
