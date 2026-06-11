@php
    $metaTitle = $site->meta_title ?: $site->display_name;
    $accent = $accent ?? ($site->accent_color ?? '#0d9488');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $metaTitle)</title>
    <meta name="description" content="{{ $site->meta_description ?: Str::limit(strip_tags($site->description ?? ''), 160) }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --hb-accent: {{ $accent }};
            --hb-accent-soft: color-mix(in srgb, {{ $accent }} 14%, #fff);
            --hb-accent-dark: color-mix(in srgb, {{ $accent }} 75%, #0f172a);
            --hb-bg: #faf9f7;
            --hb-surface: #ffffff;
            --hb-text: #1c1917;
            --hb-muted: #78716c;
            --hb-border: #e7e5e4;
            --hb-radius: 20px;
            --hb-radius-sm: 12px;
            --hb-shadow: 0 20px 50px rgba(28, 25, 23, 0.08);
            --hb-shadow-sm: 0 8px 24px rgba(28, 25, 23, 0.06);
            --hb-font: 'Inter', system-ui, sans-serif;
            --hb-serif: 'Cormorant Garamond', Georgia, serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: var(--hb-font);
            background: var(--hb-bg);
            color: var(--hb-text);
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--hb-accent-dark); text-decoration: none; }
        a:hover { color: var(--hb-accent); }
        img { max-width: 100%; height: auto; display: block; }
        .hb-wrap { max-width: 1180px; margin: 0 auto; padding: 0 1.5rem; }
        .hb-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }

        /* Header */
        .hb-header {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255,255,255,.88);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(231,229,228,.8);
        }
        .hb-nav {
            display: flex; align-items: center; justify-content: space-between;
            padding: .9rem 0; gap: 1rem;
        }
        .hb-brand { display: flex; align-items: center; gap: .85rem; text-decoration: none; color: inherit; }
        .hb-brand img { height: 48px; width: auto; border-radius: 10px; object-fit: contain; }
        .hb-brand-name {
            font-family: var(--hb-serif); font-size: 1.45rem; font-weight: 700;
            letter-spacing: .02em; line-height: 1.15; margin: 0;
        }
        .hb-nav-links { display: flex; align-items: center; gap: 1.25rem; }
        .hb-nav-link { font-size: .9rem; font-weight: 500; color: var(--hb-muted); }
        .hb-nav-link:hover { color: var(--hb-text); }

        /* Buttons */
        .hb-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .8rem 1.5rem; border-radius: 999px; font-weight: 600; font-size: .92rem;
            border: none; cursor: pointer; text-decoration: none; transition: all .2s ease;
            font-family: inherit;
        }
        .hb-btn-primary {
            background: linear-gradient(135deg, var(--hb-accent), var(--hb-accent-dark));
            color: #fff; box-shadow: 0 8px 22px color-mix(in srgb, var(--hb-accent) 35%, transparent);
        }
        .hb-btn-primary:hover { transform: translateY(-2px); color: #fff; box-shadow: 0 12px 28px color-mix(in srgb, var(--hb-accent) 40%, transparent); }
        .hb-btn-outline { background: #fff; border: 1.5px solid var(--hb-border); color: var(--hb-text); }
        .hb-btn-outline:hover { border-color: var(--hb-accent); color: var(--hb-accent-dark); }
        .hb-btn-light { background: #fff; color: var(--hb-accent-dark); font-weight: 700; }
        .hb-btn-light:hover { color: var(--hb-accent-dark); transform: translateY(-2px); }
        .hb-btn-block { width: 100%; }

        /* Landing hero */
        .hb-landing-hero {
            position: relative; min-height: min(92vh, 880px);
            background-size: cover; background-position: center;
            display: flex; align-items: center;
        }
        .hb-landing-hero__overlay {
            position: absolute; inset: 0;
            background: linear-gradient(105deg, rgba(12,10,9,.88) 0%, rgba(12,10,9,.55) 42%, rgba(12,10,9,.25) 100%);
        }
        .hb-landing-hero__inner {
            position: relative; z-index: 2; width: 100%;
            display: grid; grid-template-columns: 1.1fr .9fr; gap: 2.5rem; align-items: center;
            padding: 4rem 1.5rem 3rem;
        }
        .hb-landing-hero__title {
            font-family: var(--hb-serif); font-size: clamp(2.75rem, 6vw, 4.5rem);
            font-weight: 700; color: #fff; line-height: 1.05; margin: 0 0 1rem;
        }
        .hb-landing-hero__lead { color: rgba(255,255,255,.9); font-size: 1.12rem; max-width: 520px; margin: 0 0 1.75rem; }
        .hb-hero-stats { display: flex; gap: 2rem; flex-wrap: wrap; }
        .hb-hero-stats div strong { display: block; color: #fff; font-size: 1.35rem; font-family: var(--hb-serif); }
        .hb-hero-stats div span { color: rgba(255,255,255,.7); font-size: .78rem; text-transform: uppercase; letter-spacing: .1em; }
        .hb-booking-panel {
            background: rgba(255,255,255,.97); border-radius: calc(var(--hb-radius) + 4px);
            padding: 1.75rem; box-shadow: 0 30px 80px rgba(0,0,0,.28); border: 1px solid rgba(255,255,255,.6);
        }
        .hb-booking-panel h2 { font-family: var(--hb-serif); margin: 0 0 .25rem; font-size: 1.75rem; }
        .hb-booking-panel > p { margin: 0 0 1.25rem; color: var(--hb-muted); font-size: .9rem; }
        .hb-booking-panel .hb-search-widget { padding: 0; }
        .hb-booking-panel .hb-search-grid { grid-template-columns: 1fr 1fr; }
        .hb-booking-panel .hb-search-submit { grid-column: 1 / -1; }

        .hb-trust-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
        .hb-trust-item {
            padding: 2rem 1.5rem; border-radius: var(--hb-radius); background: #fff;
            border: 1px solid var(--hb-border); text-align: center;
        }
        .hb-trust-icon {
            width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            background: var(--hb-accent-soft); font-size: 1.5rem;
        }
        .hb-trust-item h3 { margin: 0 0 .5rem; font-size: 1.05rem; }
        .hb-trust-item p { margin: 0; color: var(--hb-muted); font-size: .92rem; }

        .hb-about-split { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; align-items: center; }
        .hb-about-split__media { border-radius: var(--hb-radius); overflow: hidden; box-shadow: var(--hb-shadow); }
        .hb-about-split__media img { width: 100%; height: 100%; min-height: 360px; object-fit: cover; }
        .hb-about-copy { color: var(--hb-muted); font-size: 1.05rem; line-height: 1.85; margin: 0; }

        .hb-cta-band {
            background: linear-gradient(135deg, var(--hb-accent-dark), #1c1917);
            padding: 3.5rem 0; margin-top: 0;
        }
        .hb-cta-band__inner { display: flex; align-items: center; justify-content: space-between; gap: 2rem; flex-wrap: wrap; }
        .hb-cta-band h2 { font-family: var(--hb-serif); color: #fff; margin: 0 0 .35rem; font-size: 2rem; }
        .hb-cta-band p { color: rgba(255,255,255,.8); margin: 0; }

        .hb-empty-state { padding: 3.5rem 2rem; text-align: center; }
        .hb-empty-state__icon { font-size: 2.5rem; margin-bottom: 1rem; opacity: .6; }
        .hb-empty-state h2 { font-family: var(--hb-serif); margin: 0 0 .5rem; }
        .hb-results-count { color: var(--hb-muted); margin: 0 0 1.25rem; font-size: .92rem; }

        /* Cards & forms */
        .hb-card {
            background: var(--hb-surface); border-radius: var(--hb-radius);
            border: 1px solid var(--hb-border); box-shadow: var(--hb-shadow-sm);
        }
        .hb-field label {
            display: block; font-size: .72rem; font-weight: 700; color: var(--hb-muted);
            margin-bottom: .4rem; text-transform: uppercase; letter-spacing: .08em;
        }
        .hb-field input, .hb-field select, .hb-field textarea {
            width: 100%; padding: .78rem 1rem; border: 1.5px solid var(--hb-border);
            border-radius: var(--hb-radius-sm); font: inherit; background: #fff; color: var(--hb-text);
            transition: border-color .15s, box-shadow .15s;
        }
        .hb-field input:focus, .hb-field select:focus, .hb-field textarea:focus {
            outline: none; border-color: var(--hb-accent);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--hb-accent) 15%, transparent);
        }

        /* Search widget */
        .hb-search-widget { padding: 1.35rem 1.5rem; }
        .hb-search-grid {
            display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; align-items: end;
        }
        .hb-search-widget--compact .hb-search-grid { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }

        /* Hero */
        .hb-hero-full {
            position: relative; min-height: clamp(520px, 72vh, 720px);
            display: flex; align-items: flex-end; overflow: hidden;
            background: linear-gradient(145deg, var(--hb-accent-dark) 0%, #1c1917 55%, #0c0a09 100%);
        }
        .hb-hero-full__bg {
            position: absolute; inset: 0; background-size: cover; background-position: center;
            opacity: .5; transform: scale(1.02);
        }
        .hb-hero-full__overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(12,10,9,.92) 0%, rgba(12,10,9,.35) 45%, rgba(12,10,9,.15) 100%);
        }
        .hb-hero-full__content {
            position: relative; z-index: 2; width: 100%; padding: 3rem 0 5.5rem;
        }
        .hb-hero-eyebrow {
            display: inline-block; padding: .35rem .85rem; border-radius: 999px;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2);
            color: rgba(255,255,255,.9); font-size: .78rem; font-weight: 600;
            letter-spacing: .12em; text-transform: uppercase; margin-bottom: 1rem;
        }
        .hb-hero-title {
            font-family: var(--hb-serif); font-size: clamp(2.5rem, 6vw, 4.25rem);
            font-weight: 700; color: #fff; line-height: 1.08; margin: 0 0 1rem; max-width: 14ch;
        }
        .hb-hero-sub { color: rgba(255,255,255,.88); font-size: 1.12rem; max-width: 520px; margin: 0; }
        .hb-hero-search-float {
            margin-top: -3.5rem; position: relative; z-index: 10; margin-bottom: 3rem;
        }

        /* Sections */
        .hb-section { padding: 4.5rem 0; }
        .hb-section--alt { background: #fff; border-top: 1px solid var(--hb-border); border-bottom: 1px solid var(--hb-border); }
        .hb-section-head { text-align: center; max-width: 640px; margin: 0 auto 2.75rem; }
        .hb-section-eyebrow {
            font-size: .75rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
            color: var(--hb-accent); margin-bottom: .5rem;
        }
        .hb-section-title {
            font-family: var(--hb-serif); font-size: clamp(1.85rem, 4vw, 2.75rem);
            font-weight: 700; margin: 0 0 .75rem; line-height: 1.15;
        }
        .hb-section-desc { color: var(--hb-muted); margin: 0; font-size: 1.05rem; }

        /* Features grid */
        .hb-features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
        .hb-feature {
            padding: 1.75rem; border-radius: var(--hb-radius); background: #fff;
            border: 1px solid var(--hb-border); text-align: center;
        }
        .hb-feature-icon {
            width: 52px; height: 52px; border-radius: 14px; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            background: var(--hb-accent-soft); color: var(--hb-accent-dark); font-size: 1.4rem;
        }
        .hb-feature h3 { margin: 0 0 .5rem; font-size: 1.05rem; }
        .hb-feature p { margin: 0; color: var(--hb-muted); font-size: .92rem; }

        /* Amenities */
        .hb-amenity-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1rem; }
        .hb-amenity-item {
            display: flex; align-items: center; gap: .65rem; padding: .85rem 1rem;
            background: #fff; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-sm);
            font-size: .9rem; font-weight: 500;
        }
        .hb-amenity-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--hb-accent); flex-shrink: 0; }

        /* Gallery */
        .hb-gallery { display: grid; grid-template-columns: repeat(4, 1fr); grid-auto-rows: 180px; gap: .75rem; }
        .hb-gallery-item { border-radius: var(--hb-radius-sm); overflow: hidden; }
        .hb-gallery-item img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s; }
        .hb-gallery-item:hover img { transform: scale(1.05); }
        .hb-gallery-item:first-child { grid-row: span 2; grid-column: span 2; }

        /* Info strip */
        .hb-info-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
        .hb-info-item {
            text-align: center; padding: 1.5rem 1rem; background: #fff;
            border-radius: var(--hb-radius); border: 1px solid var(--hb-border);
        }
        .hb-info-item strong { display: block; font-size: .78rem; text-transform: uppercase; letter-spacing: .08em; color: var(--hb-muted); margin-bottom: .35rem; }
        .hb-info-item span { font-size: 1.1rem; font-weight: 600; }

        /* Room cards */
        .hb-room-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .hb-room-card { overflow: hidden; display: flex; flex-direction: column; transition: transform .25s, box-shadow .25s; background: #fff; border-radius: var(--hb-radius); border: 1px solid var(--hb-border); }
        .hb-room-card:hover { transform: translateY(-6px); box-shadow: var(--hb-shadow); }
        .hb-room-card__media { height: 240px; position: relative; overflow: hidden; background: #e7e5e4; }
        .hb-room-img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
        .hb-room-card:hover .hb-room-img { transform: scale(1.06); }
        .hb-room-card__media::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background: linear-gradient(to top, rgba(0,0,0,.35), transparent 55%);
        }
        .hb-room-card__tags { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .5rem; }
        .hb-room-card__badge {
            position: absolute; top: 1rem; right: 1rem; z-index: 1;
            padding: .35rem .75rem; border-radius: 999px; font-size: .75rem; font-weight: 700;
            background: rgba(255,255,255,.95); color: var(--hb-text);
        }
        .hb-room-card__body { padding: 1.35rem; flex: 1; display: flex; flex-direction: column; }
        .hb-room-card__title { font-family: var(--hb-serif); font-size: 1.45rem; font-weight: 700; margin: 0 0 .5rem; }
        .hb-room-card__meta { font-size: .85rem; color: var(--hb-muted); margin-bottom: 1rem; }
        .hb-room-card__footer { margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--hb-border); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .hb-price { font-size: 1.5rem; font-weight: 700; color: var(--hb-accent-dark); line-height: 1.2; }
        .hb-price small { display: block; font-size: .78rem; font-weight: 500; color: var(--hb-muted); }
        .hb-badge {
            display: inline-block; padding: .25rem .7rem; border-radius: 999px;
            font-size: .72rem; font-weight: 700; background: var(--hb-accent-soft); color: var(--hb-accent-dark);
        }

        /* Page banner (search etc) */
        .hb-page-banner {
            background: linear-gradient(135deg, var(--hb-accent-dark), #1c1917);
            color: #fff; padding: 2.5rem 0; margin-bottom: 2rem;
        }
        .hb-page-banner h1 { font-family: var(--hb-serif); font-size: 2.25rem; margin: 0 0 .35rem; }
        .hb-page-banner p { margin: 0; opacity: .85; }

        /* Alerts & steps */
        .hb-alert { padding: 1rem 1.15rem; border-radius: var(--hb-radius-sm); margin-bottom: 1rem; font-size: .92rem; }
        .hb-alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .hb-alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .hb-steps { display: flex; gap: .5rem; margin-bottom: 1.75rem; flex-wrap: wrap; }
        .hb-step { padding: .45rem 1rem; border-radius: 999px; font-size: .8rem; font-weight: 600; background: #fff; border: 1px solid var(--hb-border); color: var(--hb-muted); }
        .hb-step.active { background: var(--hb-accent); border-color: var(--hb-accent); color: #fff; }

        /* Footer */
        .hb-footer {
            background: #1c1917; color: #a8a29e; margin-top: 4rem; padding: 3.5rem 0 2rem;
        }
        .hb-footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 2.5rem; margin-bottom: 2.5rem; }
        .hb-footer h4 { color: #fff; font-family: var(--hb-serif); font-size: 1.25rem; margin: 0 0 1rem; }
        .hb-footer p, .hb-footer a { color: #a8a29e; font-size: .92rem; margin: 0 0 .5rem; }
        .hb-footer a:hover { color: #fff; }
        .hb-footer-bottom {
            border-top: 1px solid #292524; padding-top: 1.5rem;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
            font-size: .82rem;
        }

        @media (max-width: 900px) {
            .hb-landing-hero__inner { grid-template-columns: 1fr; padding-top: 2.5rem; }
            .hb-search-grid { grid-template-columns: 1fr 1fr; }
            .hb-search-submit { grid-column: 1 / -1; }
            .hb-trust-grid, .hb-about-split { grid-template-columns: 1fr; }
            .hb-footer-grid { grid-template-columns: 1fr; }
            .hb-gallery { grid-template-columns: 1fr 1fr; grid-auto-rows: 140px; }
            .hb-gallery-item:first-child { grid-row: span 1; grid-column: span 2; }
            .hb-nav-links .hb-nav-link { display: none; }
        }
        @media (max-width: 600px) {
            .hb-search-grid, .hb-booking-panel .hb-search-grid { grid-template-columns: 1fr; }
            .hb-landing-hero { min-height: auto; }
        }
    </style>
    @stack('styles')
</head>
<body>
    <header class="hb-header">
        <div class="hb-wrap">
            <nav class="hb-nav">
                <a href="{{ route('hotel.public.home', ['hotelSlug' => $site->slug]) }}" class="hb-brand">
                    @if($site->logoUrl())
                        <img src="{{ $site->logoUrl() }}" alt="{{ $site->display_name }}">
                    @endif
                    <span class="hb-brand-name">{{ $site->display_name }}</span>
                </a>
                <div class="hb-nav-links">
                    <a href="{{ route('hotel.public.home', ['hotelSlug' => $site->slug]) }}#rooms" class="hb-nav-link">{{ __('Rooms') }}</a>
                    <a href="{{ route('hotel.public.home', ['hotelSlug' => $site->slug]) }}#about" class="hb-nav-link">{{ __('About') }}</a>
                    <a href="{{ route('hotel.public.search', ['hotelSlug' => $site->slug]) }}" class="hb-btn hb-btn-primary">{{ __('Book now') }}</a>
                </div>
            </nav>
        </div>
    </header>

    @if(session('error'))
        <div class="hb-wrap" style="padding-top:1rem;"><div class="hb-alert hb-alert-error">{{ session('error') }}</div></div>
    @endif
    @if(session('success'))
        <div class="hb-wrap" style="padding-top:1rem;"><div class="hb-alert hb-alert-success">{{ session('success') }}</div></div>
    @endif

    @yield('content')

    <footer class="hb-footer">
        <div class="hb-wrap">
            <div class="hb-footer-grid">
                <div>
                    <h4>{{ $site->display_name }}</h4>
                    @if($site->tagline)<p>{{ $site->tagline }}</p>@endif
                    @if($site->address)<p>{{ $site->address }}</p>@endif
                </div>
                <div>
                    <h4>{{ __('Contact') }}</h4>
                    @if($site->contact_phone)<p><a href="tel:{{ $site->contact_phone }}">{{ $site->contact_phone }}</a></p>@endif
                    @if($site->contact_email)<p><a href="mailto:{{ $site->contact_email }}">{{ $site->contact_email }}</a></p>@endif
                </div>
                <div>
                    <h4>{{ __('Hotel hours') }}</h4>
                    <p>{{ __('Check-in') }}: {{ $site->check_in_time ?? '14:00' }}</p>
                    <p>{{ __('Check-out') }}: {{ $site->check_out_time ?? '11:00' }}</p>
                </div>
            </div>
            <div class="hb-footer-bottom">
                <span>&copy; {{ date('Y') }} {{ $site->display_name }}</span>
                <span>{{ __('Secure direct booking') }} · {{ config('app.name') }}</span>
            </div>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
