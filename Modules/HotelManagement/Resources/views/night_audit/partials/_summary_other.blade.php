@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
@endphp
<h6 class="fw-semibold mb-3">{{ __('Other summaries') }}</h6>
<div class="row g-2 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Cancellations') }}</span>
            <span class="fw-bold">{{ $fmt(1200) }}</span>
            <span class="badge bg-danger mt-1">2</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('No shows') }}</span>
            <span class="fw-bold">{{ $fmt(0) }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Adjustments') }}</span>
            <span class="fw-bold">{{ $fmt(350) }}</span>
            <span class="badge bg-danger mt-1">1</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Refunds') }}</span>
            <span class="fw-bold">{{ $fmt(0) }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Taxes') }}</span>
            <span class="fw-bold">{{ $fmt(2950) }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3 bg-primary-subtle">
            <span class="text-muted small d-block">{{ __('Grand total') }}</span>
            <span class="fw-bold fs-5 text-primary">{{ $fmt(23750) }}</span>
        </div>
    </div>
</div>
