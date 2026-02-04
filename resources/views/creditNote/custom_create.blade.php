{{ Form::open(array('route' => array('invoice.custom.credit.note'),'mothod'=>'post', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group col-md-12">
            {{ Form::label('invoice', __('Invoice'),['class'=>'form-label']) }}<x-required></x-required>
                <select class="form-control select" required="required" id="invoice" name="invoice">
                    <option value="">{{__('Select Invoice')}}</option>
                    @foreach($invoices as $key=>$invoice)
                        <option value="{{$key}}">{{\Auth::user()->invoiceNumberFormat($invoice)}}</option>
                    @endforeach
                </select>
                <div class="text-xs mt-1">
                    {{ __('Create invoice here.') }} <a href="{{ route('invoice.index') }}"><b>{{ __('Create invoice') }}</b></a>
                </div>
        </div>
        <div class="form-group col-md-12 mt-3" id="invoice-items-container" style="display:none;">
            <label class="form-label">{{ __('Select Items to Return') }}</label>
            <div id="invoice-items"></div>
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
        <div class="form-group col-md-12">
            <input type="checkbox" id="return_items" name="return_items" value="1">
            {{ Form::label('return_items', __('Return Items'),['class'=>'form-label']) }}
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
        $('#invoice').on('change', function () {
            
            var invoiceId = $(this).val();
            if (invoiceId) {
                $.ajax({
                    url: "{{ route('invoice.get.items') }}", // route we’ll create next
                    type: "GET",
                    data: {invoice_id: invoiceId},
                    success: function (response) {
                        $('#invoice-items').html(response.html);
                        if($('#return_items').prop('checked') == true)
                        {
                            $('#invoice-items-container').show();
                        }
                    },
                    error: function () {
                        $('#invoice-items').html('<div class="text-danger">Failed to load items.</div>');
                    }
                });
            } else {
                $('#invoice-items-container').hide();
                $('#invoice-items').empty();
            }
        });
        $(document).on('input', '.returnQty', function () {
            let max = parseFloat($(this).attr('max'));
            let val = parseFloat($(this).val()) || 0;
            if (val > max) {
                $(this).val(max);
                val = max;
            }
        
            let total = 0;
            $('.returnQty').each(function () {
                let qty = parseFloat($(this).val()) || 0;
                let price = parseFloat($(this).data('price')) || 0;
                total += qty * price;
            });
        
            $('#amountInp').val(total.toFixed(2));
        });
        $('#return_items').on('change', function () {
            if($(this).prop('checked') == true)
            {
                $('#amountInp').val('');
                $('#amountInp').attr('readonly',true);
                $('#invoice-items-container').show();
            }
            else{
                $('#amountInp').val('');
                $('#amountInp').attr('readonly',false);
                $('#invoice-items-container').hide();
            }
        });

    });
</script>

