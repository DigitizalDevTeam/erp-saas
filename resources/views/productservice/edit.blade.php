{{ Form::model($productService, array('route' => array('productservice.update', $productService->id), 'method' => 'PUT','enctype' => "multipart/form-data", 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    {{-- start for ai module--}}
    @php
        $plan= \App\Models\Utility::getChatGPTSettings();
    @endphp
    @if($plan->chatgpt == 1)
    <div class="text-end mb-3">
        <a href="#" data-size="md" class="btn  btn-primary btn-icon btn-sm" data-ajax-popup-over="true" data-url="{{ route('generate',['productservice']) }}"
           data-bs-placement="top" data-title="{{ __('Generate content with AI') }}">
            <i class="fas fa-robot"></i> <span>{{__('Generate with AI')}}</span>
        </a>
    </div>
    @endif
    {{-- end for ai module--}}
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('name', __('Name'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::text('name',null, array('class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Name'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sku', __('SKU'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::text('sku', null, array('class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Sku'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('shelf_no', __('Shelf Number'),['class'=>'form-label']) }}
                {{ Form::text('shelf_no', $productService->shelf_no, array('class' => 'form-control', 'placeholder' => __('Enter Shelf Number'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('row_no', __('Row Number'),['class'=>'form-label']) }}
                {{ Form::text('row_no', $productService->row_no, array('class' => 'form-control', 'placeholder' => __('Enter Row Number'))) }}
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sale_price', __('Sale Price'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::number('sale_price', null, array('class' => 'form-control','required'=>'required','step'=>'0.01', 'placeholder' => __('Enter Sale Price'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sale_price_type', __('Price includes VAT?'),['class'=>'form-label']) }}
                <select name="sale_price_type" class="form-control select">
                    <option value="exclusive" {{ ($productService->sale_price_type ?? 'exclusive') === 'exclusive' ? 'selected' : '' }}>{{ __('Exclusive VAT') }} ({{ __('VAT will be added on top') }})</option>
                    <option value="inclusive" {{ ($productService->sale_price_type ?? '') === 'inclusive' ? 'selected' : '' }}>{{ __('Inclusive VAT') }} ({{ __('VAT is included in price') }})</option>
                </select>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('sale_chartaccount_id', __('Income Account'),['class'=>'form-label']) }}<x-required></x-required>
            <select name="sale_chartaccount_id" class="form-control" required="required">
                @foreach ($incomeChartAccounts as $key => $chartAccount)
                    <option value="{{ $key }}" class="subAccount" {{ ($productService->sale_chartaccount_id == $key) ? 'selected' : ''}}>{{ $chartAccount }}</option>
                    @foreach ($incomeSubAccounts as $subAccount)
                        @if ($key == $subAccount['account'])
                            <option value="{{ $subAccount['id'] }}" class="ms-5" {{ ($productService->sale_chartaccount_id == $subAccount['id']) ? 'selected' : ''}}> &nbsp; &nbsp;&nbsp; {{ isset($subAccount['code_name']) ? $subAccount['code_name'] : '' }}</option>
                        @endif
                    @endforeach
                @endforeach
            </select>
            <div class="text-xs mt-1">
                {{ __('Create account here.') }} <a href="{{ route('chart-of-account.index') }}"><b>{{ __('Create account') }}</b></a>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('purchase_price', __('Purchase Price'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::number('purchase_price', null, array('class' => 'form-control','required'=>'required','step'=>'0.01', 'placeholder' => __('Enter Purchase Price'))) }}
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('expense_chartaccount_id', __('Expense Account'),['class'=>'form-label']) }}
            <select name="expense_chartaccount_id" class="form-control" required="required">
                @foreach ($expenseChartAccounts as $key => $chartAccount)
                    <option value="{{ $key }}" class="subAccount" {{ ($productService->expense_chartaccount_id == $key) ? 'selected' : ''}}>{{ $chartAccount }}</option>
                    @foreach ($expenseSubAccounts as $subAccount)
                        @if ($key == $subAccount['account'])
                            <option value="{{ $subAccount['id'] }}" class="ms-5" {{ ($productService->expense_chartaccount_id == $subAccount['id']) ? 'selected' : ''}}> &nbsp; &nbsp;&nbsp; {{ $subAccount['code_name'] }}</option>
                        @endif
                    @endforeach
                @endforeach
            </select>
            <div class="text-xs mt-1">
                {{ __('Create account here.') }} <a href="{{ route('chart-of-account.index') }}"><b>{{ __('Create account') }}</b></a>
            </div>
        </div>

        <div class="form-group  col-md-6">
            {{ Form::label('tax_id', __('Tax'),['class'=>'form-label']) }}
            {{ Form::select('tax_id[]', $tax,null, array('class' => 'form-control select2','id'=>'choices-multiple1','multiple'=>'')) }}
            <div class=" text-xs mt-1">
                {{__('Create tax here. ')}}<a href="{{route('taxes.index')}}"><b>{{__('Create tax')}}</b></a>
            </div>
        </div>
        <div class="form-group  col-md-6">
            <div class="form-group">
                {{ Form::label('price_level_id', __('Discount Type'), ['class' => 'form-label']) }}
                <select name="price_level_id" class="form-control select">
                    <option value="">{{ __('Select Discount Type') }}</option>
                    @foreach($priceLevels as $level)
                        @php
                            $type = ucfirst($level->type); // Fixed or Percentage
                            $value = $level->type === 'percentage' ? $level->value . '%' : '$' . $level->value;
                        @endphp
                        <option value="{{ $level->id }}" {{ $level->id == $productService->price_level_id ? 'selected' : '' }}>{{ $level->name }} - {{ $type }} - {{ $value }}</option>
                    @endforeach
                </select>
            </div>
            <div class=" text-xs mt-1">
                {{__('Create discount type here. ')}}<a href="{{route('price-levels.index')}}"><b>{{__('Create discount type')}}</b></a>
            </div>
        </div>
        <div class="form-group  col-md-6">
            {{ Form::label('category_id', __('Category'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::select('category_id', $category,null, array('class' => 'form-control select','required'=>'required')) }}
            <div class=" text-xs mt-1">
                {{__('Create category here. ')}}<a href="{{route('product-category.index')}}"><b>{{__('Create Category')}}</b></a>
            </div>
        </div>
        <div class="form-group  col-md-6">
            {{ Form::label('unit_id', __('Unit'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::select('unit_id', $unit,null, array('class' => 'form-control select','required'=>'required')) }}
            <div class=" text-xs mt-1">
                {{__('Create unit here. ')}}<a href="{{route('product-unit.index')}}"><b>{{__('Create unit')}}</b></a>
            </div>
        </div>

        <div class="col-md-6 form-group">
            {{Form::label('pro_image',__('Product Image'),['class'=>'form-label'])}}
            <div class="choose-file ">
                <label for="pro_image" class="form-label">
                    <input type="file" class="form-control file-validate" name="pro_image" id="pro_image" data-filename="pro_image_create">
                <p id="" class="file-error text-danger"></p>
                    <img id="image" class="mt-3" width="100" src="{{ $productService->pro_image ? \App\Models\Utility::get_file('uploads/pro_image/'.$productService->pro_image) : asset(Storage::url('uploads/pro_image/user-2_1654779769.jpg'))}}" />
                </label>
            </div>
        </div>



        <div class="col-md-6">
            <div class="form-group">
                <label class="d-block form-label">{{__('Type')}}</label>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadio5" name="type" value="product" @if($productService->type=='product') checked @endif >
                            <label class="custom-control-label form-label" for="customRadio5">{{__('Product')}}</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadio6" name="type" value="service" @if($productService->type=='service') checked @endif >
                            <label class="custom-control-label form-label" for="customRadio6">{{__('Service')}}</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadio7" name="type" value="kit" @if($productService->type=='kit') checked @endif >
                            <label class="custom-control-label form-label" for="customRadio7">{{__('Kit')}}</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('print_zone', __('Print destination (Restaurant)'), ['class' => 'form-label']) }}
            {{ Form::select('print_zone', ['' => __('None'), 'kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'both' => __('Both')], $productService->print_zone ?? '', ['class' => 'form-control select']) }}
            <small class="text-muted">{{ __('Where this product prints when sent to production (Kitchen/Bar slip).') }}</small>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('pos_printer_id', __('POS Printer'), ['class' => 'form-label']) }}
            <select name="pos_printer_id" class="form-control select">
                <option value="">{{ __('Default (by zone)') }}</option>
                @foreach($printers ?? [] as $pr)
                    <option value="{{ $pr->id }}" {{ $productService->pos_printer_id == $pr->id ? 'selected' : '' }}>{{ $pr->branch ? $pr->branch->name : '' }} – {{ $pr->name }} ({{ $pr->type }})</option>
                @endforeach
            </select>
            <small class="text-muted">{{ __('Send this product to this printer in POS. Leave default to use zone.') }}</small>
        </div>

        <div class="form-group col-md-6 quantity {{in_array($productService->type, ['service','kit']) ? 'd-none':''}}">
            {{ Form::label('quantity', __('Quantity'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('quantity',null, array('class' => 'form-control','required'=>'required')) }}
        </div>
        <div class="form-group col-md-6 quantity {{in_array($productService->type, ['service','kit']) ? 'd-none':''}}">
            {{ Form::label('low_stock_threshold', __('Low stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('low_stock_threshold', $productService->low_stock_threshold, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Default'))) }}
            <small class="text-muted">{{ __('Leave empty to use system default. Alert when quantity is below this.') }}</small>
        </div>
        <div class="form-group col-md-6 quantity {{in_array($productService->type, ['service','kit']) ? 'd-none':''}}">
            {{ Form::label('max_stock_threshold', __('Max stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('max_stock_threshold', $productService->max_stock_threshold, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Optional'))) }}
            <small class="text-muted">{{ __('Alert when quantity is above this (optional).') }}</small>
        </div>
        <div class="form-group col-md-12 quantity {{in_array($productService->type, ['service','kit']) ? 'd-none':''}}">
            <div class="form-check">
                {{ Form::checkbox('does_not_affect_stock', '1', !($productService->affect_stock ?? true), ['class' => 'form-check-input', 'id' => 'does_not_affect_stock_edit']) }}
                {{ Form::label('does_not_affect_stock', __('Does not affect stock (e.g. composite dish – components are deducted separately)'), ['class' => 'form-check-label', 'for' => 'does_not_affect_stock_edit']) }}
            </div>
            <small class="text-muted">{{ __('When checked: on POS sale this product’s own quantity is not used. (For kits, use the Kit Components section below.)') }}</small>
        </div>

        <div class="col-md-12">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="identifier_tracking" id="identifier_tracking_edit" value="1" {{ ($productService->identifier_tracking ?? false) ? 'checked' : '' }}>
                <label class="form-check-label" for="identifier_tracking_edit">{{ __('Track Serial Number / MAC Address') }}</label>
            </div>
            <div class="row mt-2" id="identifier_tracking_edit_fields" style="{{ ($productService->identifier_tracking ?? false) ? '' : 'display:none;' }}">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Tracking type') }}</label>
                    <select name="identifier_type" class="form-control select">
                        <option value="serial" {{ ($productService->identifier_type ?? 'serial') === 'serial' ? 'selected' : '' }}>{{ __('Serial Number') }}</option>
                        <option value="mac" {{ ($productService->identifier_type ?? '') === 'mac' ? 'selected' : '' }}>{{ __('MAC Address') }}</option>
                        <option value="both" {{ ($productService->identifier_type ?? '') === 'both' ? 'selected' : '' }}>{{ __('Serial + MAC') }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Warranty (days)') }}</label>
                    <input type="number" name="warranty_days" class="form-control" min="0" step="1" value="{{ $productService->warranty_days ?? '' }}" placeholder="{{ __('Optional') }}">
                </div>
            </div>
            <small class="text-muted d-block mt-1">{{ __('If enabled: stock-in and sales will require one unique identifier per unit (no duplicates).') }}</small>
        </div>

        <div class="col-md-12 mt-2">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Expiry alert (days before)') }}</label>
                    <input type="number" name="expiry_alert_days" class="form-control" min="0" step="1" value="{{ $productService->expiry_alert_days ?? '' }}" placeholder="{{ __('Optional') }}">
                    <small class="text-muted">{{ __('Show dashboard alert when expiry is within this many days.') }}</small>
                </div>
            </div>
        </div>

        <div class="col-12 {{ (($productService->type ?? '') === 'kit') ? '' : 'd-none' }}" id="kit_components_edit_section">
            <hr class="my-3">
            <h6 class="mb-2">{{ __('Kit Components') }}</h6>
            <p class="text-muted small mb-2">{{ __('When you sell 1 kit, stock will be deducted from each component by the qty per kit.') }}</p>

            <div class="table-responsive mb-2">
                <table class="table table-sm" id="kit_components_edit_table">
                    <thead>
                        <tr>
                            <th>{{ __('Component') }}</th>
                            <th class="text-end">{{ __('Qty per kit') }}</th>
                            <th width="120" class="text-end">{{ __('Remove') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(($kitItems ?? []) as $ki)
                            <tr>
                                <td>{{ $ki->component ? $ki->component->name : '-' }}{{ $ki->component && $ki->component->sku ? ' (' . $ki->component->sku . ')' : '' }}</td>
                                <td class="text-end">{{ $ki->quantity }}</td>
                                <td class="text-end">
                                    <div class="form-check d-inline-block">
                                        <input class="form-check-input" type="checkbox" name="remove_kit_item_ids[]" value="{{ $ki->id }}" id="remove_kit_{{ $ki->id }}">
                                        <label class="form-check-label" for="remove_kit_{{ $ki->id }}"></label>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="text-muted"><td colspan="3">{{ __('No components yet. Add below, then click Update.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Component product') }}</label>
                    <select class="form-select" id="kit_component_edit_select">
                        <option value="">{{ __('Select component') }}</option>
                        @foreach(($kitComponents ?? []) as $cp)
                            <option value="{{ $cp->id }}">{{ $cp->name }}{{ $cp->sku ? ' (' . $cp->sku . ')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Quantity') }}</label>
                    <input type="number" class="form-control" id="kit_component_edit_qty" step="0.0001" min="0.0001" value="1">
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-primary w-100" id="kit_component_edit_add_btn">{{ __('Add Component') }}</button>
                </div>
            </div>

            <small class="text-muted d-block mt-2">{{ __('After adding/removing components, click Update to save.') }}</small>
        </div>
        <div class="form-group  col-md-12">
            {{ Form::label('description', __('Description'),['class'=>'form-label']) }}
            {!! Form::textarea('description', null, ['class'=>'form-control','rows'=>'2']) !!}
        </div>


    </div>
    @if(!$customFields->isEmpty())
                @include('customFields.formBuilder')
    @endif
</div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn  btn-primary">
</div>
{{Form::close()}}
<script>
    document.getElementById('pro_image').onchange = function () {
        var src = URL.createObjectURL(this.files[0])
        document.getElementById('image').src = src
    }

    //hide & show quantity

    $(document).on('click', '.type', function ()
    {
        var type = $(this).val();
        if (type == 'product') {
            $('.quantity').removeClass('d-none')
            $('.quantity').addClass('d-block');
            $('input[name="quantity"]').prop('required', true);
        } else {
            $('.quantity').addClass('d-none')
            $('.quantity').removeClass('d-block');
            $('input[name="quantity"]').val('').prop('required', false);
        }

        if (type == 'kit') {
            $('#kit_components_edit_section').removeClass('d-none');
        } else {
            $('#kit_components_edit_section').addClass('d-none');
        }
    });

    (function () {
        let kitRowIdx = 0;
        $('#kit_component_edit_add_btn').on('click', function () {
            const componentId = $('#kit_component_edit_select').val();
            const componentText = $('#kit_component_edit_select option:selected').text();
            const qty = parseFloat($('#kit_component_edit_qty').val() || '0');
            if (!componentId || !qty || qty <= 0) return;

            const $tbody = $('#kit_components_edit_table tbody');
            // remove "no components" placeholder row if present
            if ($tbody.find('tr').length === 1 && $tbody.find('tr').first().hasClass('text-muted')) {
                $tbody.empty();
            }

            const safeText = $('<div>').text(componentText).html();
            const row = `
                <tr data-new-kit-row="${kitRowIdx}">
                    <td>
                        ${safeText}
                        <input type="hidden" name="kit_components[${kitRowIdx}][component_product_id]" value="${componentId}">
                    </td>
                    <td class="text-end">
                        ${qty}
                        <input type="hidden" name="kit_components[${kitRowIdx}][quantity]" value="${qty}">
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger kit-component-remove"><i class="ti ti-trash"></i></button>
                    </td>
                </tr>`;
            $tbody.append(row);
            kitRowIdx++;
        });

        $(document).on('click', '#kit_components_edit_table .kit-component-remove', function () {
            $(this).closest('tr').remove();
        });
    })();

    $(document).on('change', '#identifier_tracking_edit', function () {
        $('#identifier_tracking_edit_fields').toggle(this.checked);
    });
</script>

