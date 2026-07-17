@extends('layouts.admin')
@section('page-title'){{ __('Add hotel expense') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.expenses.index') }}">{{ __('Hotel expenses') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
@include('hotelmanagement::expenses._petty_cash_banner')
{!! Form::open(['route' => 'hotel.expenses.store', 'method' => 'POST']) !!}
<div class="card shadow-sm border-0">
    <div class="card-body">
        @include('hotelmanagement::expenses._form')
        <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
        <a href="{{ route('hotel.expenses.index', ['category' => $category ?? 'laundry']) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
    </div>
</div>
{!! Form::close() !!}
@endsection
