@extends('layouts.admin')
@section('page-title')
    {{__('Quotation Create')}}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item"><a href="{{route('quotation.index')}}">{{__('Quotation')}}</a></li>
    <li class="breadcrumb-item">{{__('Quotation Create')}}</li>
@endsection
@push('script-page')
    <script src="{{asset('js/jquery-ui.min.js')}}"></script>
    <script src="{{asset('js/jquery.repeater.min.js')}}"></script>
    <script>
        var selector = "body";
       
        var FLOAT_SEP = @json((\App\Models\Utility::getValByName('float_number') ?: 'comma') === 'dot' ? '.' : ',');
    var selector = "body";

    /** Parse locale money input (comma or dot as decimal). */
    function parseNum(val) {
        if (val === null || val === undefined || val === '') return 0;
        if (typeof val === 'number') return isNaN(val) ? 0 : val;
        var s = String(val).trim().replace(/\s/g, '');
        if (!s) return 0;
        s = s.replace(/[^\d.,\-]/g, '');
        if (!s || s === '-' || s === '.') return 0;
        var lastComma = s.lastIndexOf(',');
        var lastDot = s.lastIndexOf('.');
        if (lastComma > -1 && lastDot > -1) {
            if (lastComma > lastDot) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else {
                s = s.replace(/,/g, '');
            }
        } else if (lastComma > -1) {
            s = s.replace(',', '.');
        }
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }

    function formatNum(n) {
        var x = parseNum(n);
        return x.toFixed(2).replace('.', FLOAT_SEP);
    }

    function normalizeMoneyInputs($form) {
        $form.find('.price, .discount, .quantity').each(function () {
            var n = parseNum($(this).val());
            $(this).val(String(n));
        });
    }

        
        if ($(selector + " .repeater").length) {
            var $dragAndDrop = $("body .repeater tbody").sortable({
                handle: '.sort-handler'
            });
            var $repeater = $(selector + ' .repeater').repeater({
                initEmpty: false,
                defaultValues: {
                    'status': 1
                },
                show: function () {
                    $(this).slideDown();
                    var file_uploads = $(this).find('input.multi');
                    if (file_uploads.length) {
                        $(this).find('input.multi').MultiFile({
                            max: 3,
                            accept: 'png|jpg|jpeg',
                            max_size: 2048
                        });
                    }
                },
                hide: function (deleteElement) {
                    if (confirm('Are you sure you want to delete this element?')) {
                        $(this).slideUp(deleteElement);
                        $(this).remove();

                        $(".price").change();
                        $(".discount").change();
                        $('.item option').prop('hidden', false);
                        $('.item :selected').each(function () {
                            var id = $(this).val();
                            if (id) {
                                $('.item').not(this).find("option[value=" + id + "]").prop('hidden', true);
                            }
                        });

                        var inputs = $(".amount");
                        var subTotal = 0;
                        for (var i = 0; i < inputs.length; i++) {
                            subTotal = parseNum(subTotal) + parseNum($(inputs[i]).html());
                        }
                        $('.subTotal').html(formatNum(subTotal));
                        $('.totalAmount').html(formatNum(subTotal));
                    }
                },
                ready: function (setIndexes) {
                    $dragAndDrop.on('drop', setIndexes);
                },
                isFirstItemUndeletable: true
            });
            var value = $(selector + " .repeater").attr('data-value');
            if (typeof value != 'undefined' && value.length != 0) {
                value = JSON.parse(value);
                $repeater.setList(value);
                for (var i = 0; i < value.length; i++) {
                    var tr = $('#sortable-table .id[value="' + value[i].id + '"]').parent();
                    tr.find('.item').val(value[i].product_id);
                    changeItem(tr.find('.item'));
                }
            }
        }


        $(document).on('change', '.item', function () {
            var iteams_id = $(this).val();
            var url = $(this).data('url');
            var el = $(this);
            $.ajax({
                url: url,
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': jQuery('#token').val()
                },
                data: {
                    'product_id': iteams_id
                },
                cache: false,
                success: function (data) {
                    var item = JSON.parse(data);

                    if(item.productquantity < 1)
                    {
                        show_toastr('Error', "{{__('This product is out of stock!')}}", 'error');
                        return false;
                    }

                    $(el.parent().parent().parent().find('.quantity')).val(1);
                    $(el.parent().parent().parent().find('.price')).val(formatNum(item.product.sale_price));
                    $(el.parent().parent().parent().parent().find('.pro_description')).val(item.product.description);
                    var taxes = '';
                    var tax = [];

                    var totalItemTaxRate = 0;
                    if (item.taxes == 0) {
                        taxes += '-';
                    } else {
                        for (var i = 0; i < item.taxes.length; i++) {

                            taxes += '<span class="badge bg-primary mt-1 mr-2">' + item.taxes[i].name + ' ' + '(' + item.taxes[i].rate + '%)' + '</span>';
                            tax.push(item.taxes[i].id);
                            totalItemTaxRate += parseNum(item.taxes[i].rate);

                            }
                            }
                            var itemTaxPrice = parseNum((totalItemTaxRate / 100) * (parseNum(item.product.sale_price) * 1));

                            $(el.parent().parent().parent().find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));
                            $(el.parent().parent().parent().find('.itemTaxRate')).val(totalItemTaxRate.toFixed(2));
                    $(el.parent().parent().parent().find('.taxes')).html(taxes);
                    $(el.parent().parent().parent().find('.tax')).val(tax.length ? tax.join(',') : '0');
                    $(el.parent().parent().parent().find('.unit')).text(item.unit || '').attr('title', item.unit || '');
                    $(el.parent().parent().parent().find('.discount')).val(formatNum(0));
                    $(el.parent().parent().parent().find('.amount')).html(formatNum(item.totalAmount));

                    var $wrap = $(el).closest('[data-repeater-item]');
                    if (typeof window.applyProductVariants === 'function') {
                        window.applyProductVariants($wrap, item, null, 'sale_price');
                    }


                    var inputs = $(".amount");
                    var subTotal = 0;
                    for (var i = 0; i < inputs.length; i++) {
                        subTotal = parseNum(subTotal) + parseNum($(inputs[i]).html());
                    }
                    $('.subTotal').html(formatNum(subTotal));


                    var totalItemPrice = 0;
                    var priceInput = $('.price');
                    for (var j = 0; j < priceInput.length; j++) {
                        if (!isNaN(parseNum(priceInput[j].value))) {
                            totalItemPrice += parseNum(priceInput[j].value);
                        }
                    }

                    var totalItemTaxPrice = 0;
                    var itemTaxPriceInput = $('.itemTaxPrice');
                    for (var j = 0; j < itemTaxPriceInput.length; j++) {
                        if (!isNaN(parseNum(itemTaxPriceInput[j].value))) {
                            totalItemTaxPrice += parseNum(itemTaxPriceInput[j].value);
                        }
                    }

                    $('.totalTax').html(formatNum(totalItemTaxPrice));
                    $('.totalAmount').html(formatNum(parseNum(subTotal) + parseNum(totalItemTaxPrice)));

                },
            });
        });

        $(document).on('keyup', '.quantity', function () {
            var quntityTotalTaxPrice = 0;

            var el = $(this).parent().parent().parent().parent();
            var quantity = parseNum($(this).val());
            var price = parseNum($(el.find('.price')).val());
            var item_id = $(el.find('.item')).val();

            $.ajax({
                url: '{{ route('product.quantity') }}',
                type: 'POST',
                data: {
                    "item_id": item_id,
                    "quantity": quantity,
                    "_token": "{{ csrf_token() }}",
                },
                success: function(data) {

                    if(data < quantity)
                    {
                        show_toastr('Error', "{{__('This product is out of stock!')}}", 'error');
                        return false;
                    }

            var discount = parseNum($(el.find('.discount')).val());

            var totalItemPrice = (quantity * price) - discount;
            var amount = (totalItemPrice);


            var totalItemTaxRate = parseNum($(el.find('.itemTaxRate')).val());
            var itemTaxPrice = parseNum((totalItemTaxRate / 100) * (totalItemPrice));
            $(el.find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));

            $(el.find('.amount')).html(formatNum(parseNum(itemTaxPrice) + parseNum(amount)));

            var totalItemTaxPrice = 0;
            var itemTaxPriceInput = $('.itemTaxPrice');
            for (var j = 0; j < itemTaxPriceInput.length; j++) {
                totalItemTaxPrice += parseNum(itemTaxPriceInput[j].value);
            }


            var totalItemPrice = 0;
            var inputs_quantity = $(".quantity");

            var priceInput = $('.price');
            for (var j = 0; j < priceInput.length; j++) {
                totalItemPrice += (parseNum(priceInput[j].value) * parseNum(inputs_quantity[j].value));
            }

            var inputs = $(".amount");

            var subTotal = 0;
            for (var i = 0; i < inputs.length; i++) {
                subTotal = parseNum(subTotal) + parseNum($(inputs[i]).html());
            }

            $('.subTotal').html(formatNum(amount));
            $('.totalTax').html(formatNum(totalItemTaxPrice));

            $('.totalAmount').html(formatNum(subTotal));
        }
            });
        })

        $(document).on('keyup change', '.price', function () {
            var el = $(this).parent().parent().parent().parent();
            var price = parseNum($(this).val());
            var quantity = parseNum($(el.find('.quantity')).val());

            var discount = parseNum($(el.find('.discount')).val());
            var totalItemPrice = (quantity * price) - discount;

            var amount = (totalItemPrice);


            var totalItemTaxRate = parseNum($(el.find('.itemTaxRate')).val());
            var itemTaxPrice = parseNum((totalItemTaxRate / 100) * (totalItemPrice));
            $(el.find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));

            $(el.find('.amount')).html(formatNum(parseNum(itemTaxPrice) + parseNum(amount)));

            var totalItemTaxPrice = 0;
            var itemTaxPriceInput = $('.itemTaxPrice');
            for (var j = 0; j < itemTaxPriceInput.length; j++) {
                totalItemTaxPrice += parseNum(itemTaxPriceInput[j].value);
            }


            var totalItemPrice = 0;
            var inputs_quantity = $(".quantity");

            var priceInput = $('.price');
            for (var j = 0; j < priceInput.length; j++) {
                totalItemPrice += (parseNum(priceInput[j].value) * parseNum(inputs_quantity[j].value));
            }

            var inputs = $(".amount");

            var subTotal = 0;
            for (var i = 0; i < inputs.length; i++) {
                subTotal = parseNum(subTotal) + parseNum($(inputs[i]).html());
            }

            $('.subTotal').html(formatNum(totalItemPrice));
            $('.totalTax').html(formatNum(totalItemTaxPrice));

            $('.totalAmount').html(formatNum(subTotal));


        })

        $(document).on('keyup change', '.discount', function () {
            var el = $(this).parent().parent().parent();
            var discount = parseNum($(this).val());

            var price = parseNum($(el.find('.price')).val());
            var quantity = parseNum($(el.find('.quantity')).val());
            var totalItemPrice = (quantity * price) - discount;


            var amount = (totalItemPrice);


            var totalItemTaxRate = parseNum($(el.find('.itemTaxRate')).val());
            var itemTaxPrice = parseNum((totalItemTaxRate / 100) * (totalItemPrice));
            $(el.find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));

            $(el.find('.amount')).html(formatNum(parseNum(itemTaxPrice) + parseNum(amount)));

            var totalItemTaxPrice = 0;
            var itemTaxPriceInput = $('.itemTaxPrice');
            for (var j = 0; j < itemTaxPriceInput.length; j++) {
                totalItemTaxPrice += parseNum(itemTaxPriceInput[j].value);
            }


            var totalItemPrice = 0;
            var inputs_quantity = $(".quantity");

            var priceInput = $('.price');
            for (var j = 0; j < priceInput.length; j++) {
                totalItemPrice += (parseNum(priceInput[j].value) * parseNum(inputs_quantity[j].value));
            }

            var inputs = $(".amount");

            var subTotal = 0;
            for (var i = 0; i < inputs.length; i++) {
                subTotal = parseNum(subTotal) + parseNum($(inputs[i]).html());
            }


            var totalItemDiscountPrice = 0;
            var itemDiscountPriceInput = $('.discount');

            for (var k = 0; k < itemDiscountPriceInput.length; k++) {
                if (!isNaN(parseNum(itemDiscountPriceInput[k].value))) {
                    totalItemDiscountPrice += parseNum(itemDiscountPriceInput[k].value);
                }
            }


            $('.subTotal').html(formatNum(totalItemPrice));
            $('.totalTax').html(formatNum(totalItemTaxPrice));

            $('.totalAmount').html(formatNum(subTotal));
            $('.totalDiscount').html(formatNum(totalItemDiscountPrice));




        })

        var vendorId = '{{$customer}}';
        if (vendorId > 0) {
            $('#vender').val(vendorId).change();
        }

        $(document).on('change', '.item', function () {
            $('.item option').prop('hidden', false);
            $('.item :selected').each(function () {
                var id = $(this).val();
                if (id) {
                    $('.item').not(this).find("option[value=" + id + "]").prop('hidden', true);
                }
            });
        });

        $(document).on('click', '[data-repeater-create]', function () {
            $('.item option').prop('hidden', false);
            $('.item :selected').each(function () {
                var id = $(this).val();
                if (id) {
                    $('.item').not(this).find("option[value=" + id + "]").prop('hidden', true);
                }
            });
        })
        $(document).on('submit', 'form', function () {
            if (!$(this).find('#sortable-table').length && !$(this).find('.repeater').length) {
                return;
            }
            normalizeMoneyInputs($(this));
        });
    </script>

@endpush

@section('content')
    <div class="row">
        {{ Form::open(array('url' => 'quotation','class'=>'w-100')) }}
        <div class="col-12">
            <input type="hidden" name="_token" id="token" value="{{ csrf_token() }}">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group" id="vender-box">
                                {{ Form::label('customer_id', __('Customer'),['class'=>'form-label']) }}
                                {{ Form::text('customer_id', $customer->name, array('class' => 'form-control select','required'=>'required','readonly' => 'readonly')) }}
                            </div>
                            <div id="vender_detail" class="d-none">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('warehouse_id', __('Warehouse'),['class'=>'form-label']) }}
                                        {{ Form::text('warehouse_id', $warehouse->name, array('class' => 'form-control warehouse_id','required'=>'required','readonly' => 'readonly')) }}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('quotation_date', __('Quotation Date'),['class'=>'form-label']) }}
                                        {{Form::date('quotation_date',$quotation_date,array('class'=>'form-control','required'=>'required'))}}

                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('quotation_number', __('Quotation Number'),['class'=>'form-label']) }}
                                        {{ Form::text('quotation_number', $quotation_number, ['class' => 'form-control', 'readonly' => 'readonly']) }}

                                    </div>
                                </div>
                            </div>


                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <div class="col-12">
            <h5 class=" d-inline-block mb-4">{{__('Product & Services')}}</h5>
            <div class="card repeater" data-value=''>
                <div class="item-section py-2">
                    <div class="row justify-content-between align-items-center">
                        <div class="col-md-12 d-flex align-items-center justify-content-between justify-content-md-end">
                            <div class="all-button-box me-2">
                                <a href="#" data-repeater-create="" class="btn btn-primary" data-bs-toggle="modal" data-target="#add-bank">
                                    <i class="ti ti-plus"></i> {{__('Add item')}}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table mb-0" data-repeater-list="items" id="   ">
                            <thead>
                            <tr>
                                <th>{{__('Items')}}</th>
                                <th>{{ __('Variant') }}</th>
                                <th>{{__('Quantity')}}</th>
                                <th>{{__('Price')}} </th>
                                <th>{{__('Discount')}}</th>
                                <th>{{__('Tax')}} (%)</th>
                                <th class="text-end">{{__('Amount')}} <br><small class="text-danger font-weight-bold">{{__('after tax & discount')}}</small></th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody class="ui-sortable" data-repeater-item>
                            <tr>
                                <td width="22%" class="form-group pt-1">
                                    <div class="item_div">
                                        {{ Form::select('item', $product_services,'', array('class' => 'form-control select2 item','data-url'=>route('quotation.product'),'required'=>'required')) }}
                                    </div>
                                </td>
                                <td width="16%" class="form-group pt-1">
                                    <select name="product_variant_id" class="form-control select2 variant-select" disabled>
                                        <option value="">{{ __('—') }}</option>
                                    </select>
                                </td>
                                <td class="item-col-qty">
                                    <div class="form-group price-input input-group search-form mb-0">
                                        {{ Form::text('quantity','', array('class' => 'form-control quantity','required'=>'required','placeholder'=>__('Qty'),'required'=>'required' , 'step' => '00.01' )) }}
                                    </div>
                                    <small class="unit item-unit-label" title=""></small>
                                </td>
                                <td class="item-col-price">
                                    <div class="form-group price-input input-group search-form mb-0">
                                       {{ Form::text('price','', array('class' => 'form-control price','required'=>'required','inputmode' => 'decimal','autocomplete' => 'off'  ,'placeholder'=>__('Price'),'required'=>'required')) }}
                                        <span class="input-group-text bg-transparent">{{\Auth::user()->currencySymbol()}}</span>
                                    </div>
                                </td>
                                <td class="item-col-discount">
                                    <div class="form-group price-input input-group search-form mb-0">
                                        {{ Form::text('discount','', array('class' => 'form-control discount','placeholder'=>__('Discount'))) }}
                                        <span class="input-group-text bg-transparent">{{\Auth::user()->currencySymbol()}}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-group">
                                        <div class="input-group">
                                            <div class="taxes"></div>
                                            {{ Form::hidden('tax','0', array('class' => 'form-control tax')) }}
                                            {{ Form::hidden('itemTaxPrice','', array('class' => 'form-control itemTaxPrice')) }}
                                            {{ Form::hidden('itemTaxRate','', array('class' => 'form-control itemTaxRate')) }}
                                        </div>
                                    </div>
                                </td>

                                <td class="text-end amount">
                                    0.00
                                </td>
                                <td>
                                    <div class="action-btn me-2">
                                        <a href="#" class="ti ti-trash text-white btn btn-sm repeater-action-btn bg-danger ms-2" data-repeater-delete data-bs-toggle="tooltip" title="{{__('Delete')}}"></a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3">
                                    <div class="form-group">{{ Form::textarea('description', null, ['class'=>'form-control pro_description','rows'=>'2','placeholder'=>__('Description')]) }}</div>
                                </td>
                                <td colspan="5"></td>
                            </tr>
                            </tbody>
                            <tfoot>
                            <tr>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td></td>
                                <td><strong>{{__('Sub Total')}} ({{\Auth::user()->currencySymbol()}})</strong></td>
                                <td class="text-end subTotal">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td></td>
                                <td><strong>{{__('Discount')}} ({{\Auth::user()->currencySymbol()}})</strong></td>
                                <td class="text-end totalDiscount">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td></td>
                                <td><strong>{{__('Tax')}} ({{\Auth::user()->currencySymbol()}})</strong></td>
                                <td class="text-end totalTax">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td></td>
                                <td class="blue-text"><strong>{{__('Total Amount')}} ({{\Auth::user()->currencySymbol()}})</strong></td>
                                <td class="blue-text text-end totalAmount">0.00</td>
                                <td></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <input type="button" value="{{__('Cancel')}}" onclick="location.href = '{{route("quotation.index")}}';" class="btn btn-secondary me-2">
            <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
        </div>
    {{ Form::close() }}
    </div>

@endsection

@push('script-page')
    @include('partials.product_variant_line_js')
@endpush

