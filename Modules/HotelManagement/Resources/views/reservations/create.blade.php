@extends('layouts.admin')
@section('page-title'){{ __('Create Reservation') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::open(['route' => 'hotel.reservations.store', 'id' => 'reservation_create_form']) !!}
@php($reservation = null)
@include('hotelmanagement::reservations.form')
<button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
<button type="submit" class="btn btn-success" formaction="{{ route('hotel.reservations.store-and-check-in') }}" formmethod="POST" id="btn_create_checkin">{{ __('Create & Check-in') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.reservations.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
@push('script-page')
<script>
document.getElementById('btn_create_checkin') && document.getElementById('btn_create_checkin').addEventListener('click', function (e) {
    var room = document.getElementById('hotel_room_id');
    if (!room || !room.value) {
        e.preventDefault();
        alert('{{ __("Select a room before Create & Check-in.") }}');
    }
});
</script>
@endpush
