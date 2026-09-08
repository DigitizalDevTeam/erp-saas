<style>
    .hotel-top-period-fields.is-disabled {
        opacity: .65;
    }
    .hotel-top-period-fields.is-disabled .hotel-top-field {
        background-color: #f3f4f6 !important;
        pointer-events: none;
    }
    .hotel-pricing-option {
        cursor: pointer;
        border: 1.5px solid #e5e7eb !important;
        border-radius: 12px;
        background: #fff;
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        margin-bottom: 0;
    }
    .hotel-pricing-option:hover { border-color: #c5d0c8 !important; }
    .hotel-pricing-option.is-selected {
        border-color: #0CAF60 !important;
        box-shadow: 0 0 0 1px rgba(12, 175, 96, .18);
        background: #f7fdf9;
    }
    .hotel-pricing-option .form-check-input:checked {
        background-color: #0CAF60;
        border-color: #0CAF60;
    }
    .hotel-pricing-panel {
        border-top: 1px solid #e8eee9;
        margin-top: .9rem;
        padding-top: .9rem;
    }
</style>

@php
    $isEdit = isset($ratePlan) && $ratePlan;
    $currentStructure = old('pricing_structure', $isEdit ? 'period' : 'normal');
    if (! in_array($currentStructure, ['normal', 'period'], true)) {
        $currentStructure = $isEdit ? 'period' : 'normal';
    }
    $defaultPriority = old('priority', $isEdit ? ($ratePlan->priority ?? 100) : 100);
    $rateValue = old('rate', $ratePlan->rate ?? '');
    $monthlyValue = old('monthly_rate', $ratePlan->monthly_rate ?? '');
    $weekendValue = old('weekend_rate', $ratePlan->weekend_rate ?? '');
    $validFrom = old('valid_from', isset($ratePlan) && $ratePlan->valid_from ? \Carbon\Carbon::parse($ratePlan->valid_from)->format('Y-m-d\TH:i') : '');
    $validTo = old('valid_to', isset($ratePlan) && $ratePlan->valid_to ? \Carbon\Carbon::parse($ratePlan->valid_to)->format('Y-m-d\TH:i') : '');
    $billingMode = old('billing_mode', $ratePlan->billing_mode ?? 'nightly');
@endphp

<div class="alert alert-info mb-3">
    {{ __('Plan different rates for date ranges. Active periods for the same room type cannot overlap. When no period is active, the Normal price applies. Optional weekend rate applies Fri–Sun nights.') }}
</div>

{{-- Room Type always enabled (needed for Normal + Period) --}}
<div class="row">
    <div class="col-md-6 form-group">
        {!! Form::label('hotel_room_type_id', __('Room Type')) !!}
        {!! Form::select('hotel_room_type_id', $roomTypes, $ratePlan->hotel_room_type_id ?? null, [
            'class' => 'form-control select2',
            'id' => 'hotel_rate_plan_room_type_id',
            'required' => true,
        ]) !!}
    </div>
</div>

{{-- TOP SECTION — enabled only when Price per Period is selected --}}
<div id="hotel_top_period_fields" class="hotel-top-period-fields mb-4 {{ (! $isEdit && $currentStructure === 'normal') ? 'is-disabled' : '' }}">
    <div class="row">
        <div class="col-md-6 form-group">
            {!! Form::label('name', __('Plan name')) !!}
            <input type="text" name="name" id="hotel_rate_plan_name" class="form-control hotel-top-field" placeholder="{{ __('Enter plan name') }}"
                   value="{{ old('name', $ratePlan->name ?? '') }}" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : 'required' }}>
        </div>
        <div class="col-md-6 form-group">
            {!! Form::label('rate_top', __('Nightly rate')) !!}
            <input type="number" step="0.01" min="0" name="rate" id="hotel_rate_top" class="form-control hotel-top-field" placeholder="{{ __('Enter nightly rate') }}"
                   value="{{ $rateValue }}" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : 'required' }}>
        </div>

        <div class="col-md-4 form-group">
            {!! Form::label('weekend_rate', __('Weekend / promo nightly rate')) !!}
            <input type="number" step="0.01" min="0" name="weekend_rate" class="form-control hotel-top-field" placeholder="{{ __('Enter weekend / promo rate') }}"
                   value="{{ $weekendValue }}" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : '' }}>
            <small class="text-muted">{{ __('Leave empty to use the nightly rate every day. Set a lower value for weekend promotions.') }}</small>
        </div>
        <div class="col-md-4 form-group">
            {!! Form::label('monthly_rate_top', __('Monthly rate')) !!}
            <input type="number" step="0.01" min="0" name="monthly_rate" id="hotel_monthly_top" class="form-control hotel-top-field" placeholder="{{ __('Enter monthly rate') }}"
                   value="{{ $monthlyValue }}" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : '' }}>
        </div>
        <div class="col-md-4 form-group">
            {!! Form::label('billing_mode', __('Billing mode')) !!}
            <select name="billing_mode" id="hotel_rate_plan_billing_mode" class="form-control hotel-top-field" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : 'required' }}>
                <option value="nightly" @selected($billingMode === 'nightly')>{{ __('Nightly') }}</option>
                <option value="monthly" @selected($billingMode === 'monthly')>{{ __('Monthly') }}</option>
                <option value="both" @selected($billingMode === 'both')>{{ __('Both') }}</option>
            </select>
        </div>

        <div class="col-md-4 form-group">
            {!! Form::label('priority', __('Priority')) !!}
            <input type="number" min="0" name="priority" id="hotel_rate_plan_priority" class="form-control hotel-top-field" value="{{ $defaultPriority }}"
                   {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : '' }}>
            <small class="text-muted">{{ __('Used only if ranges are adjacent or inactive; active periods must not overlap.') }}</small>
        </div>
        <div class="col-md-4 form-group">
            {!! Form::label('valid_from', __('Start Date & Time')) !!}
            <input type="datetime-local" name="valid_from" id="hotel_rate_plan_valid_from" class="form-control hotel-top-field"
                   value="{{ $validFrom }}" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : 'required' }}>
        </div>
        <div class="col-md-4 form-group">
            {!! Form::label('valid_to', __('End Date & Time')) !!}
            <input type="datetime-local" name="valid_to" id="hotel_rate_plan_valid_to" class="form-control hotel-top-field"
                   value="{{ $validTo }}" {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : 'required' }}>
        </div>
        <div class="col-md-12 form-group">
            <div class="form-check">
                <input type="checkbox" name="is_active" value="1" id="hotel_rate_plan_is_active" class="form-check-input hotel-top-field"
                       {{ old('is_active', isset($ratePlan) ? (bool) $ratePlan->is_active : true) ? 'checked' : '' }}
                       {{ (! $isEdit && $currentStructure === 'normal') ? 'disabled' : '' }}>
                <label class="form-check-label" for="hotel_rate_plan_is_active">{{ __('Active') }}</label>
            </div>
        </div>
    </div>
</div>

@unless($isEdit)
<div class="mb-2">
    <label class="form-label fw-semibold mb-1">{{ __('Pricing Structure') }}</label>
    <p class="small text-muted mb-3">{{ __('Choose how you want to set the price for this period.') }}</p>
</div>

<div class="row g-3 mb-2" id="hotel_pricing_structure_options">
    {{-- Normal Price --}}
    <div class="col-lg-6">
        <div class="p-3 h-100 hotel-pricing-option {{ $currentStructure === 'normal' ? 'is-selected' : '' }}" id="hotel_card_normal">
            <label class="d-flex align-items-start gap-2 mb-0 w-100" style="cursor:pointer;">
                <input type="radio" name="pricing_structure" value="normal" class="form-check-input mt-1 flex-shrink-0" {{ $currentStructure === 'normal' ? 'checked' : '' }}>
                <div class="flex-grow-1">
                    <strong class="d-block">{{ __('Normal Price (Standard)') }}</strong>
                    <div class="small text-muted mt-1">{{ __('Set a single nightly rate for the selected room type.') }}</div>
                </div>
            </label>
            <div class="hotel-pricing-panel hotel-panel-normal {{ $currentStructure === 'normal' ? '' : 'd-none' }}">
                <div class="row g-2">
                    <div class="col-md-6 form-group mb-0">
                        <label class="form-label">{{ __('Price per night') }}</label>
                        <input type="number" step="0.01" min="0" name="rate" id="hotel_rate_normal" class="form-control hotel-field-normal"
                               value="{{ $rateValue }}" placeholder="{{ __('Enter price') }}" {{ $currentStructure === 'normal' ? 'required' : 'disabled' }}>
                    </div>
                    <div class="col-md-6 form-group mb-0">
                        <label class="form-label">{{ __('Monthly rate') }}</label>
                        <input type="number" step="0.01" min="0" name="monthly_rate" id="hotel_monthly_normal" class="form-control hotel-field-normal"
                               value="{{ $monthlyValue }}" placeholder="{{ __('Enter monthly rate') }}" {{ $currentStructure === 'normal' ? '' : 'disabled' }}>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Price per Period --}}
    <div class="col-lg-6">
        <div class="p-3 h-100 hotel-pricing-option {{ $currentStructure === 'period' ? 'is-selected' : '' }}" id="hotel_card_period">
            <label class="d-flex align-items-start gap-2 mb-0 w-100" style="cursor:pointer;">
                <input type="radio" name="pricing_structure" value="period" class="form-check-input mt-1 flex-shrink-0" {{ $currentStructure === 'period' ? 'checked' : '' }}>
                <div class="flex-grow-1">
                    <strong class="d-block">{{ __('Price per Period') }}</strong>
                    <div class="small text-muted mt-1">{{ __('Set different rates for date ranges within the selected period.') }}</div>
                </div>
            </label>
            <div class="hotel-pricing-panel hotel-panel-period-hint {{ $currentStructure === 'period' ? '' : 'd-none' }}">
                <div class="row g-2 mb-2">
                    <div class="col-md-6 form-group mb-0">
                        <label class="form-label">{{ __('Start Date & Time') }}</label>
                        <input type="datetime-local" class="form-control" id="hotel_period_card_from" readonly>
                    </div>
                    <div class="col-md-6 form-group mb-0">
                        <label class="form-label">{{ __('End Date & Time') }}</label>
                        <input type="datetime-local" class="form-control" id="hotel_period_card_to" readonly>
                    </div>
                    <div class="col-md-6 form-group mb-0">
                        <label class="form-label">{{ __('Price') }}</label>
                        <input type="number" step="0.01" class="form-control" id="hotel_period_card_price" readonly placeholder="{{ __('Enter price') }}">
                    </div>
                </div>
                <div class="alert alert-info py-2 px-3 mb-0 small">
                    {{ __('You can add multiple period rates (e.g. weekend, holiday, special days).') }}
                </div>
            </div>
        </div>
    </div>

    {{-- Promotional Price UI commented out --}}
</div>
@else
    <input type="hidden" name="pricing_structure" value="period">
@endunless

<script>
(function () {
    function syncPeriodCardPreview() {
        var from = document.getElementById('hotel_rate_plan_valid_from');
        var to = document.getElementById('hotel_rate_plan_valid_to');
        var rate = document.getElementById('hotel_rate_top');
        var cFrom = document.getElementById('hotel_period_card_from');
        var cTo = document.getElementById('hotel_period_card_to');
        var cPrice = document.getElementById('hotel_period_card_price');
        if (cFrom && from) cFrom.value = from.value || '';
        if (cTo && to) cTo.value = to.value || '';
        if (cPrice && rate) cPrice.value = rate.value || '';
    }

    function syncHotelPricingStructure() {
        var isEdit = @json($isEdit);
        var checked = document.querySelector('input[name="pricing_structure"]:checked');
        var structure = checked ? checked.value : (isEdit ? 'period' : 'normal');
        if (structure === 'promotional') structure = 'period';

        var isNormal = !isEdit && structure === 'normal';
        var isPeriod = isEdit || structure === 'period';

        document.querySelectorAll('.hotel-pricing-option').forEach(function (el) {
            var radio = el.querySelector('input[name="pricing_structure"]');
            el.classList.toggle('is-selected', !!(radio && radio.checked));
        });

        document.querySelectorAll('.hotel-panel-normal').forEach(function (el) {
            el.classList.toggle('d-none', !isNormal);
        });
        document.querySelectorAll('.hotel-panel-period-hint').forEach(function (el) {
            el.classList.toggle('d-none', !isPeriod || isEdit);
        });

        var topWrap = document.getElementById('hotel_top_period_fields');
        if (topWrap) topWrap.classList.toggle('is-disabled', isNormal);

        document.querySelectorAll('.hotel-top-field').forEach(function (el) {
            el.disabled = isNormal;
            if (['name', 'rate', 'valid_from', 'valid_to', 'billing_mode'].indexOf(el.name) !== -1) {
                el.required = isPeriod;
            }
        });

        document.querySelectorAll('.hotel-field-normal').forEach(function (el) {
            el.disabled = !isNormal;
            if (el.name === 'rate') el.required = isNormal;
        });

        syncPeriodCardPreview();
    }

    document.querySelectorAll('input[name="pricing_structure"]').forEach(function (el) {
        el.addEventListener('change', syncHotelPricingStructure);
    });

    ['hotel_rate_plan_valid_from', 'hotel_rate_plan_valid_to', 'hotel_rate_top'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', syncPeriodCardPreview);
        if (el) el.addEventListener('change', syncPeriodCardPreview);
    });

    document.querySelectorAll('.hotel-pricing-option').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('input[type="number"], input[type="text"], input[type="datetime-local"], select, textarea, a, button')) {
                return;
            }
            var radio = card.querySelector('input[name="pricing_structure"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                syncHotelPricingStructure();
            }
        });
    });

    document.querySelectorAll('.hotel-pricing-panel').forEach(function (panel) {
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    syncHotelPricingStructure();
})();
</script>
