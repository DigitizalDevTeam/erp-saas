<div class="row">
    <div class="col-md-4 form-group">
        {!! Form::label('name', __('Name')) !!}
        {!! Form::text('name', $roomType->name ?? null, ['class' => 'form-control', 'required']) !!}
        <small class="text-muted">{{ __('Category shown on the booking website (e.g. Standard, Deluxe, Suite).') }}</small>
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('unit_category', __('Unit Category')) !!}
        {!! Form::select('unit_category', ['room' => __('Room'), 'apartment' => __('Apartment')], $roomType->unit_category ?? 'room', ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('max_occupancy', __('Max Occupancy')) !!}
        {!! Form::number('max_occupancy', $roomType->max_occupancy ?? 2, ['class' => 'form-control', 'min' => 1, 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('default_billing_mode', __('Default Billing Mode')) !!}
        {!! Form::select('default_billing_mode', ['nightly' => __('Nightly'), 'monthly' => __('Monthly')], $roomType->default_billing_mode ?? 'nightly', ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-12 form-group">
        {!! Form::label('description', __('Description')) !!}
        {!! Form::textarea('description', $roomType->description ?? null, ['class' => 'form-control', 'rows' => 3]) !!}
    </div>

    <div class="col-12"><hr><h6 class="text-uppercase text-muted small fw-bold mb-3">{{ __('Booking website photos (up to 3 slides)') }}</h6></div>

    <div class="col-md-4 form-group">
        <label class="form-label">{{ __('Feature image') }} <span class="text-muted">({{ __('Slide 1') }})</span></label>
        <input type="file" name="cover" class="form-control" accept="image/*">
        @if(!empty($roomType?->coverImageUrl()))
            <div class="mt-2">
                <img src="{{ $roomType->coverImageUrl() }}" alt="" class="rounded border" style="width:100%;max-height:140px;object-fit:cover;">
                @if(!empty($roomType?->id))
                    <label class="form-check mt-2 small"><input type="checkbox" name="remove_cover" value="1" class="form-check-input"> {{ __('Remove feature image') }}</label>
                @endif
            </div>
        @endif
    </div>

    <div class="col-md-4 form-group">
        <label class="form-label">{{ __('Slide 2 image') }}</label>
        <input type="file" name="slide_2" class="form-control" accept="image/*">
        @if(!empty($roomType?->slideTwoUrl()))
            <div class="mt-2">
                <img src="{{ $roomType->slideTwoUrl() }}" alt="" class="rounded border" style="width:100%;max-height:140px;object-fit:cover;">
                <label class="form-check mt-2 small"><input type="checkbox" name="remove_slide_2" value="1" class="form-check-input"> {{ __('Remove slide 2') }}</label>
            </div>
        @endif
    </div>

    <div class="col-md-4 form-group">
        <label class="form-label">{{ __('Slide 3 image') }}</label>
        <input type="file" name="slide_3" class="form-control" accept="image/*">
        @if(!empty($roomType?->slideThreeUrl()))
            <div class="mt-2">
                <img src="{{ $roomType->slideThreeUrl() }}" alt="" class="rounded border" style="width:100%;max-height:140px;object-fit:cover;">
                <label class="form-check mt-2 small"><input type="checkbox" name="remove_slide_3" value="1" class="form-check-input"> {{ __('Remove slide 3') }}</label>
            </div>
        @endif
    </div>

    <div class="col-12">
        <p class="small text-muted mb-0">{{ __('Guests see these photos as a slider on room cards on your public booking website.') }}</p>
    </div>

    <div class="col-md-12 form-group mt-2">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, isset($roomType) ? (bool)$roomType->is_active : true, ['class' => 'form-check-input', 'id' => 'is_active']) !!}
            {!! Form::label('is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>
