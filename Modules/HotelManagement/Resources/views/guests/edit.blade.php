@extends('layouts.admin')
@section('page-title'){{ __('Edit guest') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.guests.index') }}">{{ __('Guests') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.guests.show', $guest->id) }}">{{ $guest->fullName() }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($guest, ['route' => ['hotel.guests.update', $guest->id], 'method' => 'PUT']) !!}
<div class="row g-2">
    <div class="col-md-4">{!! Form::label('first_name', __('First name')) !!}{!! Form::text('first_name', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4">{!! Form::label('last_name', __('Last name')) !!}{!! Form::text('last_name', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('company_name', __('Company')) !!}{!! Form::text('company_name', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('email', __('Email')) !!}{!! Form::email('email', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('phone', __('Phone')) !!}{!! Form::text('phone', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('birth_date', __('Birth date')) !!}{!! Form::date('birth_date', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-3">{!! Form::label('id_type', __('ID type')) !!}{!! Form::select('id_type', ['' => '—', 'id' => __('ID card'), 'passport' => __('Passport'), 'letter' => __('Letter')], null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-3">{!! Form::label('id_number', __('ID number')) !!}{!! Form::text('id_number', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-3">{!! Form::label('nationality', __('Nationality')) !!}{!! Form::select('nationality', ['' => '—'] + $nationalityOptions, null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-3">
        <div class="form-check mt-4">
            {!! Form::checkbox('is_vip', 1, null, ['class' => 'form-check-input', 'id' => 'is_vip']) !!}
            {!! Form::label('is_vip', __('VIP guest'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
    <div class="col-md-4">{!! Form::label('preferred_room_type_id', __('Preferred room type')) !!}{!! Form::select('preferred_room_type_id', ['' => '—'] + $roomTypes->toArray(), null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('preferred_hotel_floor_id', __('Preferred floor')) !!}{!! Form::select('preferred_hotel_floor_id', ['' => '—'] + $floors->toArray(), null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('preferred_payment_method', __('Preferred payment')) !!}{!! Form::text('preferred_payment_method', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('credit_limit', __('Credit limit')) !!}{!! Form::number('credit_limit', null, ['class' => 'form-control', 'step' => '0.01', 'min' => 0]) !!}</div>
    <div class="col-md-8">{!! Form::label('allergies', __('Allergies')) !!}{!! Form::text('allergies', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-6">{!! Form::label('preferences', __('Preferences')) !!}{!! Form::textarea('preferences', null, ['class' => 'form-control', 'rows' => 2]) !!}</div>
    <div class="col-md-6">{!! Form::label('drink_preferences', __('Drink preferences')) !!}{!! Form::textarea('drink_preferences', null, ['class' => 'form-control', 'rows' => 2]) !!}</div>
    <div class="col-12">{!! Form::label('notes', __('Notes')) !!}{!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 2]) !!}</div>
    @if(!$guest->customer_id && ($guest->email || $guest->phone))
    <div class="col-12">
        <div class="form-check">
            {!! Form::checkbox('sync_crm', 1, true, ['class' => 'form-check-input', 'id' => 'sync_crm']) !!}
            {!! Form::label('sync_crm', __('Link to CRM customer on save'), ['class' => 'form-check-label']) !!}
        </div>
    </div>
    @endif
</div>
<div class="mt-3">
    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
    <a href="{{ route('hotel.guests.show', $guest->id) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
</div>
{!! Form::close() !!}
</div></div>
@endsection
