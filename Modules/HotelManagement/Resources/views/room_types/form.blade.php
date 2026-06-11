<div class="row">
    <div class="col-md-4 form-group">
        {!! Form::label('name', __('Name')) !!}
        {!! Form::text('name', $roomType->name ?? null, ['class' => 'form-control', 'required']) !!}
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
        {!! Form::label('nightly_rate', __('Nightly Rate')) !!}
        {!! Form::number('nightly_rate', $roomType->nightly_rate ?? $roomType->base_price ?? 0, ['class' => 'form-control', 'step' => '0.01', 'min' => 0, 'required']) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('monthly_rate', __('Monthly Rate')) !!}
        {!! Form::number('monthly_rate', $roomType->monthly_rate ?? 0, ['class' => 'form-control', 'step' => '0.01', 'min' => 0]) !!}
    </div>
    <div class="col-md-4 form-group">
        {!! Form::label('default_billing_mode', __('Default Billing Mode')) !!}
        {!! Form::select('default_billing_mode', ['nightly' => __('Nightly'), 'monthly' => __('Monthly')], $roomType->default_billing_mode ?? 'nightly', ['class' => 'form-control', 'required']) !!}
    </div>
    <div class="col-md-12 form-group">
        {!! Form::label('description', __('Description')) !!}
        {!! Form::textarea('description', $roomType->description ?? null, ['class' => 'form-control', 'rows' => 3]) !!}
    </div>
    <div class="col-md-6 form-group">
        <label class="form-label">{{ __('Cover image (booking website)') }}</label>
        <input type="file" name="cover" class="form-control" accept="image/*">
        @if(!empty($roomType?->coverImageUrl()))
            <img src="{{ $roomType->coverImageUrl() }}" alt="" class="mt-2 rounded" style="max-height:120px;">
        @endif
        <small class="text-muted">{{ __('Shown on the public booking site room cards.') }}</small>
    </div>
    <div class="col-md-6 form-group">
        <label class="form-label">{{ __('Gallery images') }}</label>
        <input type="file" name="gallery[]" class="form-control" accept="image/*" multiple>
        @if(!empty($roomType?->galleryImageUrls()))
            <div class="d-flex flex-wrap gap-2 mt-2">
                @foreach($roomType->galleryImageUrls() as $url)
                    <img src="{{ $url }}" alt="" class="rounded" style="height:64px;width:64px;object-fit:cover;">
                @endforeach
            </div>
        @endif
    </div>
    <div class="col-md-12 form-group">
        <div class="form-check">
            {!! Form::checkbox('is_active', 1, isset($roomType) ? (bool)$roomType->is_active : true, ['class' => 'form-check-input', 'id' => 'is_active']) !!}
            {!! Form::label('is_active', __('Active'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
</div>
