<div class="row">
    <div class="col-md-6 form-group">
        {!! Form::label('hotel_room_type_id', __('Room Type')) !!}
        {!! Form::select('hotel_room_type_id', $roomTypes, $ratePlan->hotel_room_type_id ?? null, ['class' => 'form-control select2', 'id' => 'hotel_rate_plan_room_type_id', 'required']) !!}
    </div>
    <div class="col-md-6 form-group">
        {!! Form::label('name', __('Plan name')) !!}
        {!! Form::text('name', $ratePlan->name ?? null, ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('rate', __('Nightly rate')) !!}
        {!! Form::number('rate', $ratePlan->rate ?? null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('weekend_rate', __('Weekend / promo nightly rate')) !!}
        {!! Form::number('weekend_rate', $ratePlan->weekend_rate ?? null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => __('Optional — Fri, Sat, Sun')]) !!}
        <small class="text-muted">{{ __('Leave empty to use the nightly rate every day. Set a lower value for weekend promotions.') }}</small>
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('monthly_rate', __('Monthly rate')) !!}
        {!! Form::number('monthly_rate', $ratePlan->monthly_rate ?? null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('billing_mode', __('Billing mode')) !!}
        {!! Form::select('billing_mode', ['nightly' => __('Nightly'), 'monthly' => __('Monthly'), 'both' => __('Both')], $ratePlan->billing_mode ?? 'nightly', ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('priority', __('Priority')) !!}
        {!! Form::number('priority', $ratePlan->priority ?? 100, ['class' => 'form-control', 'min' => 0]) !!}
        <small class="text-muted">{{ __('Lower number = higher priority when periods overlap.') }}</small>
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('valid_from', __('Valid from')) !!}
        {!! Form::date('valid_from', isset($ratePlan) && $ratePlan->valid_from ? \Carbon\Carbon::parse($ratePlan->valid_from)->toDateString() : null, ['class' => 'form-control']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('valid_to', __('Valid to')) !!}
        {!! Form::date('valid_to', isset($ratePlan) && $ratePlan->valid_to ? \Carbon\Carbon::parse($ratePlan->valid_to)->toDateString() : null, ['class' => 'form-control']) !!}
    </div>
    <div class="col-md-12 form-group">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, isset($ratePlan) ? (bool) $ratePlan->is_active : true, ['class' => 'form-check-input', 'id' => 'hotel_rate_plan_is_active']) !!}
            {!! Form::label('hotel_rate_plan_is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>
