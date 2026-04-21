@extends('layouts.admin')
@section('page-title'){{ __('Edit Room Type') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.room-types.index') }}">{{ __('Room Types') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($roomType, ['route' => ['hotel.room-types.update', $roomType->id], 'method' => 'PUT']) !!}
@include('hotel.room_types.form', ['roomType' => $roomType])
<button class="btn btn-primary">{{ __('Update') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.room-types.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
