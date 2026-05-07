@extends('layouts.admin')
@section('page-title'){{ __('OTA settings') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('OTA') }}</li>
@endsection
@section('content')
<div class="alert alert-info">
    {{ __('Run database migrations to create `hotel_ota_settings`, then return to this screen.') }}
</div>
@endsection
