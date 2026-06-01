@php
    $formAction = $formAction ?? '#';
    $formMethod = $formMethod ?? 'POST';
    $roomId = $roomId ?? null;
    $chargeTypes = $chargeTypes ?? [
        'minibar' => __('Minibar'),
        'laundry' => __('Laundry'),
        'misc' => __('Other extra'),
    ];
    $minibarItems = $minibarItems ?? collect();
    $defaultDate = $defaultDate ?? now()->toDateString();
    $redirectTo = $redirectTo ?? null;
@endphp
{!! Form::open(['url' => $formAction, 'method' => $formMethod, 'class' => 'row g-2', 'id' => $formId ?? null]) !!}
@if($roomId)
    {!! Form::hidden('hotel_room_id', $roomId) !!}
@endif
@if($redirectTo)
    {!! Form::hidden('redirect_to', $redirectTo) !!}
@endif
<div class="col-md-3">
    {!! Form::label('charge_type', __('Charge type')) !!}
    {!! Form::select('charge_type', $chargeTypes, old('charge_type', 'minibar'), ['class' => 'form-control', 'id' => 'charge_type_select']) !!}
</div>
<div class="col-md-3" id="minibar_item_wrap">
    {!! Form::label('minibar_item_id', __('Minibar item')) !!}
    <select name="minibar_item_id" id="minibar_item_id" class="form-control">
        <option value="">{{ __('Custom / other') }}</option>
        @foreach($minibarItems as $item)
            <option value="{{ $item->id }}" data-price="{{ $item->unit_price }}" @selected(old('minibar_item_id') == $item->id)>
                {{ $item->name }} — {{ Auth::user()->priceFormat($item->unit_price) }}
            </option>
        @endforeach
    </select>
</div>
<div class="col-md-4">
    {!! Form::label('description', __('Description')) !!}
    {!! Form::text('description', old('description'), ['class' => 'form-control', 'maxlength' => '191', 'placeholder' => __('Optional if minibar item selected')]) !!}
</div>
<div class="col-md-2">
    {!! Form::label('quantity', __('Qty')) !!}
    {!! Form::number('quantity', old('quantity', 1), ['class' => 'form-control', 'min' => '1']) !!}
</div>
<div class="col-md-2">
    {!! Form::label('unit_price', __('Unit price')) !!}
    {!! Form::number('unit_price', old('unit_price'), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'required', 'id' => 'consumption_unit_price']) !!}
</div>
<div class="col-md-2">
    {!! Form::label('service_date', __('Service date')) !!}
    {!! Form::date('service_date', old('service_date', $defaultDate), ['class' => 'form-control']) !!}
</div>
<div class="col-md-6">
    {!! Form::label('notes', __('Notes')) !!}
    {!! Form::text('notes', old('notes'), ['class' => 'form-control', 'maxlength' => '500', 'placeholder' => __('Housekeeping notes (optional)')]) !!}
</div>
<div class="col-md-2 d-flex align-items-end">
    <button type="submit" class="btn btn-primary w-100">{{ $submitLabel ?? __('Post to room bill') }}</button>
</div>
{!! Form::close() !!}

@once
@push('script-page')
<script>
(function () {
    var typeSel = document.getElementById('charge_type_select');
    var itemWrap = document.getElementById('minibar_item_wrap');
    var itemSel = document.getElementById('minibar_item_id');
    var priceInput = document.getElementById('consumption_unit_price');

    function syncMinibarVisibility() {
        if (!typeSel || !itemWrap) return;
        var isMinibar = typeSel.value === 'minibar';
        itemWrap.style.display = isMinibar ? '' : 'none';
    }

    function applyItemPrice() {
        if (!itemSel || !priceInput) return;
        var opt = itemSel.options[itemSel.selectedIndex];
        if (opt && opt.dataset.price) {
            priceInput.value = opt.dataset.price;
        }
    }

    if (typeSel) {
        typeSel.addEventListener('change', syncMinibarVisibility);
        syncMinibarVisibility();
    }
    if (itemSel) {
        itemSel.addEventListener('change', applyItemPrice);
    }
})();
</script>
@endpush
@endonce
