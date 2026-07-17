@extends('layouts.admin')
@section('page-title'){{ __('Edit hotel expense') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.expenses.index') }}">{{ __('Hotel expenses') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit') }}</li>
@endsection
@section('content')
@include('hotelmanagement::expenses._petty_cash_banner')
{!! Form::model($expense, ['route' => ['hotel.expenses.update', $expense->id], 'method' => 'PUT']) !!}
<div class="card shadow-sm border-0">
    <div class="card-body">
        @include('hotelmanagement::expenses._form', ['expense' => $expense])
        <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
        <a href="{{ route('hotel.expenses.index', ['category' => $expense->category]) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
    </div>
</div>
{!! Form::close() !!}
@endsection
