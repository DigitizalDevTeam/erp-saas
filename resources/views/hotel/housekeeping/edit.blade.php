@extends('layouts.admin')
@section('page-title'){{ __('Edit Housekeeping Task') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.housekeeping.index') }}">{{ __('Housekeeping') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::model($housekeeping, ['route' => ['hotel.housekeeping.update', $housekeeping->id], 'method' => 'PUT']) !!}
@include('hotel.housekeeping.form')
<button class="btn btn-primary">{{ __('Update') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.housekeeping.index') }}">{{ __('Cancel') }}</a>
{!! Form::close() !!}
</div></div>
@endsection
