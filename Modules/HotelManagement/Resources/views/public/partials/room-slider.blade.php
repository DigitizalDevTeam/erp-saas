@php
    $sliderUrls = $sliderUrls ?? ($room['slider_urls'] ?? []);
    if (empty($sliderUrls) && !empty($room['image_url'] ?? null)) {
        $sliderUrls = [$room['image_url']];
    }
    if (empty($sliderUrls) && !empty($imageUrls ?? null)) {
        $sliderUrls = $imageUrls;
    }

    $fallbacks = [
        'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=900&q=80',
        'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=900&q=80',
    ];
    $seed = isset($roomName) ? abs(crc32((string) $roomName)) : 0;

    if ($sliderUrls === []) {
        $sliderUrls = [$fallbacks[$seed % count($fallbacks)]];
    }

    $sliderId = 'hb-slider-' . substr(md5(($roomName ?? 'room') . implode('|', $sliderUrls)), 0, 10);
    $slideCount = count($sliderUrls);
@endphp

<div class="hb-room-slider" id="{{ $sliderId }}" data-hb-slider data-slide-count="{{ $slideCount }}">
    <div class="hb-room-slider__viewport">
        @foreach($sliderUrls as $index => $url)
            <div class="hb-room-slider__slide {{ $index === 0 ? 'is-active' : '' }}" data-slide-index="{{ $index }}">
                <img src="{{ $url }}" alt="{{ $alt ?? $roomName ?? '' }}" class="hb-room-img" loading="{{ $index === 0 ? 'eager' : 'lazy' }}">
            </div>
        @endforeach
    </div>

    @if($slideCount > 1)
        <button type="button" class="hb-room-slider__nav hb-room-slider__nav--prev" aria-label="{{ __('Previous image') }}">‹</button>
        <button type="button" class="hb-room-slider__nav hb-room-slider__nav--next" aria-label="{{ __('Next image') }}">›</button>
        <div class="hb-room-slider__dots">
            @foreach($sliderUrls as $index => $url)
                <button type="button" class="hb-room-slider__dot {{ $index === 0 ? 'is-active' : '' }}" data-go-to="{{ $index }}" aria-label="{{ __('Slide') }} {{ $index + 1 }}"></button>
            @endforeach
        </div>
    @endif
</div>
