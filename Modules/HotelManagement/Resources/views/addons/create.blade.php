@extends('layouts.admin')
@section('page-title'){{ __('Add addon') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.addons.index') }}">{{ __('Addons & extras') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
{!! Form::open(['route' => 'hotel.addons.store', 'method' => 'POST']) !!}
<div class="card"><div class="card-body">
@include('hotelmanagement::addons.form')
<button class="btn btn-primary">{{ __('Create') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.addons.index') }}">{{ __('Cancel') }}</a>
</div></div>
{!! Form::close() !!}
@endsection
