@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $summary = $pettyCashSummary ?? null;
@endphp
@if($openShift ?? null)
    <div class="alert alert-info border-0 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <strong>{{ __('Petty cash (open shift #') }}{{ $openShift->id }})</strong>
                <div class="small mt-1">{{ __('Cash expenses deduct from the petty cash float for this shift.') }}</div>
            </div>
            @if($summary)
                <div class="text-end small">
                    <div>{{ __('Opening float') }}: {{ $fmt($openShift->opening_float) }}</div>
                    <div class="text-danger">{{ __('Expenses') }}: − {{ $fmt($summary['expenses_total'] ?? 0) }}</div>
                    <div class="fw-bold">{{ __('Remaining') }}: {{ $fmt($summary['remaining_petty_cash'] ?? 0) }}</div>
                </div>
            @endif
        </div>
    </div>
@else
    <div class="alert alert-warning border-0 mb-3 small">
        <i class="ti ti-alert-triangle me-1"></i>
        {{ __('No open cashier shift. Cash expenses will be recorded but not deducted from petty cash until a shift is opened.') }}
        <a href="{{ route('hotel.cashier-shifts.index') }}" class="alert-link ms-1">{{ __('Open shift') }}</a>
    </div>
@endif
