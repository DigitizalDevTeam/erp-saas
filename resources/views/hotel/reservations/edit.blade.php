@extends('layouts.admin')
@section('page-title'){{ __('Edit Reservation') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($reservation, ['route' => ['hotel.reservations.update', $reservation->id], 'method' => 'PUT']) !!}
@include('hotel.reservations.form')
<button class="btn btn-primary">{{ __('Update') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.reservations.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
