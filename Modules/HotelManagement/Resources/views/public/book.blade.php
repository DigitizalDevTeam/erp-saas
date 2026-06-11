@extends('hotelmanagement::public.layout')
@section('title', __('Complete your booking') . ' — ' . $site->display_name)

@php
    $checkInAt = $checkIn . ' ' . ($site->check_in_time ?? '14:00');
    $checkOutAt = $checkOut . ' ' . ($site->check_out_time ?? '11:00');
@endphp

@section('content')
<div class="hb-page-banner" style="padding:2rem 0;margin-bottom:0;">
    <div class="hb-wrap">
        <h1 style="font-size:2rem;">{{ __('Complete your booking') }}</h1>
        <p>{{ __('Almost there — enter guest details to confirm your stay.') }}</p>
    </div>
</div>

<div class="hb-wrap" style="padding:2.5rem 0 4rem;">
    <div class="hb-steps">
        <span class="hb-step">{{ __('1. Dates') }}</span>
        <span class="hb-step active">{{ __('2. Guest details') }}</span>
        <span class="hb-step">{{ __('3. Confirmation') }}</span>
    </div>

    <div class="hb-book-layout">
        <div>
            <form method="post" action="{{ route('hotel.public.book.store', ['hotelSlug' => $site->slug]) }}" class="hb-card" style="padding:1.75rem;">
                @csrf
                <input type="hidden" name="hotel_room_type_id" value="{{ $roomTypeId }}">
                <input type="hidden" name="check_in_at" value="{{ $checkInAt }}">
                <input type="hidden" name="check_out_at" value="{{ $checkOutAt }}">
                <input type="hidden" name="adults" value="{{ $adults }}">
                <input type="hidden" name="children" value="{{ $children }}">

                <h2 style="font-family:var(--hb-serif);margin:0 0 1.25rem;font-size:1.65rem;">{{ __('Guest information') }}</h2>

                @if($errors->any())
                    <div class="hb-alert hb-alert-error">
                        <ul style="margin:0;padding-left:1.2rem;">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="hb-form-grid">
                    <div class="hb-field">
                        <label>{{ __('First name') }} *</label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}" required maxlength="100">
                    </div>
                    <div class="hb-field">
                        <label>{{ __('Last name') }}</label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="100">
                    </div>
                    <div class="hb-field">
                        <label>{{ __('Email') }} *</label>
                        <input type="email" name="email" value="{{ old('email') }}" required maxlength="191">
                    </div>
                    <div class="hb-field">
                        <label>{{ __('Phone') }} *</label>
                        <input type="tel" name="phone" value="{{ old('phone') }}" required maxlength="50">
                    </div>
                    <div class="hb-field hb-field-full">
                        <label>{{ __('Nationality') }}</label>
                        <input type="text" name="nationality" value="{{ old('nationality') }}" maxlength="100">
                    </div>
                    <div class="hb-field hb-field-full">
                        <label>{{ __('Special requests') }}</label>
                        <textarea name="special_requests" rows="3" maxlength="1000" placeholder="{{ __('Late arrival, dietary needs, accessibility, etc.') }}">{{ old('special_requests') }}</textarea>
                    </div>
                </div>

                @if(!empty($addons))
                    <hr style="border:none;border-top:1px solid var(--hb-border);margin:1.5rem 0;">
                    <h3 style="font-size:1rem;margin:0 0 1rem;font-weight:600;">{{ __('Addons & extras') }}</h3>
                    <div style="display:flex;flex-direction:column;gap:.75rem;">
                        @foreach($addons as $i => $addon)
                            <label class="hb-addon-option">
                                <input type="checkbox" name="addons[{{ $i }}][addon_id]" value="{{ $addon['id'] }}" @checked(collect(old('addons', []))->contains(fn($r) => (int)($r['addon_id'] ?? 0) === $addon['id']))>
                                <span style="flex:1;">
                                    <strong>{{ $addon['name'] }}</strong>
                                    @if(!empty($addon['description']))
                                        <br><span style="color:var(--hb-muted);font-size:.88rem;">{{ $addon['description'] }}</span>
                                    @endif
                                </span>
                                <span class="hb-price" style="font-size:1rem;">{{ $hbPrice($addon['price']) }}</span>
                            </label>
                            <input type="hidden" name="addons[{{ $i }}][qty]" value="1">
                        @endforeach
                    </div>
                @endif

                <hr style="border:none;border-top:1px solid var(--hb-border);margin:1.5rem 0;">

                @if($site->cancellation_policy)
                    <div style="font-size:.88rem;color:var(--hb-muted);margin-bottom:1rem;padding:1rem;background:var(--hb-bg);border-radius:var(--hb-radius-sm);">
                        <strong>{{ __('Cancellation policy') }}</strong><br>
                        {!! nl2br(e($site->cancellation_policy)) !!}
                    </div>
                @endif

                <label style="display:flex;align-items:flex-start;gap:.6rem;font-size:.9rem;">
                    <input type="checkbox" name="terms" value="1" required style="margin-top:.2rem;" @checked(old('terms'))>
                    <span>{{ __('I agree to the hotel terms and cancellation policy.') }}</span>
                </label>

                @if($stripeEnabled ?? false)
                    <p style="font-size:.88rem;color:var(--hb-muted);margin:1rem 0 0;">
                        {{ __('You will be redirected to Stripe to pay securely.') }}
                        @if(!empty($paymentAmounts))
                            <br><strong>{{ __('Amount due now') }}: {{ $hbPrice($paymentAmounts['payable']) }}</strong>
                            @if(($site->prepayment_percent ?? 100) < 100)
                                <span>({{ $site->prepayment_percent }}% {{ __('of') }} {{ $hbPrice($paymentAmounts['grand_total']) }})</span>
                            @endif
                        @endif
                    </p>
                @elseif($site->payment_mode === 'pay_at_hotel')
                    <p style="font-size:.88rem;color:var(--hb-muted);margin:1rem 0 0;">
                        {{ __('Payment will be collected at the hotel during check-in.') }}
                    </p>
                @endif

                <button type="submit" class="hb-btn hb-btn-primary hb-btn-block" style="margin-top:1.25rem;">
                    @if($stripeEnabled ?? false)
                        {{ __('Pay with Stripe & confirm') }}
                    @else
                        {{ __('Confirm booking') }}
                    @endif
                </button>
            </form>
        </div>

        <aside class="hb-card hb-book-sidebar" style="padding:0;overflow:hidden;">
            @if($roomType ?? null)
                <div style="height:180px;overflow:hidden;">
                    @include('hotelmanagement::public.partials.room-image', [
                        'imageUrl' => $roomType->displayImageUrl(),
                        'roomName' => $roomType->name,
                        'alt' => $roomType->name,
                    ])
                </div>
            @endif
            <div style="padding:1.5rem;">
            <h3 style="font-family:var(--hb-serif);margin:0 0 1rem;font-size:1.35rem;">{{ __('Booking summary') }}</h3>
            @if($roomType ?? null)
                <p style="font-weight:600;margin:0 0 1rem;">{{ $roomType->name }}</p>
            @endif
            <p style="margin:0 0 .75rem;font-size:.92rem;"><strong>{{ __('Check-in') }}</strong><br>{{ \Carbon\Carbon::parse($checkInAt)->format('D, M j, Y') }} · {{ $site->check_in_time ?? '14:00' }}</p>
            <p style="margin:0 0 .75rem;font-size:.92rem;"><strong>{{ __('Check-out') }}</strong><br>{{ \Carbon\Carbon::parse($checkOutAt)->format('D, M j, Y') }} · {{ $site->check_out_time ?? '11:00' }}</p>
            <p style="margin:0 0 1rem;color:var(--hb-muted);font-size:.9rem;">{{ $adults }} {{ __('adults') }}@if($children > 0), {{ $children }} {{ __('children') }}@endif</p>
            <hr style="border:none;border-top:1px solid var(--hb-border);margin:1rem 0;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                <span>{{ __('Room rate') }}</span>
                <span class="hb-price" style="font-size:1.2rem;">{{ $hbPrice($quote['subtotal']) }}</span>
            </div>
            <p style="font-size:.82rem;color:var(--hb-muted);margin:.35rem 0 0;">
                {{ $hbPrice($quote['rate_amount']) }} × {{ $quote['units'] }} {{ strtolower($quote['unit_label']) }}
            </p>
            @if(!empty($quote['rate_plan_name']))
                <p style="font-size:.82rem;color:var(--hb-muted);">{{ $quote['rate_plan_name'] }}</p>
            @endif
            @if(($stripeEnabled ?? false) && !empty($paymentAmounts))
                <hr style="border:none;border-top:1px solid var(--hb-border);margin:1rem 0;">
                <div style="display:flex;justify-content:space-between;font-weight:600;">
                    <span>{{ __('Due now (Stripe)') }}</span>
                    <span class="hb-price" style="font-size:1.15rem;">{{ $hbPrice($paymentAmounts['payable']) }}</span>
                </div>
            @endif
            <a href="{{ route('hotel.public.search', ['hotelSlug' => $site->slug, 'check_in' => $checkIn, 'check_out' => $checkOut, 'adults' => $adults, 'children' => $children]) }}"
               class="hb-btn hb-btn-outline hb-btn-block" style="margin-top:1.25rem;">
                {{ __('Change room') }}
            </a>
            </div>
        </aside>
    </div>
</div>
@endsection

@push('styles')
<style>
    .hb-book-layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; align-items: start; }
    .hb-book-sidebar { position: sticky; top: 5.5rem; }
    .hb-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .hb-field-full { grid-column: 1 / -1; }
    .hb-addon-option {
        display: flex; align-items: flex-start; gap: .75rem; padding: .9rem 1rem;
        border: 1.5px solid var(--hb-border); border-radius: var(--hb-radius-sm); cursor: pointer;
        transition: border-color .15s;
    }
    .hb-addon-option:has(input:checked) { border-color: var(--hb-accent); background: var(--hb-accent-soft); }
    @media (max-width: 900px) {
        .hb-book-layout { grid-template-columns: 1fr; }
        .hb-book-sidebar { position: static; }
        .hb-form-grid { grid-template-columns: 1fr; }
    }
</style>
@endpush
