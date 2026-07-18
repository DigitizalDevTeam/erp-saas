@extends('layouts.admin')
@section('page-title')
    {{ __('Fiscal year closing') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Double Entry') }}</li>
    <li class="breadcrumb-item">{{ __('Fiscal year closing') }}</li>
@endsection

@section('content')
    @include('fiscalYearClosing.partials.tabs_shell')
@endsection
