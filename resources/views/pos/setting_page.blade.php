@extends('layouts.admin')
@section('page-title')
    {{ __('pos.barcode_setting') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.print.setting') }}">{{ __('Print Settings') }}</a></li>
    <li class="breadcrumb-item">{{ __('pos.barcode_setting') }}</li>
@endsection
@section('action-btn')
    <div class="float-end">
        <a href="{{ route('pos.print.setting') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-printer me-1"></i>{{ __('Print') }} {{ __('Setting') }}
        </a>
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-md-8 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('pos.barcode_setting') }}</h5>
                </div>
                <div class="card-body">
                    @include('pos.setting')
                </div>
            </div>
            <p class="text-muted small">
                {{ __('For Slip Layout, UTHARA Factura A4/A5 and 80mm Talão, open Print Settings.') }}
                <a href="{{ route('pos.print.setting') }}">{{ __('Print Settings') }}</a>
            </p>
        </div>
    </div>
@endsection
