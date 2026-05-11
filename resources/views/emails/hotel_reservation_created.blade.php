@php
    $guest = $reservation->guest;
    $roomType = $reservation->roomType;
    $room = $reservation->room;
@endphp
<p>{{ __('Dear') }} {{ trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) ?: __('Guest') }},</p>

<p>{{ __('Your booking has been created successfully.') }}</p>

<p>
    <strong>{{ __('Reservation No') }}:</strong> {{ $reservation->reservation_no }}<br>
    <strong>{{ __('Check-in') }}:</strong> {{ $reservation->check_in_at ?: $reservation->check_in_date }}<br>
    <strong>{{ __('Check-out') }}:</strong> {{ $reservation->check_out_at ?: $reservation->check_out_date }}<br>
    <strong>{{ __('Unit type') }}:</strong> {{ $roomType->name ?? '-' }}<br>
    <strong>{{ __('Assigned unit') }}:</strong> {{ $room->room_no ?? __('To be assigned') }}<br>
    <strong>{{ __('Billing mode') }}:</strong> {{ __(\Illuminate\Support\Str::title($payload['billing_mode'] ?? 'nightly')) }}<br>
    <strong>{{ __('Rate') }}:</strong> {{ $payload['rate_display'] ?? $reservation->rate_per_night }}
</p>

@if(!empty($reservation->estimated_arrival_time))
<p><strong>{{ __('Estimated arrival time') }}:</strong> {{ $reservation->estimated_arrival_time }}</p>
@endif

<p>{{ __('Thank you for choosing us.') }}</p>
