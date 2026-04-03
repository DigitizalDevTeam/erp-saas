{{ Form::model($productService, array('route' => array('productstock.update', $productService->id), 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    <div class="row">

        <div class="form-group col-md-6">
            {{ Form::label('Product', __('Product'),['class'=>'form-label']) }}<br>
            {{$productService->name}}

        </div>
        <div class="form-group col-md-6">
            {{ Form::label('Product', __('SKU'),['class'=>'form-label']) }}<br>
            {{$productService->sku}}

        </div>
        <div class="form-group col-md-6">
            {{ Form::label('quantity', __('Quantity'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::number('quantity',"", array('class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Quantity'))) }}
        </div>
        @if(isset($singleWarehouseId) && $singleWarehouseId)
            <div class="form-group col-md-12">
                {{ Form::label('warehouse_id', __('Warehouse'),['class'=>'form-label']) }}
                @php $wname = ($warehouses[$singleWarehouseId] ?? ''); @endphp
                <div class="form-control-plaintext">{{ $wname }}</div>
                {{ Form::hidden('warehouse_id', $singleWarehouseId) }}
            </div>
        @elseif(isset($warehouses) && $warehouses->count() > 1)
            <div class="form-group col-md-12">
                {{ Form::label('warehouse_id', __('Warehouse'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::select('warehouse_id', $warehouses, null, ['class' => 'form-control select', 'required' => 'required']) }}
            </div>
        @endif
        <div class="form-group col-md-6">
            {{ Form::label('shelf_no', __('Shelf number'),['class'=>'form-label']) }}
            {{ Form::text('shelf_no', $productService->shelf_no, ['class' => 'form-control', 'placeholder' => __('Optional')]) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('row_no', __('Row'),['class'=>'form-label']) }}
            {{ Form::text('row_no', $productService->row_no, ['class' => 'form-control', 'placeholder' => __('Optional')]) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('expiry_date', __('Expiry Date'),['class'=>'form-label']) }}
            {{ Form::date('expiry_date', null, ['class' => 'form-control']) }}
            <small class="text-muted">{{ __('Optional. Used for expiry alerts.') }}</small>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('batch_no', __('Batch No'),['class'=>'form-label']) }}
            {{ Form::text('batch_no', null, ['class' => 'form-control', 'placeholder' => __('Optional')]) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('mfg_date', __('MFG Date'),['class'=>'form-label']) }}
            {{ Form::date('mfg_date', null, ['class' => 'form-control']) }}
        </div>
        @if(($productService->identifier_tracking ?? false))
            <div class="form-group col-md-12">
                <label class="form-label">
                    @if(($productService->identifier_type ?? 'serial') === 'mac')
                        {{ __('MAC Address List') }}
                    @elseif(($productService->identifier_type ?? '') === 'both')
                        {{ __('Serial/MAC List') }}
                    @else
                        {{ __('Serial Number List') }}
                    @endif
                </label>
                {{ Form::textarea('identifiers', null, ['class' => 'form-control', 'rows' => 4, 'placeholder' => __('Enter one per line')]) }}
                <small class="text-muted">{{ __('Required: exactly N identifiers where N = quantity. No duplicates allowed.') }}</small>
            </div>
        @endif
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Save')}}" class="btn  btn-primary">
</div>
{{Form::close()}}
