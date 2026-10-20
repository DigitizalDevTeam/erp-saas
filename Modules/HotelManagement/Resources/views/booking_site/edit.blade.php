@extends('layouts.admin')
@section('page-title'){{ __('Booking website') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Booking website') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <p class="text-muted small mb-4">{{ __('Configure your public direct booking website. Guests book at') }} <code>/{{ $site->slug ?: 'your-slug' }}</code> {{ __('on this platform.') }}</p>

                {!! Form::open(['route' => 'hotel.booking-site.update', 'method' => 'POST', 'files' => true, 'class' => 'row g-3']) !!}

                <div class="col-12"><h6 class="text-uppercase text-muted small fw-bold">{{ __('Public URL') }}</h6></div>
                <div class="col-md-8">
                    {!! Form::label('slug', __('URL slug')) !!}
                    <div class="input-group">
                        <span class="input-group-text">{{ url('/') }}/</span>
                        {!! Form::text('slug', old('slug', $site->slug), ['class' => 'form-control', 'placeholder' => 'matolavillage', 'pattern' => '[a-z0-9\-]+']) !!}
                    </div>
                    <small class="text-muted">{{ __('Lowercase letters, numbers and hyphens only.') }}</small>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        {!! Form::checkbox('is_published', 1, old('is_published', $site->is_published), ['class' => 'form-check-input', 'id' => 'is_published']) !!}
                        {!! Form::label('is_published', __('Published (live)'), ['class' => 'form-check-label']) !!}
                    </div>
                </div>

                <div class="col-12"><hr><h6 class="text-uppercase text-muted small fw-bold">{{ __('Branding') }}</h6></div>
                <div class="col-md-6">
                    {!! Form::label('display_name', __('Hotel name')) !!}
                    {!! Form::text('display_name', old('display_name', $site->display_name), ['class' => 'form-control', 'required' => true]) !!}
                </div>
                <div class="col-md-6">
                    {!! Form::label('tagline', __('Tagline')) !!}
                    {!! Form::text('tagline', old('tagline', $site->tagline), ['class' => 'form-control', 'placeholder' => __('Your perfect stay awaits')]) !!}
                </div>
                <div class="col-12">
                    {!! Form::label('description', __('Description')) !!}
                    {!! Form::textarea('description', old('description', $site->description), ['class' => 'form-control', 'rows' => 4]) !!}
                </div>
                <div class="col-md-4">
                    {!! Form::label('accent_color', __('Accent color')) !!}
                    {!! Form::color('accent_color', old('accent_color', $site->accent_color ?: '#0d9488'), ['class' => 'form-control form-control-color w-100']) !!}
                </div>
                <div class="col-md-4">
                    {!! Form::label('default_language', __('Default language')) !!}
                    {!! Form::select('default_language', ['en' => 'English', 'pt' => 'Português', 'es' => 'Español', 'fr' => 'Français'], old('default_language', $site->default_language ?: 'en'), ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-4">
                    {!! Form::label('logo', __('Logo')) !!}
                    {!! Form::file('logo', ['class' => 'form-control', 'accept' => 'image/*']) !!}
                    @if($site->logoUrl())
                        <img src="{{ $site->logoUrl() }}" alt="" class="mt-2 rounded" style="max-height:48px;">
                    @endif
                </div>
                <div class="col-md-6">
                    {!! Form::label('cover', __('Cover image (hero)')) !!}
                    {!! Form::file('cover', ['class' => 'form-control', 'accept' => 'image/*']) !!}
                    @if($site->coverUrl())
                        <img src="{{ $site->coverUrl() }}" alt="" class="mt-2 rounded w-100" style="max-height:120px;object-fit:cover;">
                    @endif
                </div>
                <div class="col-md-6">
                    {!! Form::label('gallery[]', __('Gallery images')) !!}
                    {!! Form::file('gallery[]', ['class' => 'form-control', 'accept' => 'image/*', 'multiple' => true]) !!}
                </div>
                <div class="col-12">
                    {!! Form::label('amenities_text', __('Amenities (one per line)')) !!}
                    {!! Form::textarea('amenities_text', old('amenities_text', implode("\n", $site->amenities ?? [])), ['class' => 'form-control', 'rows' => 4, 'placeholder' => "Wi-Fi\nPool\nParking"]) !!}
                </div>

                <div class="col-12"><hr><h6 class="text-uppercase text-muted small fw-bold">{{ __('Contact & policies') }}</h6></div>
                <div class="col-md-6">
                    {!! Form::label('contact_email', __('Contact email')) !!}
                    {!! Form::email('contact_email', old('contact_email', $site->contact_email), ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-6">
                    {!! Form::label('contact_phone', __('Contact phone')) !!}
                    {!! Form::text('contact_phone', old('contact_phone', $site->contact_phone), ['class' => 'form-control']) !!}
                </div>
                <div class="col-12">
                    {!! Form::label('address', __('Address')) !!}
                    {!! Form::text('address', old('address', $site->address), ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-3">
                    {!! Form::label('check_in_time', __('Check-in time')) !!}
                    {!! Form::time('check_in_time', old('check_in_time', $site->check_in_time ?: '14:00'), ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-3">
                    {!! Form::label('check_out_time', __('Check-out time')) !!}
                    {!! Form::time('check_out_time', old('check_out_time', $site->check_out_time ?: '11:00'), ['class' => 'form-control']) !!}
                </div>
                <div class="col-12">
                    {!! Form::label('cancellation_policy', __('Cancellation policy')) !!}
                    {!! Form::textarea('cancellation_policy', old('cancellation_policy', $site->cancellation_policy), ['class' => 'form-control', 'rows' => 3]) !!}
                </div>

                <div class="col-12"><hr><h6 class="text-uppercase text-muted small fw-bold">{{ __('SEO') }}</h6></div>
                <div class="col-md-6">
                    {!! Form::label('meta_title', __('Meta title')) !!}
                    {!! Form::text('meta_title', old('meta_title', $site->meta_title), ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-4">
                    {!! Form::label('payment_mode', __('Payment mode')) !!}
                    {!! Form::select('payment_mode', [
                        'pay_at_hotel' => __('Pay at hotel'),
                        'prepay' => __('Stripe online payment'),
                    ], old('payment_mode', $site->payment_mode ?: 'pay_at_hotel'), ['class' => 'form-control', 'id' => 'payment_mode']) !!}
                </div>

                <div class="col-12">
                    {!! Form::label('meta_description', __('Meta description')) !!}
                    {!! Form::textarea('meta_description', old('meta_description', $site->meta_description), ['class' => 'form-control', 'rows' => 2]) !!}
                </div>

                <div class="col-12"><hr><h6 class="text-uppercase text-muted small fw-bold">{{ __('Stripe (optional override)') }}</h6>
                    <p class="small text-muted">{{ __('Leave blank to use keys from') }} <a href="{{ route('settings') }}">{{ __('Company Settings → Payment') }}</a>.</p>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Stripe publishable key') }}</label>
                    <input type="text" name="stripe_key" class="form-control" value="{{ old('stripe_key', ($site->payment_config ?? [])['stripe_key'] ?? '') }}" placeholder="pk_live_...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Stripe secret key') }}</label>
                    <input type="password" name="stripe_secret" class="form-control" placeholder="{{ __('Leave blank to keep existing') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Stripe webhook secret') }}</label>
                    <input type="password" name="stripe_webhook_secret" class="form-control" placeholder="whsec_...">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">{{ __('Save settings') }}</button>
                </div>
                {!! Form::close() !!}
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="text-uppercase text-muted small fw-bold">{{ __('Preview') }}</h6>
                @if($publicUrl && $site->is_published)
                    <p class="mb-2"><a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="btn btn-outline-primary w-100">{{ __('Open live website') }}</a></p>
                    <p class="small text-muted mb-0"><code>{{ $publicUrl }}</code></p>
                @elseif($publicUrl)
                    <p class="text-warning small">{{ __('Site is saved but not published. Enable "Published" to make it live.') }}</p>
                    <p class="small text-muted"><code>{{ $publicUrl }}</code></p>
                @else
                    <p class="text-muted small mb-0">{{ __('Save a slug to generate your public URL.') }}</p>
                @endif
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <h6 class="text-uppercase text-muted small fw-bold">{{ __('Stripe payments') }}</h6>
                @if($stripeConfigured ?? false)
                    <span class="badge bg-success mb-2">{{ __('Stripe ready') }}</span>
                @else
                    <span class="badge bg-warning text-dark mb-2">{{ __('Stripe not active yet') }}</span>
                @endif

                <ul class="list-unstyled small mb-3">
                    <li class="mb-2">
                        @if($stripeStatus['has_keys'] ?? false)
                            <span class="text-success">✓</span> {{ __('API keys found') }}
                            @if(!empty($stripeStatus['publishable']))
                                <br><code class="text-muted">{{ Str::limit($stripeStatus['publishable'], 20, '…') }}</code>
                                @if(($stripeStatus['keys_source'] ?? '') === 'company')
                                    <span class="text-muted">({{ __('from company settings') }})</span>
                                @elseif(($stripeStatus['keys_source'] ?? '') === 'booking_site')
                                    <span class="text-muted">({{ __('booking site override') }})</span>
                                @endif
                            @endif
                        @else
                            <span class="text-danger">✗</span> {{ __('API keys missing') }}
                            — <a href="{{ route('settings') }}">{{ __('Settings → Payment') }}</a>
                        @endif
                    </li>
                    <li class="mb-2">
                        @if($stripeStatus['is_prepay_mode'] ?? false)
                            <span class="text-success">✓</span> {{ __('Payment mode: Stripe online payment') }}
                        @else
                            <span class="text-danger">✗</span> {{ __('Payment mode is still "Pay at hotel"') }}
                            <br><span class="text-muted">{{ __('Change Payment mode above to "Stripe online payment" and save.') }}</span>
                        @endif
                    </li>
                    @if($companyStripeEnabled ?? false)
                        <li class="mb-0"><span class="text-success">✓</span> {{ __('Company Stripe toggle is on') }}</li>
                    @endif
                </ul>

                <p class="small text-muted mb-0">{{ __('Webhook URL') }}:<br><code class="user-select-all">{{ $webhookUrl ?? '' }}</code></p>
                <p class="small text-muted mt-1 mb-0">{{ __('Event') }}: <code>checkout.session.completed</code></p>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <h6 class="text-uppercase text-muted small fw-bold">{{ __('How it works') }}</h6>
                <ol class="small text-muted ps-3 mb-0">
                    <li>{{ __('Guest visits your public URL') }}</li>
                    <li>{{ __('Selects dates and room type') }}</li>
                    <li>{{ __('Booking creates a confirmed reservation in your ERP') }}</li>
                    <li>{{ __('Staff see it in Reservations with source "online"') }}</li>
                </ol>
            </div>
        </div>
    </div>
</div>
@push('script-page')
<script>
    (function () {
        var mode = document.getElementById('payment_mode');
        var wrap = document.getElementById('prepay_percent_wrap');
        function toggle() {
            if (!mode || !wrap) return;
            wrap.style.display = mode.value === 'prepay' ? '' : 'none';
        }
        if (mode) {
            mode.addEventListener('change', toggle);
            toggle();
        }
    })();
</script>
@endpush
@endsection
