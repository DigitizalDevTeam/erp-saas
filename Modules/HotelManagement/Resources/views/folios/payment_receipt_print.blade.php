@extends('layouts.admin')
@section('page-title'){{ __('Payment receipt') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item">{{ __('Payment receipt') }}</li>
@endsection
@section('content')
@php
    $companyTitle = \App\Models\Utility::companyData(Auth::user()->creatorId(), 'title_text') ?: config('app.name');
    $guest = $folio->stay?->reservation?->guest;
    $guestName = $guest ? trim(($guest->first_name ?? '').' '.($guest->last_name ?? '')) : __('Guest');
    $reservation = $folio->stay?->reservation;
    $roomNo = $reservation?->room?->room_no;
    $methodLabel = \Modules\HotelManagement\Services\HotelFolioPaymentService::paymentMethodOptions()[$payment->method] ?? ucfirst($payment->method);
@endphp
<div class="mb-3 d-flex flex-wrap gap-2">
    @if($reservation)
        <a href="{{ route('hotel.frontdesk.checkout', $reservation->id) }}" class="btn btn-outline-secondary">{{ __('Back to check-out') }}</a>
    @endif
    <a href="{{ route('hotel.folios.show', $folio) }}" class="btn btn-outline-secondary">{{ __('Back to room bill') }}</a>
    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="ti ti-printer me-1"></i>{{ __('Reprint document') }}</button>
</div>
<div id="hotel-payment-receipt" class="card shadow-sm">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h4 class="mb-1">{{ $companyTitle }}</h4>
            <p class="mb-0 text-muted fw-bold text-uppercase">{{ __('Payment receipt') }}</p>
        </div>
        <div class="row mb-3 small">
            <div class="col-md-6">
                <p class="mb-1"><strong>{{ __('Receipt no.') }}:</strong> PAY-{{ str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT) }}</p>
                <p class="mb-1"><strong>{{ __('Guest') }}:</strong> {{ $guestName }}</p>
                @if($reservation?->reservation_no)
                    <p class="mb-1"><strong>{{ __('Reservation') }}:</strong> {{ $reservation->reservation_no }}</p>
                @endif
                @if($roomNo)
                    <p class="mb-0"><strong>{{ __('Room') }}:</strong> {{ $roomNo }}</p>
                @endif
            </div>
            <div class="col-md-6 text-md-end">
                <p class="mb-1"><strong>{{ __('Date') }}:</strong> {{ $payment->created_at?->format('d/m/Y H:i') }}</p>
                <p class="mb-1"><strong>{{ __('Payment method') }}:</strong> {{ $methodLabel }}</p>
                @if($payment->reference)
                    <p class="mb-0"><strong>{{ __('Reference') }}:</strong> {{ $payment->reference }}</p>
                @endif
            </div>
        </div>
        <div class="border rounded p-3 mb-3 text-center">
            <div class="text-muted small">{{ __('Amount received') }}</div>
            <div class="fs-3 fw-bold text-success">{{ Auth::user()->priceFormat($payment->amount) }}</div>
        </div>
        <div class="row small">
            <div class="col-md-6">
                <p class="mb-1"><strong>{{ __('Folio') }}:</strong> {{ $folio->folio_no }}</p>
            </div>
            <div class="col-md-6 text-md-end">
                @php $balance = max(0, (float) $folio->grand_total - (float) $folio->paid_total); @endphp
                <p class="mb-1"><strong>{{ __('Grand total') }}:</strong> {{ Auth::user()->priceFormat($folio->grand_total) }}</p>
                <p class="mb-1"><strong>{{ __('Paid') }}:</strong> {{ Auth::user()->priceFormat($folio->paid_total) }}</p>
                <p class="mb-0"><strong>{{ __('Pending balance') }}:</strong> {{ Auth::user()->priceFormat($balance) }}</p>
            </div>
        </div>
        @if($payment->notes)
            <p class="small mt-3 mb-0"><strong>{{ __('Notes') }}:</strong> {{ $payment->notes }}</p>
        @endif
        <p class="text-center mt-4 mb-0 fw-semibold">{{ __('Thank you for your preference!') }}</p>
    </div>
</div>
@endsection

@push('css-page')
<style>
@media print {
    .dash-sidebar, .dash-header, .breadcrumb, .btn, .dash-footer { display: none !important; }
    .dash-container { margin: 0 !important; padding: 0 !important; }
}
</style>
@endpush
