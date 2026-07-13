@extends('layouts.admin')
@section('page-title'){{ __('Edit Reservation') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reservations.index') }}">{{ __('Reservations') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
@if($reservation->status === 'checked_out')
<div class="alert alert-warning">{{ __('This reservation is checked out and locked. Only an administrator can correct guest/document details.') }}</div>
@elseif($reservation->status === 'checked_in')
<div class="alert alert-info">{{ __('Guest is checked in. Room changes, extend stay and payments are done from Frontdesk. Booking channel and payment history stay locked.') }}</div>
@endif
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($reservation, ['route' => ['hotel.reservations.update', $reservation->id], 'method' => 'PUT']) !!}
@include('hotelmanagement::reservations.form')
@if($reservation->status === 'checked_in')
<a href="{{ route('hotel.frontdesk.checkout', $reservation->id) }}" class="btn btn-warning">{{ __('Open Frontdesk') }}</a>
@endif
<button class="btn btn-primary">{{ __('Update') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.reservations.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
