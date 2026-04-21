<div class="row">
    <div class="col-md-4 form-group">
        {!! Form::label('name', __('Name')) !!}
        {!! Form::text('name', $roomType->name ?? null, ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('base_price', __('Base Price')) !!}
        {!! Form::number('base_price', $roomType->base_price ?? 0, ['class' => 'form-control', 'step' => '0.01']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('max_occupancy', __('Max Occupancy')) !!}
        {!! Form::number('max_occupancy', $roomType->max_occupancy ?? 2, ['class' => 'form-control', 'min' => 1, 'required']) !!}
    </div>
    <div class="col-md-12 form-group">
        {!! Form::label('description', __('Description')) !!}
        {!! Form::text('description', $roomType->description ?? null, ['class' => 'form-control']) !!}
    </div>
    <div class="col-md-12 form-group">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, isset($roomType) ? (bool)$roomType->is_active : true, ['class' => 'form-check-input', 'id' => 'is_active']) !!}
            {!! Form::label('is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>
