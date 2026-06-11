@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $other = $preview['other'] ?? [];
@endphp
<h6 class="fw-semibold mb-3">{{ __('Other summaries') }}</h6>
<div class="row g-2 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Cancellations') }}</span>
            <span class="fw-bold">{{ $fmt($other['cancellations']['amount'] ?? 0) }}</span>
            <span class="badge bg-danger mt-1">{{ $other['cancellations']['count'] ?? 0 }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('No shows') }}</span>
            <span class="fw-bold">{{ $fmt($other['no_shows']['amount'] ?? 0) }}</span>
            <span class="badge bg-{{ ($other['no_shows']['count'] ?? 0) > 0 ? 'warning' : 'secondary' }} mt-1">{{ $other['no_shows']['count'] ?? 0 }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Discounts') }}</span>
            <span class="fw-bold">{{ $fmt($other['adjustments']['amount'] ?? 0) }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Refunds') }}</span>
            <span class="fw-bold">{{ $fmt($other['refunds']['amount'] ?? 0) }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3">
            <span class="text-muted small d-block">{{ __('Taxes') }}</span>
            <span class="fw-bold">{{ $fmt($other['taxes'] ?? 0) }}</span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card shadow-sm border-0 h-100 text-center p-3 bg-primary-subtle">
            <span class="text-muted small d-block">{{ __('Grand total') }}</span>
            <span class="fw-bold fs-5 text-primary">{{ $fmt($other['grand_total'] ?? 0) }}</span>
        </div>
    </div>
</div>
