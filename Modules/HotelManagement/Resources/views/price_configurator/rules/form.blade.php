@extends('layouts.admin')
@section('page-title'){{ $rule->exists ? __('Edit price rule') : __('Add price rule') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.price-configurator.index', ['tab' => 'occupancy']) }}">{{ __('Price Configurator') }}</a></li>
    <li class="breadcrumb-item">{{ $rule->exists ? __('Edit price rule') : __('Add price rule') }}</li>
@endsection
@section('content')
@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
@if($rule->exists)
    {!! Form::model($rule, ['route' => ['hotel.price-rules.update', $rule], 'method' => 'PUT']) !!}
@else
    {!! Form::model($rule, ['route' => 'hotel.price-rules.store', 'method' => 'POST']) !!}
@endif
<div class="card shadow-sm">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-6">
                {!! Form::label('name', __('Rule name')) !!}
                {!! Form::text('name', null, ['class' => 'form-control', 'required' => true]) !!}
            </div>
            <div class="col-md-3">
                {!! Form::label('rule_type', __('Rule kind')) !!}
                {!! Form::select('rule_type', \Modules\HotelManagement\Entities\HotelPriceRule::ruleTypeOptions(), null, ['class' => 'form-control', 'id' => 'rule_type']) !!}
            </div>
            <div class="col-md-3">
                {!! Form::label('hotel_room_type_id', __('Room type')) !!}
                <select name="hotel_room_type_id" id="hotel_room_type_id" class="form-control">
                    <option value="">{{ __('All room types') }}</option>
                    @foreach($roomTypes as $roomType)
                        <option value="{{ $roomType->id }}" {{ (int) old('hotel_room_type_id', $rule->hotel_room_type_id) === (int) $roomType->id ? 'selected' : '' }}>{{ $roomType->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3" data-rule-field="addon">
                {!! Form::label('hotel_addon_id', __('Extra item (addon)')) !!}
                <select name="hotel_addon_id" id="hotel_addon_id" class="form-control">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach($addons as $addon)
                        <option value="{{ $addon->id }}" {{ (int) old('hotel_addon_id', $rule->hotel_addon_id) === (int) $addon->id ? 'selected' : '' }}>{{ $addon->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2" data-rule-field="child">
                {!! Form::label('adults_min', __('Adults from')) !!}
                {!! Form::number('adults_min', null, ['class' => 'form-control', 'min' => 0, 'max' => 50, 'placeholder' => __('Any')]) !!}
            </div>
            <div class="col-md-2" data-rule-field="child">
                {!! Form::label('adults_max', __('Adults to')) !!}
                {!! Form::number('adults_max', null, ['class' => 'form-control', 'min' => 0, 'max' => 50, 'placeholder' => __('Any')]) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('qty_from', __('Quantity from')) !!}
                {!! Form::number('qty_from', null, ['class' => 'form-control', 'min' => 1, 'max' => 999, 'required' => true]) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('qty_to', __('Quantity to')) !!}
                {!! Form::number('qty_to', null, ['class' => 'form-control', 'min' => 1, 'max' => 999, 'placeholder' => __('And above')]) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('price', __('Price')) !!}
                {!! Form::number('price', null, ['class' => 'form-control', 'min' => 0, 'step' => '0.01', 'required' => true]) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('price_basis', __('Price per')) !!}
                {!! Form::select('price_basis', \Modules\HotelManagement\Entities\HotelPriceRule::basisOptions(), null, ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('valid_from', __('Valid from')) !!}
                {!! Form::date('valid_from', $rule->valid_from?->format('Y-m-d'), ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('valid_to', __('Valid to')) !!}
                {!! Form::date('valid_to', $rule->valid_to?->format('Y-m-d'), ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('priority', __('Priority (lower wins)')) !!}
                {!! Form::number('priority', null, ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" {{ old('is_active', $rule->is_active) ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_active">{{ __('Active') }}</label>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <button type="submit" class="btn btn-primary">{{ __('Save rule') }}</button>
            <a class="btn btn-light" href="{{ route('hotel.price-configurator.index', ['tab' => 'occupancy']) }}">{{ __('Cancel') }}</a>
        </div>
    </div>
</div>
{!! Form::close() !!}
<script>
    (function () {
        var typeSelect = document.getElementById('rule_type');
        function syncFields() {
            var type = typeSelect.value;
            document.querySelectorAll('[data-rule-field]').forEach(function (el) {
                el.style.display = el.getAttribute('data-rule-field') === type ? '' : 'none';
            });
        }
        typeSelect.addEventListener('change', syncFields);
        syncFields();
    })();
</script>
@endsection
