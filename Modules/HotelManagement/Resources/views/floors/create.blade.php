@extends('layouts.admin')
@section('page-title'){{ __('Create Floor') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.floors.index') }}">{{ __('Floors') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::open(['route' => 'hotel.floors.store']) !!}
    @include('hotelmanagement::floors.form')
    <button class="btn btn-primary">{{ __('Save') }}</button>
    <a class="btn btn-secondary" href="{{ route('hotel.floors.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
