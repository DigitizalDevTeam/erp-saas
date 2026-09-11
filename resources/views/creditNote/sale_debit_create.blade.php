{{ Form::open(array('route' => array('create.sale.debit.note'),'mothod'=>'post', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group col-md-12">
            {{ Form::label('invoice', __('Invoice (Optional)'),['class'=>'form-label']) }}
                <select class="form-control select" id="invoice" name="invoice">
                    <option value="">{{__('Select Invoice (Optional)')}}</option>
                    @foreach($invoices as $key=>$invoice)
                        <option value="{{$key}}">{{\Auth::user()->invoiceNumberFormat($invoice)}}</option>
                    @endforeach
                </select>
                <div class="text-xs mt-1">
                    {{ __('Create invoice here.') }} <a href="{{ route('invoice.index') }}"><b>{{ __('Create invoice') }}</b></a>
                </div>
                <div class="text-xs text-muted mt-1">
                    {{ __('Paid and cancelled invoices are not listed.') }}
                </div>
        </div>
        <div class="form-group col-md-12" id="customer_div" style="display: none;">
            {{ Form::label('customer', __('Customer'),['class'=>'form-label']) }}<x-required></x-required>
                <select class="form-control select" required="required" id="customer" name="customer">
                    <option value="">{{__('Select Customer')}}</option>
                    @foreach($customers as $key=>$customer)
                        <option value="{{$key}}">{{$customer}}</option>
                    @endforeach
                </select>
        </div>
        <div class="form-group col-md-12 mt-3" id="invoice-items-container" style="display:none;">
            <label class="form-label">{{ __('Select Items') }}</label>
            <div id="invoice-items"></div>
        </div>
        <div class="form-group col-md-12 mt-3" id="product-items-container" style="display:none;">
            <label class="form-label">{{ __('Add Items') }}</label>
            <div id="product-items">
                @include('creditNote.product_items', ['products' => $products])
            </div>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('amount', __('Amount'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::number('amount', null, array('class' => 'form-control','required'=>'required','step'=>'0.01' , 'placeholder' => __('Enter Amount') , 'id' => 'amountInp')) }}

        </div>
        <div class="form-group  col-md-6">
            {{ Form::label('date', __('Date'),['class'=>'form-label']) }}<x-required></x-required>
            {{Form::date('date',null,array('class'=>'form-control','required'=>'required'))}}

        </div>
        <div class="form-group col-md-12">
            {{ Form::label('description', __('Description'),['class'=>'form-label']) }}
            {!! Form::textarea('description', null, ['class'=>'form-control','rows'=>'2' , 'placeholder' => __('Enter Description')]) !!}
        </div>
        <div class="form-group col-md-12" id="return_items_div">
            <input type="checkbox" id="return_items" name="return_items" value="1">
            {{ Form::label('return_items', __('Select Items / Qty'),['class'=>'form-label']) }}
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}
<script>
    $(document).ready(function () {
        function recalcAmount() {
            let total = 0;
            $('.returnQty:visible').each(function () {
                let qty = parseFloat($(this).val()) || 0;
                let price = parseFloat($(this).data('price')) || 0;
                total += qty * price;
            });
            $('#amountInp').val(total.toFixed(2));
        }

        function toggleItemsUi() {
            var invoiceId = $('#invoice').val();
            var useItems = $('#return_items').prop('checked') == true;

            if (invoiceId) {
                $('#customer_div').hide();
                $('#customer').removeAttr('required');
                $('#product-items-container').hide();
                if (useItems) {
                    $('#invoice-items-container').show();
                    $('#amountInp').attr('readonly', true);
                } else {
                    $('#invoice-items-container').hide();
                    $('#amountInp').attr('readonly', false);
                }
            } else {
                $('#customer_div').show();
                $('#customer').attr('required', 'required');
                $('#invoice-items-container').hide();
                if (useItems) {
                    $('#product-items-container').show();
                    $('#amountInp').attr('readonly', true);
                    recalcAmount();
                } else {
                    $('#product-items-container').hide();
                    $('#amountInp').attr('readonly', false);
                }
            }
        }

        $('#invoice').on('change', function () {
            var invoiceId = $(this).val();
            if (invoiceId) {
                $.ajax({
                    url: "{{ route('invoice.get.items') }}",
                    type: "GET",
                    data: {invoice_id: invoiceId},
                    success: function (response) {
                        $('#invoice-items').html(response.html);
                        toggleItemsUi();
                    },
                    error: function () {
                        $('#invoice-items').html('<div class="text-danger">{{ __('Failed to load items.') }}</div>');
                    }
                });
            } else {
                $('#invoice-items').empty();
                toggleItemsUi();
            }
        });

        $(document).on('input', '.returnQty', function () {
            let max = parseFloat($(this).attr('max'));
            let val = parseFloat($(this).val()) || 0;
            if (!isNaN(max) && val > max) {
                $(this).val(max);
            }
            recalcAmount();
        });

        $('#return_items').on('change', function () {
            if ($(this).prop('checked') == true) {
                $('#amountInp').val('');
            } else {
                $('#amountInp').val('');
            }
            toggleItemsUi();
        });

        $('#invoice').trigger('change');
        toggleItemsUi();
    });
</script>
