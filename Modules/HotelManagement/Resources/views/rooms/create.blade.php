@extends('layouts.admin')
@section('page-title'){{ __('Create Room') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::open(['route' => 'hotel.rooms.store']) !!}
<div class="row">
    <div class="col-md-3 form-group">{!! Form::label('room_no', __('Room / Apartment No')) !!}{!! Form::text('room_no', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('hotel_room_type_id', __('Room Type')) !!}{!! Form::select('hotel_room_type_id', $roomTypes, null, ['class' => 'form-control select2', 'id' => 'create_room_type_id', 'required']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('hotel_floor_id', __('Floor')) !!}{!! Form::select('hotel_floor_id', ['' => __('Select floor')] + $floors->toArray(), null, ['class' => 'form-control select2', 'id' => 'create_hotel_floor_id']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('status', __('Status')) !!}{!! Form::select('status', ['available' => __('Available'), 'occupied' => __('Occupied'), 'cleaning' => __('Cleaning'), 'maintenance' => __('Maintenance')], 'available', ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('override_price', __('Nightly Override Price')) !!}{!! Form::number('override_price', null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('monthly_override_price', __('Monthly Override Price')) !!}{!! Form::number('monthly_override_price', null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('notes', __('Notes')) !!}{!! Form::text('notes', null, ['class' => 'form-control']) !!}</div>
</div>
<button class="btn btn-primary">{{ __('Save') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.rooms.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
