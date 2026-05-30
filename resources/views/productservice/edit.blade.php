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
            {{ Form::select('unit_type', $unitTypeOptions, $productService->unit_type, array('class' => 'form-control select','required'=>'required', 'id' => 'unit_type_edit')) }}
            <small class="text-muted">{{ __('Used on purchase and stock screens so quantities match how you sell.') }}</small>
        </div>
        <div class="col-12 quantity {{in_array($productService->type, ['service','kit']) ? 'd-none':''}}" id="product_measures_edit_section" style="display:none;">
            <div class="border rounded p-3 mb-2 bg-light">
                <label class="form-label fw-semibold">{{ __('Size of one unit (optional)') }}</label>
                <p class="small text-muted mb-2" id="product_measures_edit_help"></p>
                <div class="row g-2">
                    <div class="col-md-3 measure-field measure-l">
                        <label class="form-label">{{ __('Length') }} (cm)</label>
                        <input type="number" step="0.000001" min="0" class="form-control" id="unit_length_edit" name="unit_length" value="{{ $productService->unit_length ?? '' }}" placeholder="0">
                    </div>
                    <div class="col-md-3 measure-field measure-w">
                        <label class="form-label">{{ __('Width') }} (cm)</label>
                        <input type="number" step="0.000001" min="0" class="form-control" id="unit_width_edit" name="unit_width" value="{{ $productService->unit_width ?? '' }}" placeholder="0">
                    </div>
                    <div class="col-md-3 measure-field measure-h">
                        <label class="form-label">{{ __('Height') }} (cm)</label>
                        <input type="number" step="0.000001" min="0" class="form-control" id="unit_height_edit" name="unit_height" value="{{ $productService->unit_height ?? '' }}" placeholder="0">
                    </div>
                    <div class="col-md-3 measure-field measure-vol-div">
                        <label class="form-label">{{ __('Carrier divisor') }}</label>
                        <select class="form-control select" id="volumetric_divisor_edit" name="volumetric_divisor">
                            <option value="">{{ __('—') }}</option>
                            <option value="4000" {{ (int) ($productService->volumetric_divisor ?? 0) === 4000 ? 'selected' : '' }}>4000</option>
                            <option value="5000" {{ (int) ($productService->volumetric_divisor ?? 0) === 5000 ? 'selected' : '' }}>5000</option>
                            <option value="6000" {{ (int) ($productService->volumetric_divisor ?? 0) === 6000 ? 'selected' : '' }}>6000</option>
                        </select>
                    </div>
                </div>
                <input type="hidden" id="dimension_unit_edit" name="dimension_unit" value="{{ $productService->dimension_unit ?? 'cm' }}">
                <input type="hidden" name="unit_volume_cbm" id="unit_volume_cbm_edit" value="{{ $productService->unit_volume_cbm ?? '' }}">
                <input type="hidden" name="unit_area_sqm" id="unit_area_sqm_edit" value="{{ $productService->unit_area_sqm ?? '' }}">
                <input type="hidden" name="unit_volumetric_weight" id="unit_volumetric_weight_edit" value="{{ $productService->unit_volumetric_weight ?? '' }}">
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
                <div class="row g-2">
                    <div class="col-md-3 col-6">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadio5" name="type" value="product" @if($productService->type=='product') checked @endif >
                            <label class="custom-control-label form-label" for="customRadio5">{{__('Product')}}</label>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadio6" name="type" value="service" @if($productService->type=='service') checked @endif >
                            <label class="custom-control-label form-label" for="customRadio6">{{__('Service')}}</label>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadio7" name="type" value="kit" @if($productService->type=='kit') checked @endif >
                            <label class="custom-control-label form-label" for="customRadio7">{{__('Kit')}}</label>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-check form-check-inline">
                            <input type="radio" class="form-check-input type" id="customRadioIngredient" name="type" value="ingredient" @if($productService->type=='ingredient') checked @endif >
                            <label class="custom-control-label form-label" for="customRadioIngredient">{{ __('Ingredient') }}</label>
                        </div>
                    </div>
                </div>
                <small class="text-muted d-block mt-1">{{ __('Ingredients are for stock/recipes only and do not appear on POS.') }}</small>
            </div>
        </div>
        <div class="form-group col-md-6 pos-only-field">
            {{ Form::label('print_zone', __('Print destination (Restaurant)'), ['class' => 'form-label']) }}
            {{ Form::select('print_zone', ['' => __('None'), 'kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'both' => __('Both')], $productService->print_zone ?? '', ['class' => 'form-control select']) }}
            <small class="text-muted">{{ __('Where this product prints when sent to production (Kitchen/Bar slip).') }}</small>
        </div>
        <div class="form-group col-md-6 pos-only-field">
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
            {{ Form::label('low_stock_threshold', __('Low stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('low_stock_threshold', $productService->low_stock_threshold, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Default'))) }}
            <small class="text-muted">{{ __('Leave empty to use system default. Alert when quantity is below this.') }}</small>
        </div>
        <div class="form-group col-md-6 quantity {{in_array($productService->type, ['service','kit']) ? 'd-none':''}}">
            {{ Form::label('max_stock_threshold', __('Max stock alert at'),['class'=>'form-label']) }}
            {{ Form::number('max_stock_threshold', $productService->max_stock_threshold, array('class' => 'form-control', 'min' => 0, 'step' => 1, 'placeholder' => __('Optional'))) }}
            <small class="text-muted">{{ __('Alert when quantity is above this (optional).') }}</small>
        </div>
        <div class="form-group col-md-12 quantity product-stock-option {{in_array($productService->type, ['service','kit','ingredient']) ? 'd-none':''}}">
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

        <div class="col-12 quantity {{in_array($productService->type, ['service','kit','ingredient']) ? 'd-none':''}}" id="product_variants_edit_section">
            <hr class="my-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h6 class="mb-1">{{ __('Variants (e.g. color & size)') }}</h6>
                    <p class="text-muted small mb-0">{{ __('Stock and purchases use a quantity per variant. Quantities are not edited here.') }}</p>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="variants_enabled" id="variants_enabled_edit" value="1" {{ ($productService->variants_enabled ?? false) ? 'checked' : '' }}>
                    <label class="form-check-label" for="variants_enabled_edit">{{ __('This product has variants') }}</label>
                </div>
            </div>

            <div id="product_variants_edit_fields" class="mt-3" style="{{ ($productService->variants_enabled ?? false) ? '' : 'display:none;' }}">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Column 1 label (e.g. Size)') }}</label>
                        <input type="text" name="variant_size_label" class="form-control" maxlength="64" value="{{ $productService->variant_size_label ?? '' }}" placeholder="{{ __('Size') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Column 2 label (e.g. Color)') }}</label>
                        <input type="text" name="variant_color_label" class="form-control" maxlength="64" value="{{ $productService->variant_color_label ?? '' }}" placeholder="{{ __('Color') }}">
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle" id="product_variants_edit_table">
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
                            @php $vIdx = 0; @endphp
                            @forelse(($productService->variants ?? []) as $v)
                                <tr data-variant-row="{{ $vIdx }}">
                                    <td>
                                        <input type="hidden" name="variant_rows[{{ $vIdx }}][id]" value="{{ $v->id }}">
                                        <input type="text" name="variant_rows[{{ $vIdx }}][size]" class="form-control form-control-sm" maxlength="64" value="{{ $v->size }}">
                                    </td>
                                    <td><input type="text" name="variant_rows[{{ $vIdx }}][color]" class="form-control form-control-sm" maxlength="64" value="{{ $v->color }}"></td>
                                    <td><input type="text" name="variant_rows[{{ $vIdx }}][sku]" class="form-control form-control-sm" maxlength="191" value="{{ $v->sku }}"></td>
                                    <td><input type="number" step="0.01" min="0" name="variant_rows[{{ $vIdx }}][sale_price]" class="form-control form-control-sm text-end" value="{{ $v->sale_price }}"></td>
                                    <td><input type="number" step="0.01" min="0" name="variant_rows[{{ $vIdx }}][purchase_price]" class="form-control form-control-sm text-end" value="{{ $v->purchase_price }}"></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger product-variant-remove"><i class="ti ti-trash"></i></button></td>
                                </tr>
                                @php $vIdx++; @endphp
                            @empty
                                <tr class="text-muted" data-empty-row><td colspan="6">{{ __('No variants added yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" id="product_variants_edit_add_btn"><i class="ti ti-plus"></i> {{ __('Add variant') }}</button>
                </div>
            </div>
        </div>

        <div class="col-12 {{ (($productService->type ?? '') === 'product') ? '' : 'd-none' }}" id="product_options_edit_section">
            <hr class="my-3">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h6 class="mb-1">{{ __('Product options') }}</h6>
                    <p class="text-muted small mb-0">{{ __('Linked products selectable at sale time. Price = parent + selected options; stock from options only.') }}</p>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="options_enabled" id="options_enabled_edit" value="1" {{ ($productService->options_enabled ?? false) ? 'checked' : '' }}>
                    <label class="form-check-label" for="options_enabled_edit">{{ __('This product has options') }}</label>
                </div>
            </div>
            <div id="product_options_edit_fields" class="mt-3" style="{{ ($productService->options_enabled ?? false) ? '' : 'display:none;' }}">
                <div class="table-responsive mb-2">
                    <table class="table table-sm" id="product_options_edit_table">
                        <thead>
                            <tr>
                                <th>{{ __('Option product') }}</th>
                                <th width="60"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($optionItems ?? []) as $oi)
                                @if($oi->optionProduct)
                                <tr data-option-row>
                                    <td>
                                        {{ $oi->optionProduct->name }}{{ $oi->optionProduct->sku ? ' (' . $oi->optionProduct->sku . ')' : '' }}
                                        <input type="hidden" name="product_options[{{ $loop->index }}][option_product_id]" value="{{ $oi->option_product_id }}">
                                    </td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger product-option-remove"><i class="ti ti-trash"></i></button></td>
                                </tr>
                                @endif
                            @empty
                                <tr class="text-muted" data-empty-row><td colspan="2">{{ __('No options added yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">{{ __('Product') }}</label>
                        <select class="form-select" id="product_option_edit_select">
                            <option value="">{{ __('Select product') }}</option>
                            @foreach(($optionProducts ?? []) as $op)
                                <option value="{{ $op->id }}">{{ $op->name }}{{ $op->sku ? ' (' . $op->sku . ')' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-primary w-100" id="product_option_edit_add_btn"><i class="ti ti-plus"></i> {{ __('Add option') }}</button>
                    </div>
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

        @include('productservice.partials.product-recipes', ['mode' => 'edit', 'recipeItems' => $recipeItems ?? collect(), 'ingredientProducts' => $ingredientProducts ?? collect()])

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

    function syncProductTypeFieldsEdit() {
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
            $('#kit_components_edit_section').removeClass('d-none');
        } else {
            $('#kit_components_edit_section').addClass('d-none');
        }

        if (isProduct) {
            $('#product_recipes_edit_section').removeClass('d-none');
            $('#product_options_edit_section').removeClass('d-none');
            $('#product_variants_edit_section').removeClass('d-none');
        } else {
            $('#product_recipes_edit_section').addClass('d-none');
            $('#product_options_edit_section').addClass('d-none');
            if (!isIngredient) {
                $('#product_variants_edit_section').addClass('d-none');
            }
        }

        if (isIngredient) {
            $('#product_variants_edit_section').addClass('d-none');
            $('.pos-only-field').addClass('d-none');
            $('.product-stock-option').addClass('d-none');
            $('input[name="sale_price"]').prop('readonly', true);
        } else {
            $('.pos-only-field').removeClass('d-none');
            $('.product-stock-option').toggle(isProduct);
            $('input[name="sale_price"]').prop('readonly', false);
        }
    }

    $(document).on('click', '.type', syncProductTypeFieldsEdit);
    $(document).on('change', 'input[name="type"]', syncProductTypeFieldsEdit);

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

    (function () {
        let recipeRowIdx = 0;
        $('#recipe_ingredient_edit_add_btn').on('click', function () {
            const ingredientId = $('#recipe_ingredient_edit_select').val();
            const ingredientText = $('#recipe_ingredient_edit_select option:selected').text();
            const qty = parseFloat($('#recipe_ingredient_edit_qty').val() || '0');
            if (!ingredientId || !qty || qty <= 0) return;

            const $tbody = $('#product_recipes_edit_table tbody');
            if ($tbody.find('tr').length === 1 && $tbody.find('tr').first().hasClass('text-muted')) {
                $tbody.empty();
            }

            const safeText = $('<div>').text(ingredientText).html();
            const row = `
                <tr data-new-recipe-row="${recipeRowIdx}">
                    <td>
                        ${safeText}
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
        $(document).on('click', '#product_recipes_edit_table .recipe-row-remove', function () {
            $(this).closest('tr').remove();
        });
    })();

    syncProductTypeFieldsEdit();

    $(document).on('change', '#identifier_tracking_edit', function () {
        $('#identifier_tracking_edit_fields').toggle(this.checked);
    });

    function calcMeasuresByUnitTypeEdit() {
        var ut = ($('#unit_type_edit').val() || '').toString();
        var $sec = $('#product_measures_edit_section');
        var $help = $('#product_measures_edit_help');
        $sec.hide();
        $('.measure-field').hide();

        if (ut === 'cbm' || ut === 'sm' || ut === 'volume') {
            $sec.show();
        } else {
            if (!$('#unit_length_edit').val() && !$('#unit_width_edit').val() && !$('#unit_height_edit').val()) {
                $('#unit_volume_cbm_edit').val('');
                $('#unit_area_sqm_edit').val('');
                $('#unit_volumetric_weight_edit').val('');
            }
            return;
        }

        if (ut === 'sm') {
            $('.measure-l,.measure-w').show();
            $help.text('{{ __("Optional: length × width in cm → stored as square meters per unit.") }}');
        } else if (ut === 'cbm') {
            $('.measure-l,.measure-w,.measure-h').show();
            $help.text('{{ __("Optional: L × W × H in cm → stored as cubic meters per unit.") }}');
        } else if (ut === 'volume') {
            $('.measure-l,.measure-w,.measure-h').show();
            $('.measure-vol-div').show();
            $help.text('{{ __("L × W × H in cm and carrier divisor → volumetric weight (optional).") }}');
        }

        var L = parseFloat($('#unit_length_edit').val() || '0') || 0;
        var W = parseFloat($('#unit_width_edit').val() || '0') || 0;
        var H = parseFloat($('#unit_height_edit').val() || '0') || 0;
        var divisor = parseFloat($('#volumetric_divisor_edit').val() || '0') || 0;
        var factorToM = 0.01;
        var Lm = L * factorToM, Wm = W * factorToM, Hm = H * factorToM;
        if (ut === 'sm' && Lm > 0 && Wm > 0) {
            $('#unit_area_sqm_edit').val((Lm * Wm).toFixed(6));
        }
        if (ut === 'cbm' && Lm > 0 && Wm > 0 && Hm > 0) {
            $('#unit_volume_cbm_edit').val((Lm * Wm * Hm).toFixed(6));
        }
        if (ut === 'volume' && divisor > 0 && L > 0 && W > 0 && H > 0) {
            if (Lm > 0 && Wm > 0 && Hm > 0) {
                $('#unit_volume_cbm_edit').val((Lm * Wm * Hm).toFixed(6));
            }
            $('#unit_volumetric_weight_edit').val(((L * W * H) / divisor).toFixed(6));
        }
    }
    $(document).on('change', '#unit_type_edit', calcMeasuresByUnitTypeEdit);
    $(document).on('keyup change', '#unit_length_edit,#unit_width_edit,#unit_height_edit,#volumetric_divisor_edit', calcMeasuresByUnitTypeEdit);
    setTimeout(calcMeasuresByUnitTypeEdit, 0);

    (function () {
        let vIdx = (function () {
            const $rows = $('#product_variants_edit_table tbody tr[data-variant-row]');
            return $rows.length ? ($rows.length) : 0;
        })();
        function renderVariantEmptyRow() {
            const $tbody = $('#product_variants_edit_table tbody');
            if ($tbody.find('tr[data-variant-row]').length === 0) {
                $tbody.html('<tr class="text-muted" data-empty-row><td colspan="6">{{ __("No variants added yet.") }}</td></tr>');
            }
        }
        function addVariantRow() {
            const $tbody = $('#product_variants_edit_table tbody');
            if ($tbody.find('tr[data-empty-row]').length) $tbody.empty();
            const row = `
                <tr data-variant-row="${vIdx}">
                    <td><input type="text" name="variant_rows[${vIdx}][size]" class="form-control form-control-sm" maxlength="64"></td>
                    <td><input type="text" name="variant_rows[${vIdx}][color]" class="form-control form-control-sm" maxlength="64"></td>
                    <td><input type="text" name="variant_rows[${vIdx}][sku]" class="form-control form-control-sm" maxlength="191"></td>
                    <td><input type="number" step="0.01" min="0" name="variant_rows[${vIdx}][sale_price]" class="form-control form-control-sm text-end"></td>
                    <td><input type="number" step="0.01" min="0" name="variant_rows[${vIdx}][purchase_price]" class="form-control form-control-sm text-end"></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger product-variant-remove"><i class="ti ti-trash"></i></button></td>
                </tr>`;
            $tbody.append(row);
            vIdx++;
        }
        function syncVariantVisibility() {
            const type = $('input[name="type"]:checked').val();
            const show = (type === 'product');
            $('#product_variants_edit_section').toggle(show);
            const enabled = $('#variants_enabled_edit').is(':checked');
            $('#product_variants_edit_fields').toggle(show && enabled);
        }
        $(document).on('change', '#variants_enabled_edit', function () {
            syncVariantVisibility();
        });
        $(document).on('click', '#product_variants_edit_add_btn', function () {
            addVariantRow();
        });
        $(document).on('click', '#product_variants_edit_table .product-variant-remove', function () {
            $(this).closest('tr').remove();
            renderVariantEmptyRow();
        });
        $(document).on('click', '.type', function () {
            syncVariantVisibility();
        });
        syncVariantVisibility();
    })();

    (function () {
        let optIdx = {{ max(count($optionItems ?? []), 0) }};
        function renderOptEmpty() {
            const $tbody = $('#product_options_edit_table tbody');
            if ($tbody.find('tr[data-option-row]').length === 0) {
                $tbody.html('<tr class="text-muted" data-empty-row><td colspan="2">{{ __("No options added yet.") }}</td></tr>');
            }
        }
        function syncOptVisibility() {
            const type = $('input[name="type"]:checked').val();
            $('#product_options_edit_section').toggleClass('d-none', type !== 'product');
            const enabled = $('#options_enabled_edit').is(':checked');
            $('#product_options_edit_fields').toggle(type === 'product' && enabled);
        }
        $(document).on('change', '#options_enabled_edit', syncOptVisibility);
        $(document).on('click', '.type', syncOptVisibility);
        $('#product_option_edit_add_btn').on('click', function () {
            const id = $('#product_option_edit_select').val();
            const text = $('#product_option_edit_select option:selected').text();
            if (!id) return;
            const $tbody = $('#product_options_edit_table tbody');
            if ($tbody.find('tr[data-empty-row]').length) $tbody.empty();
            if ($tbody.find('input[value="' + id + '"]').length) return;
            $tbody.append('<tr data-option-row><td>' + $('<div>').text(text).html() +
                '<input type="hidden" name="product_options[' + optIdx + '][option_product_id]" value="' + id + '"></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger product-option-remove"><i class="ti ti-trash"></i></button></td></tr>');
            optIdx++;
        });
        $(document).on('click', '#product_options_edit_table .product-option-remove', function () {
            $(this).closest('tr').remove();
            renderOptEmpty();
        });
        syncOptVisibility();
    })();
</script>

