@extends('layouts.admin')
@section('page-title'){{ __('Create Room Type') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.room-types.index') }}">{{ __('Room Types') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::open(['route' => 'hotel.room-types.store']) !!}
@include('hotelmanagement::room_types.form', ['roomType' => null])
<button class="btn btn-primary">{{ __('Save') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.room-types.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
