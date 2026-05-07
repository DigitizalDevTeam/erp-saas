@extends('layouts.admin')
@section('page-title'){{ __('Edit Room') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($room, ['route' => ['hotel.rooms.update', $room->id], 'method' => 'PUT']) !!}
<div class="row">
    <div class="col-md-4 form-group">{!! Form::label('room_no', __('Room No')) !!}{!! Form::text('room_no', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('hotel_room_type_id', __('Room Type')) !!}{!! Form::select('hotel_room_type_id', $roomTypes, $room->hotel_room_type_id, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('status', __('Status')) !!}{!! Form::select('status', ['available' => __('Available'), 'occupied' => __('Occupied'), 'cleaning' => __('Cleaning'), 'maintenance' => __('Maintenance')], $room->status, ['class' => 'form-control']) !!}</div>
    <div class="col-md-6 form-group">{!! Form::label('override_price', __('Override Price')) !!}{!! Form::number('override_price', $room->override_price, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
    <div class="col-md-6 form-group">{!! Form::label('notes', __('Notes')) !!}{!! Form::text('notes', $room->notes, ['class' => 'form-control']) !!}</div>
</div>
<button class="btn btn-primary">{{ __('Update') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.rooms.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
