{{ Form::open(array('route' => array('pos.save_opening_balance'),'mothod'=>'post', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group">
            {{ Form::label('amount', __('pos.amount'), ['class' => 'form-label']) }} <x-required></x-required>
            {{ Form::number('amount', null, ['class' => 'form-control', 'required' => 'required', 'step' => '0.01', 'placeholder' => __('pos.enter_amount')]) }}
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('pos.cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('pos.add') }}" class="btn  btn-primary">
</div>
{{ Form::close() }}
