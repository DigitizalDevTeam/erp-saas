{{ Form::open(array('route' => array('invoice.credit.note',$invoice_id),'mothod'=>'post', 'class'=>'needs-validation', 'novalidate', 'files' => true)) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group  col-md-6">
            {{ Form::label('date', __('Date'),['class'=>'form-label']) }}<x-required></x-required>
            {{Form::date('date',null,array('class'=>'form-control','required'=>'required'))}}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('amount', __('Amount'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::number('amount', isset($maxCreditAmount) ? $maxCreditAmount : (!empty($invoiceDue)?$invoiceDue->getDue():0), array('class' => 'form-control','required'=>'required','step'=>'0.01' , 'placeholder'=>__('Enter Amount'))) }}

        </div>
        <div class="form-group col-md-12">
            {{ Form::label('disposition', __('What happens to the money?'),['class'=>'form-label']) }}<x-required></x-required>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="disposition" id="disposition_credit" value="credit" {{ !empty($paidAmount) && $paidAmount > 0 ? '' : 'checked' }} required>
                <label class="form-check-label" for="disposition_credit">{{ __('Keep as customer credit') }}</label>
            </div>
            @if(!empty($paidAmount) && $paidAmount > 0)
            <div class="form-check">
                <input class="form-check-input" type="radio" name="disposition" id="disposition_refund" value="refund" required>
                <label class="form-check-label" for="disposition_refund">{{ __('Refund the money') }}</label>
            </div>
            @endif
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('description', __('Reason'),['class'=>'form-label']) }}<x-required></x-required>
            {!! Form::textarea('description', '', ['class'=>'form-control','rows'=>'3' , 'placeholder'=>__('Enter the reason for this credit note'), 'required'=>'required']) !!}
            <div class="invalid-feedback">{{ __('Reason is required.') }}</div>
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('attachment', __('Supporting document (optional)'),['class'=>'form-label']) }}
            <input type="file" name="attachment" class="form-control">
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Add')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}
