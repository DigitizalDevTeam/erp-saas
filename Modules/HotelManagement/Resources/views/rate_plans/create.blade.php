@extends('layouts.admin')
@section('page-title'){{ __('Create Price Period') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rate-plans.index') }}">{{ __('Price per Period') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
<div class="alert alert-info mb-3">
    {{ __('Plan different rates for date ranges. Lower priority wins when plans overlap. Optional weekend rate applies Fri–Sun nights (promo / discounted weekends).') }}
</div>
<div class="card shadow-sm"><div class="card-body">
{!! Form::open(['route' => 'hotel.rate-plans.store']) !!}
@include('hotelmanagement::rate_plans.form')
<button class="btn btn-primary">{{ __('Save') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.rate-plans.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
