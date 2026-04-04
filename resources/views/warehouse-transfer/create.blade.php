{{ Form::open(array('url' => 'warehouse-transfer', 'class'=>'needs-validation', 'novalidate', 'id' => 'warehouse-transfer-form')) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group col-md-6">
            {{ Form::label('from_warehouse', __('From Warehouse'),['class'=>'form-label']) }}<x-required></x-required>
            <select class="form-control select wt-from-warehouse" name="from_warehouse" id="wt_warehouse_id" required>
                <option value="">{{__('Select Warehouse')}}</option>
                @foreach($from_warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
            <div class="text-xs mt-1">
                {{ __('Create warehouse here.') }} <a href="{{ route('warehouse.index') }}"><b>{{ __('Create warehouse') }}</b></a>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{Form::label('to_warehouse',__('To Warehouse'),array('class'=>'form-label')) }}<x-required></x-required>
            {{ Form::select('to_warehouse', $to_warehouses,null, array('class' => 'form-control select wt-to-warehouse','required'=>'required', 'id' => 'wt_to_warehouse')) }}
            <div class="text-xs mt-1">
                {{ __('Create warehouse here.') }} <a href="{{ route('warehouse.index') }}"><b>{{ __('Create warehouse') }}</b></a>
            </div>
        </div>

        <div class="form-group col-lg-6">
            {{Form::label('date',__('Date'))}}
            {{Form::date('date',null,array('class'=>'form-control datepicker w-100 mt-2', 'id' => 'wt_date'))}}
        </div>

        <div class="col-12 mt-2">
            <label class="form-label">{{ __('Products to transfer') }}</label>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-2" id="wt-lines-table">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 220px;">{{ __('Product') }}</th>
                            <th style="width: 140px;">{{ __('Quantity') }}</th>
                            <th style="width: 48px;"></th>
                        </tr>
                    </thead>
                    <tbody id="wt-lines-body">
                        <tr class="wt-line-row">
                            <td>
                                <select class="form-control form-control-sm wt-product-select" name="items[0][product_id]">
                                    <option value="">{{ __('Select Product') }}</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm wt-qty-input" name="items[0][quantity]" placeholder="0">
                                <small class="text-muted wt-max-hint d-none"></small>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger wt-remove-line d-none" title="{{ __('Remove') }}"><i class="ti ti-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="wt-add-line">
                <i class="ti ti-plus"></i> {{ __('Add line') }}
            </button>
            <small class="text-muted d-block mt-1">{{ __('Transfer several products in one go. Choose source and destination warehouses once, then add lines.') }}</small>
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn btn-primary">
</div>
{{ Form::close() }}
