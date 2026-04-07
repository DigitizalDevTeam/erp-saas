{{ Form::open(array('url' => 'product-unit', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group col-md-12">
            {{ Form::label('name', __('Unit Name'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('name', '', array('class' => 'form-control','required'=>'required' , 'placeholder'=>__('Enter Unit Name'))) }}
            @error('name')
                <small class="invalid-name" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </small>
            @enderror
        </div>

        <div class="form-group col-md-12">
            <label class="form-label">{{ __('Dimensions mode') }}</label>
            <select name="dimension_mode" class="form-control select">
                <option value="">{{ __('None') }}</option>
                <option value="area_sqm">{{ __('Area (SQM/SM) – Width × Length') }}</option>
                <option value="volume_cbm">{{ __('Volume (CBM) – L × W × H') }}</option>
                <option value="volumetric_weight">{{ __('Volumetric weight – (L×W×H)/divisor') }}</option>
            </select>
            <small class="text-muted">{{ __('Controls which inputs appear on Product form based on this unit.') }}</small>
        </div>

        <div class="form-group col-md-6">
            <label class="form-label">{{ __('Length unit') }}</label>
            <select name="length_unit" class="form-control select">
                <option value="cm">{{ __('cm') }}</option>
                <option value="m">{{ __('m') }}</option>
            </select>
        </div>

        <div class="form-group col-md-6">
            <label class="form-label">{{ __('Volumetric divisor') }}</label>
            <select name="volumetric_divisor" class="form-control select">
                <option value="">{{ __('—') }}</option>
                <option value="4000">4000</option>
                <option value="5000">5000</option>
                <option value="6000">6000</option>
            </select>
            <small class="text-muted">{{ __('Used only for volumetric weight units.') }}</small>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}
