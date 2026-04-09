@extends('layouts.admin')
@section('page-title')
    {{__('Invoice Create')}}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item"><a href="{{route('invoice.index')}}">{{__('Invoice')}}</a></li>
    <li class="breadcrumb-item">{{__('Invoice Create')}}</li>
@endsection
@push('script-page')
    <script src="{{asset('js/jquery-ui.min.js')}}"></script>
    <script src="{{asset('js/jquery.repeater.min.js')}}"></script>
    <script>
        var INVOICE_EDIT_ID = null;
        var INVOICE_IDENTIFIERS_URL = @json(route('invoice.product.identifiers'));
        var selector = "body";
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
                            subTotal = parseFloat(subTotal) + parseFloat($(inputs[i]).html());
                        }
                        $('.subTotal').html(subTotal.toFixed(2));
                        $('.totalAmount').html(subTotal.toFixed(2));
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
            }

        }

        // $(document).on('change', '#customer', function () {
        //     $('#customer_detail').removeClass('d-none');
        //     $('#customer_detail').addClass('d-block');
        //     $('#customer-box').removeClass('d-block');
        //     $('#customer-box').addClass('d-none');
        //     var id = $(this).val();
        //     var url = $(this).data('url');
        //     $.ajax({
        //         url: url,
        //         type: 'POST',
        //         headers: {
        //             'X-CSRF-TOKEN': jQuery('#token').val()
        //         },
        //         data: {
        //             'id': id
        //         },
        //         cache: false,
        //         success: function (data) {
        //             if (data != '') {
        //                 $('#customer_detail').html(data);
        //             } else {
        //                 $('#customer-box').removeClass('d-none');
        //                 $('#customer-box').addClass('d-block');
        //                 $('#customer_detail').removeClass('d-block');
        //                 $('#customer_detail').addClass('d-none');
        //             }

        //         },

        //     });
        // });
        
        $(document).on('click', '.set-address', function () {
            
            var billing_id = $("#billing_address").val();
            var shipping_id = $("#shipping_address").val();
            if(billing_id == "" || billing_id == null || billing_id == undefined){
                alert("Billing field is requried.")
                return;
            }
            if(shipping_id == "" || shipping_id == null || shipping_id == undefined){
                alert("Shipping field is requried.")
                return;
            }
            
            $('#customer_detail').removeClass('d-none');
            $('#customer_detail').addClass('d-block');
            $('#customer-box').removeClass('d-block');
            $('#customer-box').addClass('d-none');
          
            var url = $(this).data('url');
            $.ajax({
                url: url,
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': jQuery('#token').val()
                },
                data: {
                    'billing_id': billing_id,
                    'shipping_id': shipping_id
                },
                cache: false,
                success: function (data) {
                    if (data != '') {
                        $('#customer_detail').html(data);
                    } else {
                        $('#customer-box').removeClass('d-none');
                        $('#customer-box').addClass('d-block');
                        $('#customer_detail').removeClass('d-block');
                        $('#customer_detail').addClass('d-none');
                    }

                },

            });
        });
        
        $(document).on('change', '#customer', function () {
            $('.address_fields').removeClass('d-none');
            var id = $(this).val();
            var url = $(this).data('url');
            $.ajax({
                url: url,
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': jQuery('#token').val()
                },
                data: {
                    'id': id
                },
                cache: false,
                success: function (data) {
                    if(data.shipping_address.length > 0){
                        $('#shipping_address option:not(:first)').remove();
                        let shippingHtml = "";
                        $(data.shipping_address).each(function(index, value){
                            shippingHtml += `<option value="${value.id}">${value.name}</option>`
                        })
                        $('#shipping_address').append(shippingHtml);
                    }else{
                        $('#shipping_address option:not(:first)').remove();
                    }
                    if(data.billing_address.length > 0){
                        $('#billing_address option:not(:first)').remove();
                        let billingHtml = "";
                        $(data.billing_address).each(function(index, value){
                            billingHtml += `<option value="${value.id}">${value.name}</option>`
                        })
                        $('#billing_address').append(billingHtml);
                    }else{
                        $('#billing_address option:not(:first)').remove();
                    }
                },

            });
        });
        

        $(document).on('click', '#remove', function () {
            $('#customer-box').removeClass('d-none');
            $('#customer-box').addClass('d-block');
            $('#customer_detail').removeClass('d-block');
            $('#customer_detail').addClass('d-none');
            $('.address_fields').addClass('d-none');
        })

        var invoiceMeasureState = null;
        function invoiceMeasurementPopup(item, $line, onDone) {
            var unitType = (item.unit_type || '').toLowerCase();
            if (['sm', 'cbm', 'volume'].indexOf(unitType) === -1) {
                $line.find('.measurement_json').val('');
                onDone(null);
                return;
            }
            invoiceMeasureState = {item: item, $line: $line, onDone: onDone, unitType: unitType};
            var dimUnit = item.dimension_unit || 'cm';
            $('.invoice_measure_dim_unit').text(dimUnit);
            $('#invoice_measure_divisor_wrap').toggle(unitType === 'cbm');
            $('#invoice_measure_height_wrap').toggle(unitType !== 'sm');
            $('#invoice_measure_divisor').val(parseFloat(item.volumetric_divisor || 0) || '');
            $('#invoice_measure_length').val(item.unit_length || '');
            $('#invoice_measure_width').val(item.unit_width || '');
            $('#invoice_measure_height').val(item.unit_height || '');
            $('#invoiceMeasureModal').modal('show');
        }
        $(document).on('click', '#invoice_measure_cancel', function () {
            if (invoiceMeasureState && typeof invoiceMeasureState.onDone === 'function') {
                invoiceMeasureState.onDone(false);
            }
            $('#invoiceMeasureModal').modal('hide');
            invoiceMeasureState = null;
        });
        $('#invoiceMeasureModal').on('hidden.bs.modal', function () {
            if (invoiceMeasureState && typeof invoiceMeasureState.onDone === 'function') {
                invoiceMeasureState.onDone(false);
                invoiceMeasureState = null;
            }
        });
        $(document).on('click', '#invoice_measure_apply', function () {
            if (!invoiceMeasureState) return;
            var unitType = invoiceMeasureState.unitType;
            var item = invoiceMeasureState.item;
            var $line = invoiceMeasureState.$line;
            var length = parseFloat($('#invoice_measure_length').val() || 0);
            var width = parseFloat($('#invoice_measure_width').val() || 0);
            var height = unitType === 'sm' ? 0 : parseFloat($('#invoice_measure_height').val() || 0);
            var divisor = parseFloat($('#invoice_measure_divisor').val() || item.volumetric_divisor || 0);
            if (length <= 0 || width <= 0 || (unitType !== 'sm' && height <= 0)) {
                show_toastr('Error', 'Valid dimensions are required.', 'error');
                return;
            }
            var factor = 1;
            if (unitType === 'sm') factor = length * width;
            else if (unitType === 'cbm') {
                if (divisor <= 0) divisor = 5000;
                factor = (length * width * height) / divisor;
            } else factor = length * width * height;
            factor = parseFloat(factor.toFixed(4));
            if (factor <= 0) {
                show_toastr('Error', 'Calculated value must be greater than zero.', 'error');
                return;
            }
            var payload = {
                unit_type: unitType,
                dimension_unit: item.dimension_unit || 'cm',
                length: length,
                width: width,
                height: height,
                divisor: divisor || null,
                factor: factor
            };
            $line.find('.measurement_json').val(JSON.stringify(payload));
            invoiceMeasureState.onDone(payload);
            $('#invoiceMeasureModal').modal('hide');
            invoiceMeasureState = null;
        });

        function invoiceIdentifierQtyInt($wrap) {
            var v = $wrap.find('.quantity').val();
            var n = parseInt(String(v).split(/[.,]/)[0], 10);
            return isNaN(n) ? 0 : n;
        }

        function invoiceSyncIdentifiersHidden($wrap) {
            var vals = [];
            $wrap.find('.identifiers-select option:selected').each(function () {
                vals.push($(this).val());
            });
            $wrap.find('.identifiers-input').val(vals.join('\n'));
        }

        function invoiceUpdateIdentifierHint($wrap) {
            var max = invoiceIdentifierQtyInt($wrap);
            var n = ($wrap.find('.identifiers-select').val() || []).length;
            $wrap.find('.identifier-pick-hint').text('{{ __("Selected") }}: ' + n + ' / ' + max);
        }

        function invoiceEnforceIdentifierPickLimit($wrap) {
            var max = invoiceIdentifierQtyInt($wrap);
            var $sel = $wrap.find('.identifiers-select');
            if (!$sel.length) {
                return;
            }
            if (max <= 0) {
                $sel.val([]);
                invoiceSyncIdentifiersHidden($wrap);
                invoiceUpdateIdentifierHint($wrap);
                return;
            }
            var sel = $sel.val() || [];
            if (sel.length > max) {
                sel = sel.slice(0, max);
                $sel.val(sel);
                show_toastr('{{ __("Warning") }}', '{{ __("You can select only as many Serial/MAC as quantity.") }}', 'warning');
            }
            invoiceSyncIdentifiersHidden($wrap);
            invoiceUpdateIdentifierHint($wrap);
        }

        function invoiceLoadIdentifierPicklist($wrap, prefillRaw) {
            var pid = $wrap.find('.item').val();
            var $sel = $wrap.find('.identifiers-select');
            if (!pid || !$sel.length) {
                return;
            }
            var pre = (typeof prefillRaw !== 'undefined' && prefillRaw !== null) ? prefillRaw : ($wrap.find('.identifiers-input').val() || '');
            $.ajax({
                url: INVOICE_IDENTIFIERS_URL,
                type: 'GET',
                data: {
                    product_id: pid,
                    invoice_id: INVOICE_EDIT_ID || '',
                    invoice_product_id: $wrap.find('input.id').val() || ''
                },
                success: function (res) {
                    $sel.empty();
                    (res.identifiers || []).forEach(function (row) {
                        var label = row.identifier_value;
                        if (row.identifier_type) {
                            label += ' (' + row.identifier_type + ')';
                        }
                        $sel.append($('<option></option>').attr('value', row.identifier_value).text(label));
                    });
                    var wants = String(pre).split(/[\r\n,;]+/).map(function (s) { return s.trim(); }).filter(Boolean);
                    var matched = [];
                    wants.forEach(function (w) {
                        if ($sel.find('option').filter(function () { return $(this).val() === w; }).length) {
                            matched.push(w);
                        }
                    });
                    $sel.val(matched);
                    invoiceEnforceIdentifierPickLimit($wrap);
                }
            });
        }

        $(document).on('change', '#sortable-table .identifiers-select', function () {
            invoiceEnforceIdentifierPickLimit($(this).closest('[data-repeater-item]'));
        });
        $(document).on('change input', '#sortable-table .quantity', function () {
            var $w = $(this).closest('[data-repeater-item]');
            if ($w.find('.identifier-row').is(':visible')) {
                invoiceEnforceIdentifierPickLimit($w);
            }
        });
        $(document).on('submit', 'form', function () {
            if (!$(this).find('#sortable-table').length) {
                return;
            }
            $(this).find('#sortable-table [data-repeater-item]').each(function () {
                invoiceSyncIdentifiersHidden($(this));
            });
        });

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
                    var $line = $(el).closest('[data-repeater-item]');
                    invoiceMeasurementPopup(item, $line, function (m) {
                        if (m === false) {
                            $(el).val('');
                            return;
                        }
                        $(el.parent().parent().find('.quantity')).val(1);
                        var basePrice = parseFloat(item.product.sale_price || 0);
                        var finalPrice = m ? (basePrice * m.factor) : basePrice;
                        $(el.parent().parent().find('.price')).val(finalPrice.toFixed(2));
                        $(el.parent().parent().parent().find('.pro_description')).val(item.product.description);

                    // Identifier tracking (Serial/MAC)
                    var $wrap = $(el).closest('[data-repeater-item]');
                    var $idRow = $wrap.find('.identifier-row');
                    if (item.identifier_tracking) {
                        $idRow.removeClass('d-none');
                        var label = item.identifier_type === 'mac' ? '{{ __("MAC Address List") }}' : '{{ __("Serial Number List") }}';
                        if (item.identifier_type === 'both') {
                            label = '{{ __("Serial/MAC List") }}';
                        }
                        $idRow.find('.identifier-label').text(label);
                        $idRow.find('.identifiers-input').val('');
                        invoiceLoadIdentifierPicklist($wrap, '');
                    } else {
                        $idRow.addClass('d-none');
                        $idRow.find('.identifiers-input').val('');
                        $idRow.find('.identifiers-select').empty();
                    }

                    var taxes = '';
                    var tax = [];

                    var totalItemTaxRate = 0;
                    if (item.taxes == 0) {
                        taxes += '-';
                    } else {
                        for (var i = 0; i < item.taxes.length; i++) {
                            taxes += '<span class="badge bg-primary mt-1 mr-2">' + item.taxes[i].name + ' ' + '(' + item.taxes[i].rate + '%)' + '</span>';
                            tax.push(item.taxes[i].id);
                            totalItemTaxRate += parseFloat(item.taxes[i].rate);
                        }
                    }
                    var itemTaxPrice = parseFloat((totalItemTaxRate / 100)) * parseFloat((item.product.sale_price * 1));
                    $(el.parent().parent().find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));
                    $(el.parent().parent().find('.itemTaxRate')).val(totalItemTaxRate.toFixed(2));
                    $(el.parent().parent().find('.taxes')).html(taxes);
                    $(el.parent().parent().find('.tax')).val(tax);
                    $(el.parent().parent().find('.unit')).html(item.unit);
                    $(el.parent().parent().find('.discount')).val(0);

                    var inputs = $(".amount");
                    var subTotal = 0;
                    for (var i = 0; i < inputs.length; i++) {
                        subTotal = parseFloat(subTotal) + parseFloat($(inputs[i]).html());
                    }

                    var totalItemPrice = 0;
                    var priceInput = $('.price');
                    for (var j = 0; j < priceInput.length; j++) {
                        if (!isNaN(parseFloat(priceInput[j].value))) {
                            totalItemPrice += parseFloat(priceInput[j].value);
                        }
                    }

                    var totalItemTaxPrice = 0;
                    var itemTaxPriceInput = $('.itemTaxPrice');
                    for (var j = 0; j < itemTaxPriceInput.length; j++) {
                        if (!isNaN(parseFloat(itemTaxPriceInput[j].value))) {
                            totalItemTaxPrice += parseFloat(itemTaxPriceInput[j].value);
                            $(el.parent().parent().find('.amount')).html(parseFloat(item.totalAmount)+parseFloat(itemTaxPriceInput[j].value));
                        }
                    }

                    var totalItemDiscountPrice = 0;
                    var itemDiscountPriceInput = $('.discount');

                    for (var k = 0; k < itemDiscountPriceInput.length; k++) {
                        if (!isNaN(parseFloat(itemDiscountPriceInput[k].value))) {
                            totalItemDiscountPrice += parseFloat(itemDiscountPriceInput[k].value);
                        }
                    }

                    $('.subTotal').html(totalItemPrice.toFixed(2));
                    $('.totalTax').html(totalItemTaxPrice.toFixed(2));
                    $('.totalAmount').html((parseFloat(totalItemPrice) - parseFloat(totalItemDiscountPrice) + parseFloat(totalItemTaxPrice)).toFixed(2));
                    });


                },
            });
        });

        $(document).on('keyup', '.quantity', function () {
            var quntityTotalTaxPrice = 0;

            var el = $(this).parent().parent().parent().parent();

            var quantity = $(this).val();
            var price = $(el.find('.price')).val();
            var discount = $(el.find('.discount')).val();
            if(discount.length <= 0)
            {
                discount = 0 ;
            }

            var totalItemPrice = (quantity * price) - discount;

            var amount = (totalItemPrice);


            var totalItemTaxRate = $(el.find('.itemTaxRate')).val();
            var itemTaxPrice = parseFloat((totalItemTaxRate / 100) * (totalItemPrice));
            $(el.find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));

            $(el.find('.amount')).html(parseFloat(itemTaxPrice)+parseFloat(amount));

            var totalItemTaxPrice = 0;
            var itemTaxPriceInput = $('.itemTaxPrice');
            for (var j = 0; j < itemTaxPriceInput.length; j++) {
                totalItemTaxPrice += parseFloat(itemTaxPriceInput[j].value);
            }


            var totalItemPrice = 0;
            var inputs_quantity = $(".quantity");

            var priceInput = $('.price');
            for (var j = 0; j < priceInput.length; j++) {
                totalItemPrice += (parseFloat(priceInput[j].value) * parseFloat(inputs_quantity[j].value));
            }

            var inputs = $(".amount");

            var subTotal = 0;
            for (var i = 0; i < inputs.length; i++) {
                subTotal = parseFloat(subTotal) + parseFloat($(inputs[i]).html());
            }

            $('.subTotal').html(totalItemPrice.toFixed(2));
            $('.totalTax').html(totalItemTaxPrice.toFixed(2));

            $('.totalAmount').html((parseFloat(subTotal)).toFixed(2));

        })

        $(document).on('keyup change', '.price', function () {
            var el = $(this).parent().parent().parent().parent();
            var price = $(this).val();
            var quantity = $(el.find('.quantity')).val();

            var discount = $(el.find('.discount')).val();
            if(discount.length <= 0)
            {
                discount = 0 ;
            }
            var totalItemPrice = (quantity * price)-discount;

            var amount = (totalItemPrice);


            var totalItemTaxRate = $(el.find('.itemTaxRate')).val();
            var itemTaxPrice = parseFloat((totalItemTaxRate / 100) * (totalItemPrice));
            $(el.find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));

            $(el.find('.amount')).html(parseFloat(itemTaxPrice)+parseFloat(amount));

            var totalItemTaxPrice = 0;
            var itemTaxPriceInput = $('.itemTaxPrice');
            for (var j = 0; j < itemTaxPriceInput.length; j++) {
                totalItemTaxPrice += parseFloat(itemTaxPriceInput[j].value);
            }


            var totalItemPrice = 0;
            var inputs_quantity = $(".quantity");

            var priceInput = $('.price');
            for (var j = 0; j < priceInput.length; j++) {
                totalItemPrice += (parseFloat(priceInput[j].value) * parseFloat(inputs_quantity[j].value));
            }

            var inputs = $(".amount");

            var subTotal = 0;
            for (var i = 0; i < inputs.length; i++) {
                subTotal = parseFloat(subTotal) + parseFloat($(inputs[i]).html());
            }

            $('.subTotal').html(totalItemPrice.toFixed(2));
            $('.totalTax').html(totalItemTaxPrice.toFixed(2));

            $('.totalAmount').html((parseFloat(subTotal)).toFixed(2));


        })

        $(document).on('keyup change', '.discount', function () {
            var el = $(this).parent().parent().parent();
            var discount = $(this).val();
            if(discount.length <= 0)
            {
                discount = 0 ;
            }

            var price = $(el.find('.price')).val();
            var quantity = $(el.find('.quantity')).val();
            var totalItemPrice = (quantity * price) - discount;


            var amount = (totalItemPrice);


            var totalItemTaxRate = $(el.find('.itemTaxRate')).val();
            var itemTaxPrice = parseFloat((totalItemTaxRate / 100) * (totalItemPrice));
            $(el.find('.itemTaxPrice')).val(itemTaxPrice.toFixed(2));

            $(el.find('.amount')).html(parseFloat(itemTaxPrice)+parseFloat(amount));

            var totalItemTaxPrice = 0;
            var itemTaxPriceInput = $('.itemTaxPrice');
            for (var j = 0; j < itemTaxPriceInput.length; j++) {
                totalItemTaxPrice += parseFloat(itemTaxPriceInput[j].value);
            }


            var totalItemPrice = 0;
            var inputs_quantity = $(".quantity");

            var priceInput = $('.price');
            for (var j = 0; j < priceInput.length; j++) {
                totalItemPrice += (parseFloat(priceInput[j].value) * parseFloat(inputs_quantity[j].value));
            }

            var inputs = $(".amount");

            var subTotal = 0;
            for (var i = 0; i < inputs.length; i++) {
                subTotal = parseFloat(subTotal) + parseFloat($(inputs[i]).html());
            }


            var totalItemDiscountPrice = 0;
            var itemDiscountPriceInput = $('.discount');

            for (var k = 0; k < itemDiscountPriceInput.length; k++) {
                if (!isNaN(parseFloat(itemDiscountPriceInput[k].value))) {
                    totalItemDiscountPrice += parseFloat(itemDiscountPriceInput[k].value);
                }
            }


            $('.subTotal').html(totalItemPrice.toFixed(2));
            $('.totalTax').html(totalItemTaxPrice.toFixed(2));

            $('.totalAmount').html((parseFloat(subTotal)).toFixed(2));
            $('.totalDiscount').html(totalItemDiscountPrice.toFixed(2));




        })

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

        var customerId = '{{$customerId}}';
        if (customerId > 0) {
            $('#customer').val(customerId).change();
        }

    </script>

@endpush
@section('content')
    <div class="modal fade" id="invoiceMeasureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Enter Dimensions') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">{{ __('Length') }} (<span class="invoice_measure_dim_unit">cm</span>)</label>
                        <input type="number" step="0.0001" min="0" id="invoice_measure_length" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">{{ __('Width') }} (<span class="invoice_measure_dim_unit">cm</span>)</label>
                        <input type="number" step="0.0001" min="0" id="invoice_measure_width" class="form-control">
                    </div>
                    <div class="mb-2" id="invoice_measure_height_wrap">
                        <label class="form-label">{{ __('Height') }} (<span class="invoice_measure_dim_unit">cm</span>)</label>
                        <input type="number" step="0.0001" min="0" id="invoice_measure_height" class="form-control">
                    </div>
                    <div class="mb-2" id="invoice_measure_divisor_wrap" style="display:none;">
                        <label class="form-label">{{ __('Divisor') }}</label>
                        <input type="number" step="1" min="1" id="invoice_measure_divisor" class="form-control" placeholder="4000 / 5000 / 6000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="invoice_measure_cancel">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-primary" id="invoice_measure_apply">{{ __('Apply') }}</button>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        {{ Form::open(array('url' => 'invoice','class'=>'w-100', 'class'=>'needs-validation', 'novalidate')) }}
        <div class="col-12">
            <input type="hidden" name="_token" id="token" value="{{ csrf_token() }}">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-xs-12 col-sm-12 col-md-6 col-lg-6">
                            <div class="form-group" id="customer-box">
                                {{ Form::label('customer_id', __('Customer'),['class'=>'form-label']) }}<x-required></x-required>
                                {{ Form::select('customer_id', $customers,$customerId, array('class' => 'form-control select','id'=>'customer','data-url'=>route('invoice.customer.addresses'),'required'=>'required')) }}
                                  <div class="text-xs mt-1">
                                    {{ __('Create customer here.') }} <a href="{{ route('customer.index') }}"><b>{{ __('Create customer') }}</b></a>
                                </div>
                                
                                <div class="address_fields d-none mt-1">
                                    {{ Form::label('billing_address', __('Billing Address'),['class'=>'form-label']) }}<x-required></x-required>
                                    {{ Form::select('billing_address', ["" => "Select Billing Address"], "", array('class' => 'form-control select','id'=>'billing_address','required'=>'required')) }}
                                    
                                    {{ Form::label('shipping_address', __('Shipping Address'),['class'=>'form-label mt-1']) }}<x-required></x-required>
                                    {{ Form::select('shipping_address', ["" => "Select Shipping Address"], "", array('class' => 'form-control select','id'=>'shipping_address','required'=>'required')) }}
                                    <button class="btn btn-primary d-flex p-2 mt-2 set-address" data-url="{{ route('invoice.customer.specific.address') }}" type="button"><i class="ti ti-check"></i></button>
                                </div>
                            </div>

                            <div id="customer_detail" class="d-none">
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-12 col-md-6 col-lg-6">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('issue_date', __('Issue Date'),['class'=>'form-label']) }}<x-required></x-required>
                                        <div class="form-icon-user">
                                            {{Form::date('issue_date',null,array('class'=>'form-control','required'=>'required'))}}

                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('due_date', __('Due Date'),['class'=>'form-label']) }}<x-required></x-required>
                                        <div class="form-icon-user">
                                            {{Form::date('due_date',null,array('class'=>'form-control','required'=>'required'))}}

                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('invoice_number', __('Invoice Number'),['class'=>'form-label']) }}
                                        <div class="form-icon-user">
                                            <input type="text" class="form-control" value="{{$invoice_number}}" readonly>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('category_id', __('Category'),['class'=>'form-label']) }}<x-required></x-required>
                                        {{ Form::select('category_id', $category,null, array('class' => 'form-control select','required'=>'required')) }}
                                        <div class="text-xs mt-1">
                                            {{ __('Create category here.') }} <a href="{{ route('product-category.index') }}"><b>{{ __('Create category') }}</b></a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('ref_number', __('Ref Number'),['class'=>'form-label']) }}
                                        <div class="form-icon-user">
                                            <span><i class="ti ti-joint"></i></span>
                                            {{ Form::text('ref_number', '', array('class' => 'form-control' , 'placeholder'=>__('Enter Ref NUmber'))) }}
                                        </div>
                                    </div>
                                </div>
                                @if(!$customFields->isEmpty())
                                            @include('customFields.formBuilder')
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <h5 class=" d-inline-block mb-4">{{__('Product & Services')}}</h5>
            <div class="card repeater">
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
                <div class="card-body table-border-style mt-2">
                    <div class="table-responsive">
                        <table class="table mb-0 table-custom-style" data-repeater-list="items" id="sortable-table">
                            <thead>
                            <tr>
                                <th>{{__('Items')}}<x-required></x-required></th>
                                <th>{{__('Quantity')}}<x-required></x-required></th>
                                <th>{{__('Price')}}<x-required></x-required></th>
                                <th>{{__('Discount')}}<x-required></x-required></th>
                                <th>{{__('Tax')}} (%)</th>
                                <th class="text-end">{{__('Amount')}} <br><small class="text-danger font-weight-bold">{{__('after tax & discount')}}</small></th>
                                <th></th>
                            </tr>
                            </thead>

                            <tbody class="ui-sortable" data-repeater-item>
                            <tr>

                                <td width="25%" class="form-group pt-0">
                                    {{ Form::select('item', $product_services,'', array('class' => 'form-control select2 item','data-url'=>route('invoice.product'),'required'=>'required')) }}
                                </td>
                                <td>
                                    <div class="form-group price-input input-group search-form">
                                        {{ Form::number('quantity','', array('class' => 'form-control quantity','required'=>'required','placeholder'=>__('Qty'),'required'=>'required')) }}
                                        <span class="unit input-group-text bg-transparent"></span>
                                    </div>
                                </td>


                                <td>
                                    <div class="form-group price-input input-group search-form">
                                        {{ Form::number('price','', array('class' => 'form-control price','required'=>'required','placeholder'=>__('Price'),'required'=>'required')) }}
                                        <span class="input-group-text bg-transparent">{{\Auth::user()->currencySymbol()}}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="form-group price-input input-group search-form">
                                        {{ Form::number('discount','', array('class' => 'form-control discount','required'=>'required','placeholder'=>__('Discount'))) }}
                                        <span class="input-group-text bg-transparent">{{\Auth::user()->currencySymbol()}}</span>
                                    </div>
                                </td>



                                <td>
                                    <div class="form-group">
                                        <div class="input-group colorpickerinput">
                                            <div class="taxes"></div>
                                            {{ Form::hidden('tax','', array('class' => 'form-control tax text-dark')) }}
                                            {{ Form::hidden('itemTaxPrice','', array('class' => 'form-control itemTaxPrice')) }}
                                            {{ Form::hidden('itemTaxRate','', array('class' => 'form-control itemTaxRate')) }}
                                            {{ Form::hidden('measurement_json','', array('class' => 'form-control measurement_json')) }}
                                        </div>
                                    </div>
                                </td>

                                <td class="text-end amount">0.00</td>
                                <td>
                                    <div class="action-btn me-2">
                                        <a href="#" class="ti ti-trash text-white btn btn-sm repeater-action-btn bg-danger ms-2 " data-bs-toggle="tooltip" title="{{ __('Delete') }}" data-repeater-delete></a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <div class="form-group">
                                        {{ Form::textarea('description', null, ['class'=>'form-control pro_description','rows'=>'2','placeholder'=>__('Description')]) }}
                                    </div>
                                </td>
                                <td colspan="5"></td>
                            </tr>
                            <tr class="identifier-row d-none">
                                <td colspan="7">
                                    <div class="form-group">
                                        <label class="form-label identifier-label">{{ __('Serial/MAC List') }}</label>
                                        {{ Form::hidden('identifiers', '', ['class' => 'identifiers-input']) }}
                                        <select class="form-control identifiers-select" multiple size="8" data-placeholder="{{ __('Hold Ctrl (Cmd on Mac) to select multiple') }}"></select>
                                        <small class="text-muted identifier-pick-hint d-block mt-1">{{ __('Selected') }}: 0 / 0</small>
                                        <small class="text-muted identifier-help d-block">{{ __('Select from in-stock list. Count must match quantity. No duplicates.') }}</small>
                                    </div>
                                </td>
                            </tr>
                            </tbody>
                            <tfoot>
                            <tr>
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
                                <td></td>
                                <td><strong>{{__('Discount')}} ({{\Auth::user()->currencySymbol()}})</strong></td>
                                <td class="text-end totalDiscount">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
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
                                <td class="blue-text"><strong>{{__('Total Amount')}} ({{\Auth::user()->currencySymbol()}})</strong></td>
                                <td class="text-end totalAmount blue-text">0.00</td>
                                <td></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <input type="button" value="{{__('Cancel')}}" onclick="location.href = '{{route("invoice.index")}}';" class="btn btn-secondary me-2">
            <input type="submit" value="{{__('Create')}}" class="btn btn-primary">
        </div>
        {{ Form::close() }}

    </div>
@endsection


