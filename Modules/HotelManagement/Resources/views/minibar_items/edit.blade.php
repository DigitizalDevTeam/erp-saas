@extends('layouts.admin')
@section('page-title'){{ __('Edit minibar item') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.minibar-items.index') }}">{{ __('Minibar catalog') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
{!! Form::model($item, ['route' => ['hotel.minibar-items.update', $item->id], 'method' => 'PUT']) !!}
<div class="card"><div class="card-body">
@include('hotelmanagement::minibar_items.form', ['item' => $item])
<button class="btn btn-primary">{{ __('Save') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.minibar-items.index') }}">{{ __('Cancel') }}</a>
</div></div>
{!! Form::close() !!}
@endsection
