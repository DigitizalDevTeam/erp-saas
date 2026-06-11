@extends('hotelmanagement::public.layout')
@section('title', __('Booking confirmed') . ' — ' . $site->display_name)

@section('content')
<div class="hb-wrap" style="padding:2.5rem 0 4rem;">
    <div class="hb-steps" style="margin-bottom:1.75rem;">
        <span class="hb-step">{{ __('1. Dates') }}</span>
        <span class="hb-step">{{ __('2. Guest details') }}</span>
        <span class="hb-step active">{{ __('3. Confirmation') }}</span>
    </div>

    <div class="hb-card" style="text-align:center;max-width:600px;margin:0 auto 2.5rem;padding:3rem 2rem;">
        <div class="hb-confirm-icon">✓</div>
        <h2 style="font-family:var(--hb-serif);margin:0 0 .5rem;font-size:2rem;">{{ __('Your booking is confirmed!') }}</h2>
        <p style="color:var(--hb-muted);margin:0 0 1.5rem;">{{ __('A confirmation email has been sent to :email.', ['email' => $reservation->guest?->email ?? '—']) }}</p>
        <p style="font-size:1.5rem;font-weight:700;color:var(--hb-accent-dark);margin:0;font-family:var(--hb-serif);letter-spacing:.04em;">{{ $reservation->reservation_no }}</p>
        <p style="font-size:.88rem;color:var(--hb-muted);margin:.5rem 0 0;">{{ __('Keep this reference for check-in.') }}</p>
    </div>

    <div class="hb-confirm-grid">
        <div class="hb-card" style="padding:1.5rem;">
            <h3 class="hb-confirm-label">{{ __('Stay details') }}</h3>
            <p><strong>{{ __('Check-in') }}</strong><br>
                {{ \Carbon\Carbon::parse($reservation->check_in_at ?? $reservation->check_in_date)->format('l, M j, Y') }}
                @if($site->check_in_time) · {{ $site->check_in_time }}@endif
            </p>
            <p><strong>{{ __('Check-out') }}</strong><br>
                {{ \Carbon\Carbon::parse($reservation->check_out_at ?? $reservation->check_out_date)->format('l, M j, Y') }}
                @if($site->check_out_time) · {{ $site->check_out_time }}@endif
            </p>
            <p style="margin-bottom:0;"><strong>{{ __('Room type') }}</strong><br>{{ $reservation->roomType?->name }}</p>
            @if($reservation->room)
                <p style="margin-top:.75rem;margin-bottom:0;"><strong>{{ __('Room') }}</strong><br>{{ $reservation->room->room_no }}</p>
            @endif
        </div>

        <div class="hb-card" style="padding:1.5rem;">
            <h3 class="hb-confirm-label">{{ __('Guest') }}</h3>
            <p>{{ trim($reservation->guest?->first_name . ' ' . $reservation->guest?->last_name) }}</p>
            <p style="color:var(--hb-muted);">{{ $reservation->guest?->email }}</p>
            <p style="color:var(--hb-muted);margin-bottom:0;">{{ $reservation->guest?->phone }}</p>
            <p style="margin:1rem 0 0;">{{ $reservation->adults }} {{ __('adults') }}@if($reservation->children > 0), {{ $reservation->children }} {{ __('children') }}@endif</p>
        </div>

        <div class="hb-card" style="padding:1.5rem;">
            <h3 class="hb-confirm-label">{{ __('Payment') }}</h3>
            @if(($totals['room_sub_total'] ?? 0) > 0)
                <p style="font-size:.88rem;margin:0 0 .35rem;"><span>{{ __('Room') }}</span> <strong style="float:right">{{ $hbPrice($totals['room_sub_total']) }}</strong></p>
            @endif
            @if(($totals['addon_sub_total'] ?? 0) > 0)
                <p style="font-size:.88rem;margin:0 0 .75rem;"><span>{{ __('Addons') }}</span> <strong style="float:right">{{ $hbPrice($totals['addon_sub_total']) }}</strong></p>
            @endif
            <p><strong>{{ __('Total') }}</strong><br><span class="hb-price">{{ $hbPrice($totals['grand_total'] ?? $totals['sub_total'] ?? 0) }}</span></p>
            @if(str_starts_with((string) $reservation->online_payment_ref, 'stripe:'))
                <p style="font-size:.88rem;color:#047857;margin:0;">{{ __('Paid online via Stripe.') }}</p>
            @elseif($site->payment_mode === 'pay_at_hotel')
                <p style="font-size:.88rem;color:var(--hb-muted);margin:0;">{{ __('Pay at hotel on arrival.') }}</p>
            @endif
            @if($reservation->reservationAddons->isNotEmpty())
                <hr style="border:none;border-top:1px solid var(--hb-border);margin:1rem 0;">
                <p style="font-size:.85rem;margin:0 0 .5rem;font-weight:600;">{{ __('Addons') }}</p>
                <ul style="margin:0;padding-left:1.1rem;font-size:.88rem;color:var(--hb-muted);">
                    @foreach($reservation->reservationAddons as $ra)
                        <li>{{ $ra->addon?->name }} × {{ $ra->quantity }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div style="text-align:center;margin-top:2.5rem;">
        <a href="{{ route('hotel.public.home', ['hotelSlug' => $site->slug]) }}" class="hb-btn hb-btn-outline">{{ __('Back to hotel home') }}</a>
    </div>
</div>
@endsection

@push('styles')
<style>
    .hb-confirm-icon {
        width: 76px; height: 76px; border-radius: 50%; margin: 0 auto 1.25rem;
        display: flex; align-items: center; justify-content: center;
        font-size: 2rem; font-weight: 700; color: var(--hb-accent-dark);
        background: var(--hb-accent-soft);
    }
    .hb-confirm-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; max-width: 960px; margin: 0 auto;
    }
    .hb-confirm-label {
        margin: 0 0 1rem; font-size: .72rem; text-transform: uppercase;
        letter-spacing: .1em; color: var(--hb-muted); font-weight: 700;
    }
    .hb-confirm-grid p { font-size: .92rem; margin: 0 0 .75rem; }
    @media (max-width: 768px) { .hb-confirm-grid { grid-template-columns: 1fr; } }
</style>
@endpush
