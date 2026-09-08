@extends('layouts.admin')
@section('page-title'){{ __('Edit Price Period') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rate-plans.index') }}">{{ __('Price per Period') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($ratePlan, ['route' => ['hotel.rate-plans.update', $ratePlan->id], 'method' => 'PUT']) !!}
@include('hotelmanagement::rate_plans.form')
<button class="btn btn-primary">{{ __('Update') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.rate-plans.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
