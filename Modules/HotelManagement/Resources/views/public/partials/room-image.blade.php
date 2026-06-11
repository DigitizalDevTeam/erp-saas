@php
    $fallbacks = [
        'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=800&q=80',
    ];
    $seed = isset($roomName) ? crc32((string) $roomName) : 0;
    $src = $imageUrl ?: $fallbacks[abs($seed) % count($fallbacks)];
@endphp
<img src="{{ $src }}" alt="{{ $alt ?? '' }}" class="{{ $class ?? 'hb-room-img' }}" loading="lazy">
