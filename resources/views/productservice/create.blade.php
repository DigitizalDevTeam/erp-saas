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
                <div class="input-group">
                    {{ Form::text('sku', '', array('class' => 'form-control product-sku-input','required'=>'required', 'placeholder' => __('Enter Sku'), 'autocomplete' => 'off')) }}
                    <button type="button" class="btn btn-outline-primary product-sku-generate-btn" title="{{ __('Auto generate SKU') }}">
                        <i class="ti ti-refresh"></i> {{ __('Auto generate') }}
                    </button>
                </div>
                <small class="text-muted">{{ __('SKU must be unique. Enter manually or click Auto generate.') }}</small>
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
                {{ Form::text('sale_price', '', array('class' => 'form-control money-amount-input','required'=>'required', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => __('Enter Sale Price'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('purchase_price', __('Purchase Price'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::text('purchase_price', '', array('class' => 'form-control money-amount-input','required'=>'required', 'inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => __('Enter Purchase Price'))) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('sale_price_type', __('Price includes VAT?'),['class'=>'form-label']) }}
                <select name="sale_price_type" class="form-control select">
                    <option value="exclusive">{{ __('Exclusive VAT') }} ({{ __('VAT will be added on top') }})</option>
                    <option value="inclusive">{{ __('Inclusive VAT') }} ({{ __('VAT is included in price') }})</option>
                </select>
                <div class="text-xs text-muted mt-1">
                    {{ __('Applies to Sale Price and Purchase Price on Invoice, Bill, Purchase and POS.') }}
                </div>
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
            {{ Form::label('unit_id', __('Unit'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::select('unit_id', $unit,null, array('class' => 'form-control select','required'=>'required')) }}
            <div class=" text-xs mt-1">
                {{__('Create unit here. ')}}<a href="{{route('product-unit.index')}}"><b>{{__('Create unit')}}</b></a>
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('unit_type', __('How stock is counted'),['class'=>'form-label']) }}<x-required></x-required>
            @php
                $unitTypeOptions = [
                    'kg' => __('Kilogram (kg)'),
                    'unit' => __('Piece / unit'),
                    'cbm' => __('Cubic meter (CBM)'),
                    'sm' => __('Square meter (SQM)'),
                    'volume' => __('Volumetric weight'),
                ];
            @endphp
            {{ Form::select('unit_type', $unitTypeOptions, null, array('class' => 'form-control select','required'=>'required', 'id' => 'unit_type_create')) }}
            <small class="text-muted">{{ __('Used on purchase and stock screens so quantities match how you sell (weight, pieces, area, etc.).') }}</small>
        </div>
        <div class="col-12 quantity" id="product_measures_create_section" style="display:none;">
            <div class="border rounded p-3 mb-2 bg-light">
                <label class="form-label fw-semibold">{{ __('Size of one unit (optional)') }}</label>
                <p class="small text-muted mb-2" id="product_measures_create_help"></p>
                <div class="row g-2">
                    <div class="col-md-3 measure-field measure-l">
                        <label class="form-label">{{ __('Length') }} (cm)</label>
                        <input type="number" step="0.000001" min="0" class="form-control" id="unit_length_create" name="unit_length" placeholder="0">
                    </div>
                    <div class="col-md-3 measure-field measure-w">
                        <label class="form-label">{{ __('Width') }} (cm)</label>
                        <input type="number" step="0.000001" min="0" class="form-control" id="unit_width_create" name="unit_width" placeholder="0">
                    </div>
                    <div class="col-md-3 measure-field measure-h">
                        <label class="form-label">{{ __('Height') }} (cm)</label>
                        <input type="number" step="0.000001" min="0" class="form-control" id="unit_height_create" name="unit_height" placeholder="0">
                    </div>
                    <div class="col-md-3 measure-field measure-vol-div">
                        <label class="form-label">{{ __('Carrier divisor') }}</label>
                        <select class="form-control select" id="volumetric_divisor_create" name="volumetric_divisor">
                            <option value="">{{ __('—') }}</option>
                            <option value="4000">4000</option>
                            <option value="5000">5000</option>
                            <option value="6000">6000</option>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="dimension_unit" id="dimension_unit_create" value="cm">
                <input type="hidden" name="unit_volume_cbm" id="unit_volume_cbm_create" value="">
                <input type="hidden" name="unit_area_sqm" id="unit_area_sqm_create" value="">
                <input type="hidden" name="unit_volumetric_weight" id="unit_volumetric_weight_create" value="">
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
                    <div class="row g-2">
                        <div class="col-md-3 col-6">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadio5" name="type" value="product" checked="checked" >
                                <label class="custom-control-label form-label" for="customRadio5">{{__('Product')}}</label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadio6" name="type" value="service" >
                                <label class="custom-control-label form-label" for="customRadio6">{{__('Service')}}</label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadio7" name="type" value="kit" >
                                <label class="custom-control-label form-label" for="customRadio7">{{__('Kit')}}</label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-check-inline">
                                <input type="radio" class="form-check-input type" id="customRadioIngredient" name="type" value="ingredient" >
                                <label class="custom-control-label form-label" for="customRadioIngredient">{{ __('Ingredient') }}</label>
                            </div>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-1">{{ __('Ingredients are for stock/recipes only and do not appear on POS.') }}</small>
                </div>
            </div>
        </div>
        <div class="form-group col-md-6 pos-only-field">
            {{ Form::label('print_zone', __('Print destination (Restaurant)'), ['class' => 'form-label']) }}
            {{ Form::select('print_zone', ['' => __('None'), 'kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'both' => __('Both')], '', ['class' => 'form-control select']) }}
            <small class="text-muted">{{ __('Where this product prints when sent to production (Kitchen/Bar slip). Leave empty to use the category default.') }}</small>
        </div>
        <div class="form-group col-md-6 pos-only-field">
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
            {{ Form::label('low_stock_threshold', __('Low stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('low_stock_threshold', null, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Default'))) }}
            <small class="text-muted">{{ __('Leave empty to use system default. Alert when quantity is below this.') }}</small>
        </div>
        <div class="form-group col-md-6 quantity">
            {{ Form::label('max_stock_threshold', __('Max stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('max_stock_threshold', null, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Optional'))) }}
            <small class="text-muted">{{ __('Alert when quantity is above this (optional).') }}</small>
        </div>
        <div class="form-group col-md-12 quantity product-stock-option">
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

        <div class="col-md-12 mt-2 product-batch-section">
            <hr class="my-2">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="batch_tracking" id="batch_tracking_create" value="1" {{ old('batch_tracking') ? 'checked' : '' }}>
                <label class="form-check-label" for="batch_tracking_create">{{ __('Enable batch / lot tracking') }}</label>
            </div>
            <div id="batch_consumption_create_fields" class="row g-2" style="{{ old('batch_tracking') ? '' : 'display:none;' }}">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Consumption method') }}</label>
                    <select name="batch_consumption_method" class="form-control select">
                        @foreach(\App\Services\BatchLotDeductionService::methodLabels() as $value => $label)
                            <option value="{{ $value }}" @selected(old('batch_consumption_method', 'fefo') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted d-block mt-1">{{ __('Controls which batch is deducted on POS, invoice, and hotel consumption.') }}</small>
                </div>
            </div>
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

        <div class="col-12 quantity" id="product_variants_create_section" style="display:none;">
            <hr class="my-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h6 class="mb-1">{{ __('Variants (e.g. color & size)') }}</h6>
                    <p class="text-muted small mb-0">{{ __('Stock and purchases use a quantity per variant (e.g. RED + LG). Quantities are not set here.') }}</p>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="variants_enabled" id="variants_enabled_create" value="1">
                    <label class="form-check-label" for="variants_enabled_create">{{ __('This product has variants') }}</label>
                </div>
            </div>

            <div id="product_variants_create_fields" class="mt-3" style="display:none;">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Column 1 label (e.g. Size)') }}</label>
                        <input type="text" name="variant_size_label" class="form-control" maxlength="64" placeholder="{{ __('Size') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Column 2 label (e.g. Color)') }}</label>
                        <input type="text" name="variant_color_label" class="form-control" maxlength="64" placeholder="{{ __('Color') }}">
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle" id="product_variants_create_table">
                        <thead>
                            <tr>
                                <th>{{ __('Size') }}</th>
                                <th>{{ __('Color') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th class="text-end">{{ __('Sale price') }}</th>
                                <th class="text-end">{{ __('Purchase price') }}</th>
                                <th width="60"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-muted" data-empty-row><td colspan="6">{{ __('No variants added yet.') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" id="product_variants_create_add_btn"><i class="ti ti-plus"></i> {{ __('Add variant') }}</button>
                </div>
            </div>
        </div>

        <div class="col-12 quantity" id="product_options_create_section" style="display:none;">
            <hr class="my-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h6 class="mb-1">{{ __('Product options') }}</h6>
                    <p class="text-muted small mb-0">{{ __('At sale time the user picks linked products. Use a group name (e.g. Doneness, Ice) for single-choice modifiers; leave group empty for optional add-ons.') }}</p>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="options_enabled" id="options_enabled_create" value="1">
                    <label class="form-check-label" for="options_enabled_create">{{ __('This product has options') }}</label>
                </div>
            </div>
            <div id="product_options_create_fields" class="mt-3" style="display:none;">
                <div class="table-responsive mb-2">
                    <table class="table table-sm" id="product_options_create_table">
                        <thead>
                            <tr>
                                <th>{{ __('Option product') }}</th>
                                <th>{{ __('Group') }}</th>
                                <th>{{ __('Required') }}</th>
                                <th width="60"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-muted"><td colspan="4">{{ __('No options added yet.') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">{{ __('Product') }}</label>
                        <select class="form-select" id="product_option_create_select">
                            <option value="">{{ __('Select product') }}</option>
                            @foreach(($optionProducts ?? []) as $op)
                                <option value="{{ $op->id }}">{{ $op->name }}{{ $op->sku ? ' (' . $op->sku . ')' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Group') }}</label>
                        <input type="text" class="form-control" id="product_option_create_group" placeholder="{{ __('e.g. Doneness, Ice') }}">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label d-block">&nbsp;</label>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="product_option_create_required" value="1">
                            <label class="form-check-label small" for="product_option_create_required">{{ __('Required') }}</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label d-block">&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-primary w-100" id="product_option_create_add_btn"><i class="ti ti-plus"></i> {{ __('Add option') }}</button>
                    </div>
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

        @include('productservice.partials.product-recipes', ['mode' => 'create', 'recipeItems' => $recipeItems ?? collect(), 'ingredientProducts' => $ingredientProducts ?? collect()])

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

    function syncProductTypeFieldsCreate() {
        var type = $('input[name="type"]:checked').val();
        var isProduct = type === 'product';
        var isIngredient = type === 'ingredient';
        var isKit = type === 'kit';
        var showStockExtras = isProduct || isIngredient;

        if (showStockExtras) {
            $('.quantity').removeClass('d-none').addClass('d-block');
        } else {
            $('.quantity').addClass('d-none').removeClass('d-block');
        }

        if (isKit) {
            $('#kit_components_create_section').removeClass('d-none');
        } else {
            $('#kit_components_create_section').addClass('d-none');
        }

        if (isProduct) {
            $('#product_recipes_create_section').removeClass('d-none');
            $('#product_options_create_section').show();
            $('#product_variants_create_section').show();
        } else {
            $('#product_recipes_create_section').addClass('d-none');
            $('#product_options_create_section').hide();
            $('#options_enabled_create').prop('checked', false);
            $('#product_options_create_fields').hide();
            if (!isIngredient) {
                $('#product_variants_create_section').hide();
            }
        }

        $('.product-batch-section').toggle(isProduct || isIngredient);

        if (isIngredient) {
            $('#product_variants_create_section').hide();
            $('.pos-only-field').addClass('d-none');
            $('.product-stock-option').addClass('d-none');
            if (!$('input[name="sale_price"]').data('ingredient-prev')) {
                $('input[name="sale_price"]').data('ingredient-prev', $('input[name="sale_price"]').val());
            }
            $('input[name="sale_price"]').val('0').prop('readonly', true);
        } else {
            $('.pos-only-field').removeClass('d-none');
            $('.product-stock-option').toggle(isProduct);
            var prev = $('input[name="sale_price"]').data('ingredient-prev');
            $('input[name="sale_price"]').prop('readonly', false);
            if (prev !== undefined && prev !== '') {
                $('input[name="sale_price"]').val(prev);
            }
        }
    }

    $(document).on('click', '.type', syncProductTypeFieldsCreate);
    $(document).on('change', 'input[name="type"]', syncProductTypeFieldsCreate);

    (function () {
        let optIdx = 0;
        function renderOptEmpty() {
            const $tbody = $('#product_options_create_table tbody');
            if ($tbody.find('tr[data-option-row]').length === 0) {
                $tbody.html('<tr class="text-muted" data-empty-row><td colspan="4">{{ __("No options added yet.") }}</td></tr>');
            }
        }
        function syncOptFields() {
            const type = $('input[name="type"]:checked').val();
            const show = type === 'product' && $('#options_enabled_create').is(':checked');
            $('#product_options_create_fields').toggle(show);
        }
        $('#options_enabled_create').on('change', syncOptFields);
        $(document).on('change', 'input[name="type"]', syncOptFields);
        $('#product_option_create_add_btn').on('click', function () {
            const id = $('#product_option_create_select').val();
            const text = $('#product_option_create_select option:selected').text();
            const group = ($('#product_option_create_group').val() || '').trim();
            const required = $('#product_option_create_required').is(':checked') ? '1' : '0';
            if (!id) return;
            const $tbody = $('#product_options_create_table tbody');
            if ($tbody.find('tr[data-empty-row]').length) $tbody.empty();
            if ($tbody.find('input[value="' + id + '"]').length) return;
            $tbody.append('<tr data-option-row><td>' + $('<div>').text(text).html() +
                '<input type="hidden" name="product_options[' + optIdx + '][option_product_id]" value="' + id + '"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="product_options[' + optIdx + '][option_group]" value="' + $('<div>').text(group).html() + '" placeholder="{{ __("Group") }}"></td>' +
                '<td><input type="hidden" name="product_options[' + optIdx + '][is_required]" value="0">' +
                '<input type="checkbox" class="form-check-input" name="product_options[' + optIdx + '][is_required]" value="1"' + (required === '1' ? ' checked' : '') + '></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger product-option-remove"><i class="ti ti-trash"></i></button></td></tr>');
            optIdx++;
            $('#product_option_create_group').val('');
            $('#product_option_create_required').prop('checked', false);
        });
        $(document).on('click', '.product-option-remove', function () {
            $(this).closest('tr').remove();
            renderOptEmpty();
        });
        syncOptFields();
    })();

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

    })();

    (function () {
        let recipeRowIdx = 0;
        function renderRecipeEmptyIfNeeded() {
            const $tbody = $('#product_recipes_create_table tbody');
            if ($tbody.find('tr[data-recipe-row]').length === 0 && $tbody.find('tr[data-existing-recipe-row]').length === 0) {
                $tbody.html('<tr class="text-muted recipe-empty-row"><td colspan="3">{{ __("No ingredients added yet.") }}</td></tr>');
            }
        }
        $('#recipe_ingredient_create_add_btn').on('click', function () {
            const ingredientId = $('#recipe_ingredient_create_select').val();
            const ingredientText = $('#recipe_ingredient_create_select option:selected').text();
            const qty = parseFloat($('#recipe_ingredient_create_qty').val() || '0');
            if (!ingredientId || !qty || qty <= 0) return;

            const $tbody = $('#product_recipes_create_table tbody');
            $tbody.find('.recipe-empty-row').remove();

            const row = `
                <tr data-recipe-row="${recipeRowIdx}">
                    <td>
                        ${$('<div>').text(ingredientText).html()}
                        <input type="hidden" name="product_recipes[${recipeRowIdx}][ingredient_product_id]" value="${ingredientId}">
                    </td>
                    <td class="text-end">
                        ${qty}
                        <input type="hidden" name="product_recipes[${recipeRowIdx}][quantity]" value="${qty}">
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger recipe-row-remove"><i class="ti ti-trash"></i></button>
                    </td>
                </tr>`;
            $tbody.append(row);
            recipeRowIdx++;
        });
        $(document).on('click', '#product_recipes_create_table .recipe-row-remove', function () {
            $(this).closest('tr').remove();
            renderRecipeEmptyIfNeeded();
        });
    })();

    syncProductTypeFieldsCreate();

    $(document).on('change', '#identifier_tracking_create', function () {
        $('#identifier_tracking_create_fields').toggle(this.checked);
    });

    function syncBatchFieldsCreate() {
        var on = $('#batch_tracking_create').is(':checked');
        $('#batch_consumption_create_fields').toggle(on);
    }
    $('#batch_tracking_create').on('change', syncBatchFieldsCreate);
    syncBatchFieldsCreate();

    function calcMeasuresByUnitTypeCreate() {
        var ut = ($('#unit_type_create').val() || '').toString();
        var $sec = $('#product_measures_create_section');
        var $help = $('#product_measures_create_help');
        $sec.hide();
        $('.measure-field').hide();
        $('#unit_volume_cbm_create').val('');
        $('#unit_area_sqm_create').val('');
        $('#unit_volumetric_weight_create').val('');

        if (ut === 'cbm' || ut === 'sm' || ut === 'volume') {
            $sec.show();
        } else {
            $('#unit_length_create,#unit_width_create,#unit_height_create').val('');
            $('#volumetric_divisor_create').val('');
            return;
        }

        if (ut === 'sm') {
            $('.measure-l,.measure-w').show();
            $help.text('{{ __("Optional: length × width in cm → stored as square meters per unit.") }}');
            $('#unit_height_create').val('');
            $('#volumetric_divisor_create').val('');
        } else if (ut === 'cbm') {
            $('.measure-l,.measure-w,.measure-h').show();
            $help.text('{{ __("Optional: L × W × H in cm → stored as cubic meters per unit.") }}');
            $('#volumetric_divisor_create').val('');
        } else if (ut === 'volume') {
            $('.measure-l,.measure-w,.measure-h').show();
            $('.measure-vol-div').show();
            $help.text('{{ __("L × W × H in cm and carrier divisor → volumetric weight (optional).") }}');
        }

        var L = parseFloat($('#unit_length_create').val() || '0') || 0;
        var W = parseFloat($('#unit_width_create').val() || '0') || 0;
        var H = parseFloat($('#unit_height_create').val() || '0') || 0;
        var divisor = parseFloat($('#volumetric_divisor_create').val() || '0') || 0;
        var factorToM = 0.01;
        var Lm = L * factorToM, Wm = W * factorToM, Hm = H * factorToM;
        if (ut === 'sm' && Lm > 0 && Wm > 0) {
            $('#unit_area_sqm_create').val((Lm * Wm).toFixed(6));
        }
        if (ut === 'cbm' && Lm > 0 && Wm > 0 && Hm > 0) {
            $('#unit_volume_cbm_create').val((Lm * Wm * Hm).toFixed(6));
        }
        if (ut === 'volume' && divisor > 0 && L > 0 && W > 0 && H > 0) {
            if (Lm > 0 && Wm > 0 && Hm > 0) {
                $('#unit_volume_cbm_create').val((Lm * Wm * Hm).toFixed(6));
            }
            $('#unit_volumetric_weight_create').val(((L * W * H) / divisor).toFixed(6));
        }
    }
    $(document).on('change', '#unit_type_create', calcMeasuresByUnitTypeCreate);
    $(document).on('keyup change', '#unit_length_create,#unit_width_create,#unit_height_create,#volumetric_divisor_create', calcMeasuresByUnitTypeCreate);
    setTimeout(calcMeasuresByUnitTypeCreate, 0);

    (function () {
        let vIdx = 0;
        function renderVariantEmptyRow() {
            const $tbody = $('#product_variants_create_table tbody');
            if ($tbody.find('tr[data-variant-row]').length === 0) {
                $tbody.html('<tr class="text-muted" data-empty-row><td colspan="6">{{ __("No variants added yet.") }}</td></tr>');
            }
        }
        function addVariantRow(data) {
            const $tbody = $('#product_variants_create_table tbody');
            if ($tbody.find('tr[data-empty-row]').length) $tbody.empty();
            const size = (data && data.size) ? data.size : '';
            const color = (data && data.color) ? data.color : '';
            const sku = (data && data.sku) ? data.sku : '';
            const salePrice = (data && data.sale_price != null) ? data.sale_price : '';
            const purchasePrice = (data && data.purchase_price != null) ? data.purchase_price : '';
            const row = `
                <tr data-variant-row="${vIdx}">
                    <td><input type="text" name="variant_rows[${vIdx}][size]" class="form-control form-control-sm" maxlength="64" value="${$('<div>').text(size).html()}"></td>
                    <td><input type="text" name="variant_rows[${vIdx}][color]" class="form-control form-control-sm" maxlength="64" value="${$('<div>').text(color).html()}"></td>
                    <td><input type="text" name="variant_rows[${vIdx}][sku]" class="form-control form-control-sm" maxlength="191" value="${$('<div>').text(sku).html()}"></td>
                    <td><input type="text" inputmode="decimal" autocomplete="off" name="variant_rows[${vIdx}][sale_price]" class="form-control form-control-sm text-end money-amount-input" value="${salePrice}"></td>
                    <td><input type="text" inputmode="decimal" autocomplete="off" name="variant_rows[${vIdx}][purchase_price]" class="form-control form-control-sm text-end money-amount-input" value="${purchasePrice}"></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger product-variant-remove"><i class="ti ti-trash"></i></button></td>
                </tr>`;
            $tbody.append(row);
            vIdx++;
        }

        function syncVariantVisibility() {
            const type = $('input[name="type"]:checked').val();
            const show = (type === 'product');
            $('#product_variants_create_section').toggle(show);
            const enabled = $('#variants_enabled_create').is(':checked');
            $('#product_variants_create_fields').toggle(show && enabled);
        }

        $(document).on('change', '#variants_enabled_create', function () {
            syncVariantVisibility();
        });
        $(document).on('click', '#product_variants_create_add_btn', function () {
            addVariantRow({});
        });
        $(document).on('click', '#product_variants_create_table .product-variant-remove', function () {
            $(this).closest('tr').remove();
            renderVariantEmptyRow();
        });
        $(document).on('click', '.type', function () {
            syncVariantVisibility();
        });
        syncVariantVisibility();
    })();
</script>
