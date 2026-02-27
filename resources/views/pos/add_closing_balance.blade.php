{{ Form::open(array('route' => array('pos.save_closing_balance'), 'method'=>'post', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    @if(isset($userOpenShift))
        <p class="text-muted small mb-2">{{ __('pos.closing_balance_saved_to_shift') }}</p>
        @if(isset($expectedCash))
            <p class="small mb-2"><strong>{{ __('Expected cash in drawer') }}:</strong> {{ Auth::user()->priceFormat($expectedCash) }}@if(isset($cashFromBalance) && $cashFromBalance > 0) <span class="text-muted">({{ __('incl. cash from balance payments') }}: {{ Auth::user()->priceFormat($cashFromBalance) }})</span>@endif</p>
        @endif
        {{ Form::hidden('shift_id', $userOpenShift->id) }}
    @endif
    <div class="row">
        <div class="form-group col-12 mb-2">
            {{ Form::label('amount', __('pos.amount'), ['class' => 'form-label']) }} <x-required></x-required>
            {{ Form::number('amount', isset($expectedCash) ? $expectedCash : null, ['class' => 'form-control', 'required' => 'required', 'step' => '0.01', 'placeholder' => __('pos.enter_amount')]) }}
        </div>
        <div class="form-group col-12 mb-2">
            {{ Form::label('tips', __('Tips'), ['class' => 'form-label']) }}
            {{ Form::number('tips', null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => __('Tips')]) }}
        </div>
        <div class="form-group col-12 mb-2">
            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
            {{ Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 2, 'placeholder' => __('Notes')]) }}
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('pos.cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('pos.add') }}" class="btn  btn-primary">
</div>
{{ Form::close() }}
