@php
    $formAction = $formAction ?? '#';
    $formMethod = $formMethod ?? 'POST';
    $roomId = $roomId ?? null;
    $chargeTypes = $chargeTypes ?? [
        'minibar' => __('Minibar / Room consumable'),
        'laundry' => __('Laundry'),
        'misc' => __('Other extra'),
    ];
    $products = $products ?? collect();
    $defaultDate = $defaultDate ?? now()->toDateString();
    $redirectTo = $redirectTo ?? null;
    $productInfoUrl = route('hotel.consumption.product-info');
@endphp
{!! Form::open(['url' => $formAction, 'method' => $formMethod, 'class' => 'row g-2 hotel-consumption-form', 'id' => $formId ?? null, 'data-product-info-url' => $productInfoUrl]) !!}
@if($roomId)
    {!! Form::hidden('hotel_room_id', $roomId) !!}
@endif
@if($redirectTo)
    {!! Form::hidden('redirect_to', $redirectTo) !!}
@endif
<div class="col-md-3">
    {!! Form::label('charge_type', __('Charge type')) !!}
    {!! Form::select('charge_type', $chargeTypes, old('charge_type', 'minibar'), ['class' => 'form-control js-charge-type']) !!}
</div>
<div class="col-md-4 js-product-wrap">
    {!! Form::label('product_id', __('Product')) !!}
    <select name="product_id" class="form-control js-product-select">
        <option value="">{{ __('Select product') }}</option>
        @foreach($products as $product)
            <option value="{{ $product->id }}" data-price="{{ $product->sale_price }}" @selected(old('product_id') == $product->id)>
                {{ $product->name }}@if($product->sku) ({{ $product->sku }})@endif — {{ Auth::user()->priceFormat($product->sale_price) }}
            </option>
        @endforeach
    </select>
</div>
<div class="col-md-3 js-warehouse-wrap">
    {!! Form::label('warehouse_id', __('Deduct from warehouse')) !!}
    <select name="warehouse_id" class="form-control js-warehouse-select">
        <option value="">{{ __('Select a product first') }}</option>
    </select>
</div>
<div class="col-md-4 js-description-wrap">
    {!! Form::label('description', __('Description')) !!}
    {!! Form::text('description', old('description'), ['class' => 'form-control', 'maxlength' => '191', 'placeholder' => __('Optional for products; required for other extras')]) !!}
</div>
<div class="col-md-2">
    {!! Form::label('quantity', __('Qty')) !!}
    {!! Form::number('quantity', old('quantity', 1), ['class' => 'form-control js-qty', 'min' => '1']) !!}
</div>
<div class="col-md-2">
    {!! Form::label('unit_price', __('Unit price')) !!}
    {!! Form::number('unit_price', old('unit_price'), ['class' => 'form-control js-unit-price', 'step' => '0.01', 'min' => '0']) !!}
</div>
<div class="col-md-2">
    {!! Form::label('service_date', __('Service date')) !!}
    {!! Form::date('service_date', old('service_date', $defaultDate), ['class' => 'form-control']) !!}
</div>
<div class="col-md-4">
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
    function initConsumptionForm(form) {
        var typeSel = form.querySelector('.js-charge-type');
        var productWrap = form.querySelector('.js-product-wrap');
        var warehouseWrap = form.querySelector('.js-warehouse-wrap');
        var productSel = form.querySelector('.js-product-select');
        var warehouseSel = form.querySelector('.js-warehouse-select');
        var priceInput = form.querySelector('.js-unit-price');
        var infoUrl = form.getAttribute('data-product-info-url');
        var selectProductFirst = warehouseSel ? warehouseSel.innerHTML : '';

        function isProductCharge() {
            return typeSel && typeSel.value === 'minibar';
        }

        function syncVisibility() {
            var product = isProductCharge();
            if (productWrap) productWrap.style.display = product ? '' : 'none';
            if (warehouseWrap) warehouseWrap.style.display = product ? '' : 'none';
            if (priceInput) priceInput.readOnly = product;
            if (productSel) productSel.required = product;
            if (warehouseSel) warehouseSel.required = product;
        }

        function loadProductInfo() {
            if (!productSel || !productSel.value || !infoUrl) {
                if (warehouseSel) warehouseSel.innerHTML = selectProductFirst;
                return;
            }
            var url = infoUrl + (infoUrl.indexOf('?') === -1 ? '?' : '&') + 'product_id=' + encodeURIComponent(productSel.value);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (priceInput && typeof data.price !== 'undefined') {
                        priceInput.value = parseFloat(data.price).toFixed(2);
                    }
                    if (warehouseSel) {
                        warehouseSel.innerHTML = '';
                        (data.warehouses || []).forEach(function (w) {
                            var opt = document.createElement('option');
                            opt.value = w.id;
                            opt.textContent = w.name + ' (' + (w.qty || 0) + ')';
                            warehouseSel.appendChild(opt);
                        });
                        if (!warehouseSel.options.length) {
                            warehouseSel.innerHTML = '<option value="">{{ __('No warehouse found') }}</option>';
                        }
                    }
                })
                .catch(function () {});
        }

        if (typeSel) {
            typeSel.addEventListener('change', syncVisibility);
        }
        if (productSel) {
            productSel.addEventListener('change', loadProductInfo);
        }
        syncVisibility();
        if (productSel && productSel.value) {
            loadProductInfo();
        }
    }

    document.querySelectorAll('.hotel-consumption-form').forEach(initConsumptionForm);
})();
</script>
@endpush
@endonce
