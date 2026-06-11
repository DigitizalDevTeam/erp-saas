@extends('layouts.admin')
@section('page-title'){{ __('Add minibar item') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.minibar-items.index') }}">{{ __('Minibar catalog') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
{!! Form::open(['route' => 'hotel.minibar-items.store', 'method' => 'POST']) !!}
<div class="card"><div class="card-body">
@include('hotelmanagement::minibar_items.form', ['item' => new \Modules\HotelManagement\Entities\HotelMinibarItem()])
<button class="btn btn-primary">{{ __('Create') }}</button>
<a class="btn btn-secondary" href="{{ route('hotel.minibar-items.index') }}">{{ __('Cancel') }}</a>
</div></div>
{!! Form::close() !!}
@endsection
