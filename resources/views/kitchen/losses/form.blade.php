@extends('layouts.admin')
@section('page-title')
    {{ $loss && $loss->exists ? __('Edit Loss') : __('Record Loss') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.losses.index') }}">{{ __('Kitchen Losses') }}</a></li>
    <li class="breadcrumb-item">{{ $loss && $loss->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($loss && $loss->exists)
                        {{ Form::model($loss, ['route' => ['kitchen.losses.update', $loss->id], 'method' => 'PUT', 'class' => 'needs-validation', 'novalidate']) }}
                    @else
                        {{ Form::open(['route' => 'kitchen.losses.store', 'method' => 'POST', 'class' => 'needs-validation', 'novalidate']) }}
                    @endif

                    @if ($batch)
                        <div class="alert alert-info">{{ __('Recording a loss from batch :batch.', ['batch' => $batch->batch_number]) }}</div>
                    @endif
                    @if ($order && ! $batch)
                        <div class="alert alert-info">{{ __('Recording a loss from production order :order.', ['order' => $order->order_number ?? ('#'.$order->id)]) }}</div>
                    @endif

                    <div class="row">
                        <div class="form-group col-md-4">
                            {{ Form::label('category', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('category', array_combine($categories, array_map(fn ($c) => ucfirst(str_replace('_', ' ', $c)), $categories)), $selectedCategory, ['class' => 'form-control', 'id' => 'loss-category', 'required' => 'required', 'placeholder' => __('Select a category')]) }}
                            <small class="text-muted" id="category-approval-note"></small>
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('product_id', __('Product'), ['class' => 'form-label']) }}<x-required></x-required>
                            <select name="product_id" id="loss-product" class="form-control" required>
                                <option value="">{{ __('Select a product') }}</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}"
                                        data-unit="{{ $product->unit_id }}"
                                        data-cost="{{ $product->purchase_price }}"
                                        {{ (int) $selectedProductId === (int) $product->id ? 'selected' : '' }}>
                                        {{ $product->name }} ({{ ucfirst($product->type) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('warehouse_id', $warehouses->pluck('name', 'id'), $selectedWarehouseId, ['class' => 'form-control', 'id' => 'loss-warehouse', 'required' => 'required', 'placeholder' => __('Select a warehouse')]) }}
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="form-group col-md-4">
                            {{ Form::label('kitchen_production_batch_id', __('Batch (optional)'), ['class' => 'form-label']) }}
                            <select name="kitchen_production_batch_id" id="loss-batch" class="form-control">
                                <option value="">{{ __('No batch — warehouse stock only') }}</option>
                                @foreach ($batches as $batchOption)
                                    <option value="{{ $batchOption->id }}"
                                        data-product="{{ $batchOption->product_id }}"
                                        data-warehouse="{{ $batchOption->warehouse_id }}"
                                        data-cost="{{ $batchOption->unit_cost }}"
                                        data-remaining="{{ $batchOption->quantity_remaining }}"
                                        {{ $batch && (int) $batch->id === (int) $batchOption->id ? 'selected' : '' }}>
                                        {{ $batchOption->batch_number }} — {{ number_format((float) $batchOption->quantity_remaining, 2) }} {{ $batchOption->unit ? $batchOption->unit->name : '' }} {{ __('left') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('kitchen_production_order_id', __('Production Order (optional)'), ['class' => 'form-label']) }}
                            {{ Form::select('kitchen_production_order_id', $orders->pluck('order_number', 'id'), $order ? $order->id : null, ['class' => 'form-control', 'placeholder' => __('No production order')]) }}
                        </div>
                        <div class="form-group col-md-4" id="destination-warehouse-group" style="display: none;">
                            {{ Form::label('destination_warehouse_id', __('Destination Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('destination_warehouse_id', $warehouses->pluck('name', 'id'), null, ['class' => 'form-control', 'id' => 'loss-destination-warehouse', 'placeholder' => __('Select destination warehouse')]) }}
                            <small class="text-muted">{{ __('The kitchen the stock is moved to. Nothing is written off.') }}</small>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="form-group col-md-4">
                            {{ Form::label('quantity', __('Quantity'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::number('quantity', null, ['class' => 'form-control', 'id' => 'loss-quantity', 'step' => '0.0001', 'min' => '0.0001', 'required' => 'required', 'placeholder' => 'e.g. 2']) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('unit_id', __('Unit'), ['class' => 'form-label']) }}
                            <select name="unit_id" id="loss-unit" class="form-control">
                                <option value="">{{ __('Stock unit') }}</option>
                                @foreach ($units as $unit)
                                    <option value="{{ $unit->id }}" {{ $loss && $loss->unit_id && (int) $loss->unit_id === (int) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Leave empty to use the product stock unit.') }}</small>
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('unit_cost', __('Unit Cost (optional)'), ['class' => 'form-label']) }}
                            {{ Form::number('unit_cost', null, ['class' => 'form-control', 'id' => 'loss-unit-cost', 'step' => '0.0001', 'min' => '0', 'placeholder' => '0']) }}
                            <small class="text-muted">{{ __('Leave empty to use the batch cost, or the product purchase price.') }}</small>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="form-group col-md-12">
                            {{ Form::label('reason', __('Reason'), ['class' => 'form-label']) }}
                            {{ Form::text('reason', null, ['class' => 'form-control', 'placeholder' => __('What happened? e.g. burnt during service')]) }}
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="form-group col-md-12">
                            <div class="form-check">
                                <input type="checkbox" name="reuse_flag" value="1" id="loss-reuse-flag" class="form-check-input" {{ $loss && $loss->reuse_flag ? 'checked' : '' }}>
                                <label class="form-check-label" for="loss-reuse-flag">{{ __('This food is still safe and can be reused') }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-2" id="reuse-fields" style="display: none;">
                        <div class="form-group col-md-6">
                            {{ Form::label('reuse_destination', __('Where does the reused food go?'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('reuse_destination', array_combine($reuseDestinations, array_map(fn ($d) => ucfirst(str_replace('_', ' ', $d)), $reuseDestinations)), $loss ? $loss->reuse_destination : null, ['class' => 'form-control', 'id' => 'loss-reuse-destination', 'placeholder' => __('Select a destination')]) }}
                            <small class="text-muted">{{ __('Reused food never goes back into a batch that is already sold.') }}</small>
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('food_safety_note', __('Food-Safety Note'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::textarea('food_safety_note', null, ['class' => 'form-control', 'id' => 'loss-food-safety-note', 'rows' => 2, 'placeholder' => __('Why is this food still safe? e.g. kept below 5°C, sealed, within 2 hours')]) }}
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <a href="{{ route('kitchen.losses.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ $loss && $loss->exists ? __('Update Loss') : __('Record Loss') }}</button>
                    </div>

                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
(function () {
    var categorySelect = document.getElementById('loss-category');
    var approvalNote = document.getElementById('category-approval-note');
    var approvalCategories = @json($approvalCategories);
    var destinationGroup = document.getElementById('destination-warehouse-group');
    var destinationSelect = document.getElementById('loss-destination-warehouse');
    var reuseFlag = document.getElementById('loss-reuse-flag');
    var reuseFields = document.getElementById('reuse-fields');
    var reuseDestination = document.getElementById('loss-reuse-destination');
    var foodSafetyNote = document.getElementById('loss-food-safety-note');
    var productSelect = document.getElementById('loss-product');
    var warehouseSelect = document.getElementById('loss-warehouse');
    var batchSelect = document.getElementById('loss-batch');
    var unitSelect = document.getElementById('loss-unit');
    var unitCostInput = document.getElementById('loss-unit-cost');

    function updateCategory() {
        var category = categorySelect ? categorySelect.value : '';
        if (approvalNote) {
            approvalNote.textContent = approvalCategories.indexOf(category) !== -1
                ? '{{ __('This category needs a manager\'s approval before it can be posted.') }}'
                : (category ? '{{ __('This category is recorded directly — no approval needed.') }}' : '');
        }
        if (destinationGroup) {
            destinationGroup.style.display = category === 'transfer' ? '' : 'none';
        }
        if (destinationSelect) {
            destinationSelect.required = category === 'transfer';
        }
    }

    function updateReuse() {
        var checked = reuseFlag ? reuseFlag.checked : false;
        if (reuseFields) {
            reuseFields.style.display = checked ? '' : 'none';
        }
        if (reuseDestination) {
            reuseDestination.required = checked;
        }
        if (foodSafetyNote) {
            foodSafetyNote.required = checked;
        }
    }

    function updateFromBatch() {
        var option = batchSelect && batchSelect.selectedOptions ? batchSelect.selectedOptions[0] : null;
        if (! option || ! option.value) {
            return;
        }
        var productId = option.getAttribute('data-product');
        var warehouseId = option.getAttribute('data-warehouse');
        var cost = option.getAttribute('data-cost');
        if (productSelect && productId) {
            productSelect.value = productId;
        }
        if (warehouseSelect && warehouseId) {
            warehouseSelect.value = warehouseId;
        }
        if (unitCostInput && cost !== null && cost !== '' && unitCostInput.value === '') {
            unitCostInput.value = cost;
        }
    }

    function updateFromProduct() {
        var option = productSelect && productSelect.selectedOptions ? productSelect.selectedOptions[0] : null;
        if (! option || ! option.value) {
            return;
        }
        var unitId = option.getAttribute('data-unit');
        var cost = option.getAttribute('data-cost');
        if (unitSelect && unitId && unitSelect.value === '') {
            unitSelect.value = unitId;
        }
        if (unitCostInput && cost !== null && cost !== '' && unitCostInput.value === '') {
            unitCostInput.value = cost;
        }
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', updateCategory);
    }
    if (reuseFlag) {
        reuseFlag.addEventListener('change', updateReuse);
    }
    if (batchSelect) {
        batchSelect.addEventListener('change', updateFromBatch);
    }
    if (productSelect) {
        productSelect.addEventListener('change', updateFromProduct);
    }

    updateCategory();
    updateReuse();
})();
</script>
@endpush
