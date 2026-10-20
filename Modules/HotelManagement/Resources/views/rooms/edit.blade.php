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
    <div class="col-md-3 form-group">{!! Form::label('room_no', __('Room / Apartment No')) !!}{!! Form::text('room_no', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('hotel_room_type_id', __('Room Type')) !!}{!! Form::select('hotel_room_type_id', $roomTypes, $room->hotel_room_type_id, ['class' => 'form-control select2', 'id' => 'edit_room_type_id', 'required']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('hotel_floor_id', __('Floor')) !!}{!! Form::select('hotel_floor_id', ['' => __('Select floor')] + $floors->toArray(), $room->hotel_floor_id, ['class' => 'form-control select2', 'id' => 'edit_hotel_floor_id']) !!}</div>
    <div class="col-md-3 form-group">{!! Form::label('status', __('Status')) !!}{!! Form::select('status', \Modules\HotelManagement\Entities\HotelRoom::statusOptions(), $room->status, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4 form-group">{!! Form::label('notes', __('Notes')) !!}{!! Form::text('notes', $room->notes, ['class' => 'form-control']) !!}</div>
</div>
<button class="btn btn-primary">{{ __('Update') }}</button>
@if($room->status !== 'occupied' && $room->status !== 'blocked')
    <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#blockRoomEditModal">{{ __('Block room') }}</button>
@endif
<a class="btn btn-secondary" href="{{ route('hotel.rooms.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}

@if($room->status === 'blocked')
{!! Form::open(['method' => 'POST', 'route' => ['hotel.rooms.unblock', $room->id], 'class' => 'd-inline']) !!}
<button type="submit" class="btn btn-success mt-2">{{ __('Unblock room') }}</button>
{!! Form::close() !!}
@elseif($room->status !== 'occupied')
<div class="modal fade" id="blockRoomEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {!! Form::open(['method' => 'POST', 'route' => ['hotel.rooms.block', $room->id]]) !!}
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Block room') }} {{ $room->room_no }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">{{ __('Blocked rooms cannot be assigned to reservations or used for check-in until unblocked.') }}</p>
                {!! Form::label('notes', __('Reason')) !!}
                {!! Form::textarea('notes', $room->notes, ['class' => 'form-control', 'rows' => 3]) !!}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-dark">{{ __('Block room') }}</button>
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>
@endif
</div></div>
@endsection
