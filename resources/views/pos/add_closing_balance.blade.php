{{ Form::open(array('route' => array('pos.save_closing_balance'), 'method'=>'post', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    @if(isset($userOpenShift))
        <p class="text-muted small mb-2">{{ __('pos.closing_balance_saved_to_shift') }}</p>
        {{ Form::hidden('shift_id', $userOpenShift->id) }}
        @include('pos_shift.partials.close_count', [
            'closeShift' => $userOpenShift,
            'openingBalance' => $userOpenShift->opening_balance ?? 0,
            'salesTotal' => $salesTotal ?? 0,
            'expectedCash' => $expectedCash ?? 0,
            'amountField' => 'amount',
        ])
    @endif
    <div class="form-group mt-3 mb-0">
        {{ Form::label('tips', __('Tips'), ['class' => 'form-label']) }}
        {{ Form::number('tips', old('tips'), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => __('Tips')]) }}
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('pos.cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('pos.add') }}" class="btn btn-primary">
</div>
{{ Form::close() }}
