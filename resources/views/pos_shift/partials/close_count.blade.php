@php
    $closeShift = $closeShift ?? $pos_shift ?? null;
    $openingBalance = (float) ($openingBalance ?? ($closeShift->opening_balance ?? 0));
    $salesTotal = (float) ($salesTotal ?? 0);
    $expectedCash = (float) ($expectedCash ?? 0);
    $amountField = $amountField ?? 'closing_balance';
    $countedOld = old($amountField);
    $reasonOld = old('notes', '');
    $symbol = \App\Models\Utility::getValByName('site_currency_symbol') ?: '';
    $symbolPosition = (\App\Models\Utility::settings()['site_currency_symbol_position'] ?? 'pre') === 'post' ? 'post' : 'pre';
    $branchName = $closeShift && $closeShift->branch ? $closeShift->branch->name : '-';
    $cashierName = $closeShift && $closeShift->user ? $closeShift->user->name : '-';
    $shiftLabel = $closeShift ? ('#' . $closeShift->id . ($closeShift->opened_at ? ' · ' . $closeShift->opened_at->format('Y-m-d H:i') : '')) : '';
@endphp
<div class="shift-close-count"
     data-expected="{{ $expectedCash }}"
     data-symbol="{{ $symbol }}"
     data-symbol-position="{{ $symbolPosition }}"
     data-branch="{{ $branchName }}"
     data-cashier="{{ $cashierName }}"
     data-shift="{{ $shiftLabel }}"
     data-opening="{{ \Auth::user()->priceFormat($openingBalance) }}"
     data-sales="{{ \Auth::user()->priceFormat($salesTotal) }}"
     data-expected-label="{{ \Auth::user()->priceFormat($expectedCash) }}"
     data-lbl-opening="{{ __('Opening Balance') }}"
     data-lbl-sales="{{ __('pos.sales_amount') }}"
     data-lbl-expected="{{ __('Expected cash in drawer') }}"
     data-lbl-counted="{{ __('pos.cash_counted') }}"
     data-lbl-difference="{{ __('Difference') }}"
     data-lbl-reason="{{ __('pos.close_reason') }}"
     data-lbl-branch="{{ __('Branch') }}"
     data-lbl-cashier="{{ __('User') }}"
     data-lbl-shift="{{ __('Shift') }}"
     data-lbl-title="{{ __('pos.shift_close_slip') }}"
     data-reason-required="{{ __('pos.close_reason_required') }}"
     data-popup-blocked="{{ __('pos.open_drawer_allow_popup') }}"
     data-error-title="{{ __('Error') }}">
    <div class="form-group mb-2">
        <label class="form-label">{{ __('Opening Balance') }}</label>
        <input type="text" class="form-control bg-light shift-close-opening" value="{{ \Auth::user()->priceFormat($openingBalance) }}" readonly tabindex="-1">
    </div>
    <div class="form-group mb-2">
        <label class="form-label">{{ __('pos.sales_amount') }}</label>
        <input type="text" class="form-control bg-light shift-close-sales" value="{{ \Auth::user()->priceFormat($salesTotal) }}" readonly tabindex="-1">
    </div>
    <div class="form-group mb-2">
        <label class="form-label">{{ __('Expected cash in drawer') }}</label>
        <input type="text" class="form-control bg-light shift-close-expected" value="{{ \Auth::user()->priceFormat($expectedCash) }}" readonly tabindex="-1">
    </div>
    <div class="form-group mb-2">
        <label class="form-label" for="shift-close-counted">{{ __('pos.cash_counted') }} <x-required></x-required></label>
        <input type="number" name="{{ $amountField }}" id="shift-close-counted" class="form-control shift-close-counted{{ $errors->has($amountField) ? ' is-invalid' : '' }}" required step="0.01" min="0" inputmode="decimal" placeholder="{{ __('pos.cash_counted') }}" value="{{ $countedOld }}">
        @error($amountField)
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
    <div class="form-group mb-2">
        <label class="form-label">{{ __('Difference') }}</label>
        <input type="text" class="form-control bg-light shift-close-difference" value="" readonly tabindex="-1">
    </div>
    <div class="form-group mb-2">
        <label class="form-label" for="shift-close-reason">
            {{ __('pos.close_reason') }}
            <span class="text-danger shift-close-reason-star d-none">*</span>
        </label>
        <textarea name="notes" id="shift-close-reason" class="form-control shift-close-reason{{ $errors->has('notes') ? ' is-invalid' : '' }}" rows="3" placeholder="{{ __('pos.close_reason_hint') }}">{{ $reasonOld }}</textarea>
        <div class="form-text shift-close-reason-hint d-none">{{ __('pos.close_reason_hint') }}</div>
        @error('notes')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
    @unless($hidePrint ?? false)
    <button type="button" class="btn btn-outline-primary btn-sm shift-close-print">
        <i class="ti ti-printer"></i> {{ __('Print') }}
    </button>
    @endunless
</div>
