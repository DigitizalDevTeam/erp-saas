<div class="row">
    <div class="col-md-4 form-group">
        {!! Form::label('name', __('Floor name')) !!}
        {!! Form::text('name', $floor->name ?? null, ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('level_number', __('Level number')) !!}
        {!! Form::number('level_number', $floor->level_number ?? null, ['class' => 'form-control']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('sort_order', __('Sort order')) !!}
        {!! Form::number('sort_order', $floor->sort_order ?? 0, ['class' => 'form-control', 'min' => 0]) !!}
    </div>
    <div class="col-md-12 form-group">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, isset($floor) ? (bool) $floor->is_active : true, ['class' => 'form-check-input', 'id' => 'hotel_floor_is_active']) !!}
            {!! Form::label('hotel_floor_is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>
