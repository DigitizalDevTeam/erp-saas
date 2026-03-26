{{ Form::open(array('url' => 'productservice','enctype' => "multipart/form-data", 'class'=>'needs-validation', 'novalidate')) }}
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
                {{ Form::text('name', '', array('class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Name'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sku', __('SKU'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::text('sku', '', array('class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Sku'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('shelf_no', __('Shelf Number'),['class'=>'form-label']) }}
                {{ Form::text('shelf_no', '', array('class' => 'form-control', 'placeholder' => __('Enter Shelf Number'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('row_no', __('Row Number'),['class'=>'form-label']) }}
                {{ Form::text('row_no', '', array('class' => 'form-control', 'placeholder' => __('Enter Row Number'))) }}
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sale_price', __('Sale Price'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::number('sale_price', '', array('class' => 'form-control','required'=>'required','step'=>'0.01', 'placeholder' => __('Enter Sale Price'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sale_price_type', __('Price includes VAT?'),['class'=>'form-label']) }}
                <select name="sale_price_type" class="form-control select">
                    <option value="exclusive">{{ __('Exclusive VAT') }} ({{ __('VAT will be added on top') }})</option>
                    <option value="inclusive">{{ __('Inclusive VAT') }} ({{ __('VAT is included in price') }})</option>
                </select>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('sale_chartaccount_id', __('Income Account'),['class'=>'form-label']) }}<x-required></x-required>
            <select name="sale_chartaccount_id" class="form-control" required="required">
                @foreach ($incomeChartAccounts as $key => $chartAccount)
                    <option value="{{ $key }}" class="subAccount">{{ $chartAccount }}</option>
                    @foreach ($incomeSubAccounts as $subAccount)
                        @if ($key == $subAccount['account'])
                            <option value="{{ $subAccount['id'] }}" class="ms-5"> &nbsp; &nbsp;&nbsp; {{ $subAccount['code_name'] }}</option>
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
                {{ Form::number('purchase_price', '', array('class' => 'form-control','required'=>'required','step'=>'0.01', 'placeholder' => __('Enter Purchase Price'))) }}
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('expense_chartaccount_id', __('Expense Account'),['class'=>'form-label']) }}
            <select name="expense_chartaccount_id" class="form-control" required="required">
                @foreach ($expenseChartAccounts as $key => $chartAccount)
                    <option value="{{ $key }}" class="subAccount">{{ $chartAccount }}</option>
                    @foreach ($expenseSubAccounts as $subAccount)
                        @if ($key == $subAccount['account'])
                            <option value="{{ $subAccount['id'] }}" class="ms-5"> &nbsp; &nbsp;&nbsp; {{ $subAccount['code_name'] }}</option>
                        @endif
                    @endforeach
                @endforeach
            </select>
            <div class="text-xs mt-1">
                {{ __('Create account here.') }} <a href="{{ route('chart-of-account.index') }}"><b>{{ __('Create account') }}</b></a>
            </div>
        </div>

        <div class="form-group col-md-6">
            {{ Form::label('tax_id', __('Tax'),['class'=>'form-label']) }}
            {{ Form::select('tax_id[]', $tax,null, array('class' => 'form-control select2','id'=>'choices-multiple1','multiple')) }}
            <div class=" text-xs mt-1">
                {{__('Create tax here. ')}}<a href="{{route('taxes.index')}}"><b>{{__('Create tax')}}</b></a>
            </div>
        </div>
        <div class="form-group col-md-6">
            <div class="form-group">
                {{ Form::label('price_level_id', __('Discount Type'), ['class' => 'form-label']) }}
                <select name="price_level_id" class="form-control select">
                    <option value="">{{ __('Select Discount Type') }}</option>
                    @foreach($priceLevels as $level)
                        @php
                            $type = ucfirst($level->type); // Fixed or Percentage
                            $value = $level->type === 'percentage' ? $level->value . '%' : '$' . $level->value;
                        @endphp
                        <option value="{{ $level->id }}">{{ $level->name }} - {{ $type }} - {{ $value }}</option>
                    @endforeach
                </select>
            </div>
            <div class=" text-xs mt-1">
                {{__('Create discount type here. ')}}<a href="{{route('price-levels.index')}}"><b>{{__('Create discount type')}}</b></a>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('category_id', __('Category'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::select('category_id', $category,null, array('class' => 'form-control select','required'=>'required')) }}

            <div class=" text-xs mt-1">
                {{__('Create category here. ')}}<a href="{{route('product-category.index')}}"><b>{{__('Create Category')}}</b></a>
            </div>
        </div>
        <div class="form-group col-md-6">
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
                    <img id="image" class="mt-3" style="width:25%;"/>
                </label>
            </div>
        </div>



        <div class="col-md-6">
            <div class="form-group">
                <div class="btn-box">
                    <label class="d-block form-label">{{__('Type')}}</label>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadio5" name="type" value="product" checked="checked" >
                                <label class="custom-control-label form-label" for="customRadio5">{{__('Product')}}</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadio6" name="type" value="service" >
                                <label class="custom-control-label form-label" for="customRadio6">{{__('Service')}}</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadio7" name="type" value="kit" >
                                <label class="custom-control-label form-label" for="customRadio7">{{__('Kit')}}</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('print_zone', __('Print destination (Restaurant)'), ['class' => 'form-label']) }}
            {{ Form::select('print_zone', ['' => __('None'), 'kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'both' => __('Both')], '', ['class' => 'form-control select']) }}
            <small class="text-muted">{{ __('Where this product prints when sent to production (Kitchen/Bar slip).') }}</small>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('pos_printer_id', __('POS Printer'), ['class' => 'form-label']) }}
            <select name="pos_printer_id" class="form-control select">
                <option value="">{{ __('Default (by zone)') }}</option>
                @foreach($printers ?? [] as $pr)
                    <option value="{{ $pr->id }}">{{ $pr->branch ? $pr->branch->name : '' }} – {{ $pr->name }} ({{ $pr->type }})</option>
                @endforeach
            </select>
            <small class="text-muted">{{ __('Send this product to this printer in POS. Leave default to use zone.') }}</small>
        </div>

        <div class="form-group col-md-6 quantity">
            {{ Form::label('quantity', __('Quantity'),['class'=>'form-label']) }}<x-required></x-required>
            <!--{{ Form::text('quantity',null, array('class' => 'form-control', 'required'=>'required', 'placeholder' => __('Enter Quantity'))) }}-->
            {{ Form::text('quantity',null, array('class' => 'form-control', 'placeholder' => __('Enter Quantity'))) }}
        </div>
        <div class="form-group col-md-6 quantity">
            {{ Form::label('low_stock_threshold', __('Low stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('low_stock_threshold', null, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Default'))) }}
            <small class="text-muted">{{ __('Leave empty to use system default. Alert when quantity is below this.') }}</small>
        </div>
        <div class="form-group col-md-6 quantity">
            {{ Form::label('max_stock_threshold', __('Max stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('max_stock_threshold', null, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Optional'))) }}
            <small class="text-muted">{{ __('Alert when quantity is above this (optional).') }}</small>
        </div>
        <div class="form-group col-md-12 quantity">
            <div class="form-check">
                {{ Form::checkbox('does_not_affect_stock', '1', false, ['class' => 'form-check-input', 'id' => 'does_not_affect_stock_create']) }}
                {{ Form::label('does_not_affect_stock', __('Does not affect stock (e.g. composite dish – components are deducted separately)'), ['class' => 'form-check-label', 'for' => 'does_not_affect_stock_create']) }}
            </div>
            <small class="text-muted">{{ __('When checked: on POS sale this product’s own quantity is not used. (For kits, define components after creating the kit.)') }}</small>
        </div>

        <div class="col-md-12">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="identifier_tracking" id="identifier_tracking_create" value="1">
                <label class="form-check-label" for="identifier_tracking_create">{{ __('Track Serial Number / MAC Address') }}</label>
            </div>
            <div class="row mt-2" id="identifier_tracking_create_fields" style="display:none;">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Tracking type') }}</label>
                    <select name="identifier_type" class="form-control select">
                        <option value="serial">{{ __('Serial Number') }}</option>
                        <option value="mac">{{ __('MAC Address') }}</option>
                        <option value="both">{{ __('Serial + MAC') }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Warranty (days)') }}</label>
                    <input type="number" name="warranty_days" class="form-control" min="0" step="1" placeholder="{{ __('Optional') }}">
                </div>
            </div>
            <small class="text-muted d-block mt-1">{{ __('If enabled: stock-in and sales will require one unique identifier per unit (no duplicates).') }}</small>
        </div>

        <div class="col-md-12 mt-2">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Expiry alert (days before)') }}</label>
                    <input type="number" name="expiry_alert_days" class="form-control" min="0" step="1" placeholder="{{ __('Optional') }}">
                    <small class="text-muted">{{ __('Show dashboard alert when expiry is within this many days.') }}</small>
                </div>
            </div>
        </div>

        <div class="col-12 d-none" id="kit_components_create_section">
            <hr class="my-3">
            <h6 class="mb-2">{{ __('Kit Components') }}</h6>
            <p class="text-muted small mb-2">{{ __('Add components now. When you sell 1 kit, stock will be deducted from each component by the qty per kit.') }}</p>

            <div class="table-responsive mb-2">
                <table class="table table-sm" id="kit_components_create_table">
                    <thead>
                        <tr>
                            <th>{{ __('Component') }}</th>
                            <th class="text-end">{{ __('Qty per kit') }}</th>
                            <th width="60"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted"><td colspan="3">{{ __('No components added yet.') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Component product') }}</label>
                    <select class="form-select" id="kit_component_create_select">
                        <option value="">{{ __('Select component') }}</option>
                        @foreach(($kitComponents ?? []) as $cp)
                            <option value="{{ $cp->id }}">{{ $cp->name }}{{ $cp->sku ? ' (' . $cp->sku . ')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Quantity') }}</label>
                    <input type="number" class="form-control" id="kit_component_create_qty" step="0.0001" min="0.0001" value="1">
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-primary w-100" id="kit_component_create_add_btn">{{ __('Add Component') }}</button>
                </div>
            </div>
        </div>

        <div class="form-group col-md-12">
            {{ Form::label('description', __('Description'),['class'=>'form-label']) }}
            {!! Form::textarea('description', null, ['class'=>'form-control','rows'=>'2', 'placeholder' => __('Enter Description')]) !!}
        </div>

        @if(!$customFields->isEmpty())
                    @include('customFields.formBuilder')
        @endif
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
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
            $('#kit_components_create_section').removeClass('d-none');
        } else {
            $('#kit_components_create_section').addClass('d-none');
        }
    });

    (function () {
        let kitRowIdx = 0;
        function renderEmptyIfNeeded() {
            const $tbody = $('#kit_components_create_table tbody');
            if ($tbody.find('tr[data-kit-row]').length === 0) {
                $tbody.html('<tr class="text-muted"><td colspan="3">{{ __("No components added yet.") }}</td></tr>');
            }
        }
        $('#kit_component_create_add_btn').on('click', function () {
            const componentId = $('#kit_component_create_select').val();
            const componentText = $('#kit_component_create_select option:selected').text();
            const qty = parseFloat($('#kit_component_create_qty').val() || '0');
            if (!componentId || !qty || qty <= 0) return;

            const $tbody = $('#kit_components_create_table tbody');
            if ($tbody.find('tr[data-kit-row]').length === 0) {
                $tbody.empty();
            }

            const row = `
                <tr data-kit-row="${kitRowIdx}">
                    <td>
                        ${$('<div>').text(componentText).html()}
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

        $(document).on('click', '#kit_components_create_table .kit-component-remove', function () {
            $(this).closest('tr').remove();
            renderEmptyIfNeeded();
        });

        // If modal opens with Kit preselected (edge-case)
        const initialType = $('input[name="type"]:checked').val();
        if (initialType === 'kit') {
            $('#kit_components_create_section').removeClass('d-none');
        }
    })();

    $(document).on('change', '#identifier_tracking_create', function () {
        $('#identifier_tracking_create_fields').toggle(this.checked);
    });
</script>
