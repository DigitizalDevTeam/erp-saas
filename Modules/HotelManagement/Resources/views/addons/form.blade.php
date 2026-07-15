@php
    use Modules\HotelManagement\Entities\HotelAddon;
    $typeOptions = HotelAddon::typeOptions();
    $billingOptions = HotelAddon::billingModeOptions();
    $selectedType = old('type', $addon->type ?? HotelAddon::TYPE_OTHER);
@endphp
<div class="row">
    <div class="col-md-6 form-group">
        {!! Form::label('name', __('Name')) !!}
        {!! Form::text('name', $addon->name ?? null, ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-6 form-group">
        {!! Form::label('type', __('Type')) !!}
        {!! Form::select('type', $typeOptions, $selectedType, ['class' => 'form-control', 'required', 'id' => 'addon_type']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('price', __('Price')) !!}
        {!! Form::number('price', $addon->price ?? 0, ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('billing_mode', __('Billing mode')) !!}
        {!! Form::select('billing_mode', $billingOptions, old('billing_mode', $addon->billing_mode ?? HotelAddon::BILLING_PER_STAY), ['class' => 'form-control', 'required', 'id' => 'addon_billing_mode']) !!}
        <small class="text-muted" id="addon_billing_hint">{{ __('Extra bed is always billed per night. Per unit is for qty-based items (e.g. ironing pants).') }}</small>
    </div>
    <div class="col-md-4 form-group d-flex align-items-end">
        <div class="form-check mb-2">
            {!! Form::checkbox('taxable', 1, isset($addon) ? (bool) $addon->taxable : true, ['class' => 'form-check-input', 'id' => 'addon_taxable']) !!}
            {!! Form::label('addon_taxable', __('Taxable'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
    <div class="col-md-12 form-group">
        {!! Form::label('description', __('Description')) !!}
        {!! Form::textarea('description', $addon->description ?? null, ['class' => 'form-control', 'rows' => 2]) !!}
    </div>
    <div class="col-md-12 form-group">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, isset($addon) ? (bool) $addon->is_active : true, ['class' => 'form-check-input', 'id' => 'addon_is_active']) !!}
            {!! Form::label('addon_is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>

@push('script-page')
<script>
(function () {
    var typeSelect = document.getElementById('addon_type');
    var billingSelect = document.getElementById('addon_billing_mode');
    function syncBillingMode() {
        if (!typeSelect || !billingSelect) return;
        if (typeSelect.value === 'extra_bed') {
            billingSelect.value = 'per_night';
            billingSelect.setAttribute('readonly', 'readonly');
        } else {
            billingSelect.removeAttribute('readonly');
        }
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', syncBillingMode);
        syncBillingMode();
    }
})();
</script>
@endpush
