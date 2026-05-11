@extends('layouts.admin')
@section('page-title'){{ __('Edit Floor') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.floors.index') }}">{{ __('Floors') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($floor, ['route' => ['hotel.floors.update', $floor->id], 'method' => 'PUT']) !!}
    @include('hotelmanagement::floors.form')
    <button class="btn btn-primary">{{ __('Update') }}</button>
    <a class="btn btn-secondary" href="{{ route('hotel.floors.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
