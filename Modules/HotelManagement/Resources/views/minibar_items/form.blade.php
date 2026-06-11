<div class="row g-2">
    <div class="col-md-6">{!! Form::label('name', __('Name')) !!}{!! Form::text('name', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-3">{!! Form::label('unit_price', __('Price')) !!}{!! Form::number('unit_price', null, ['class' => 'form-control', 'step' => '0.01', 'min' => 0, 'required']) !!}</div>
    <div class="col-md-3">{!! Form::label('default_quantity', __('Default stock per room')) !!}{!! Form::number('default_quantity', $item->default_quantity ?? 1, ['class' => 'form-control', 'min' => 1, 'max' => 99]) !!}</div>
    <div class="col-12">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, $item->is_active ?? true, ['class' => 'form-check-input', 'id' => 'is_active']) !!}
            {!! Form::label('is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>
