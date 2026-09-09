@extends('layouts.admin')
@section('page-title')
    {{ __('Restaurant Reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Restaurant Reports') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-4">{{ __('Restaurant Reports') }}</h5>
                    <div class="row g-3">
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.pos.shift') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-clock-hour-4 me-2"></i> {{ __('pos.shift_report') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.pos.day-summary') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-calendar me-2"></i> {{ __('Daily Sales / Day Summary') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.pos.eod') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-report me-2"></i> {{ __('End of Day (EOD)') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.sales-by-category') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-report me-2"></i> {{ __('Sales by Categories') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.sales-by-product') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-package me-2"></i> {{ __('Sales by Product') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.sales-by-waiter') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-user me-2"></i> {{ __('Sales by Waiter') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.cancellations') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-x me-2"></i> {{ __('Cancellations') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.discounts') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-discount me-2"></i> {{ __('Discounts Applied') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.best-selling') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-trending-up me-2"></i> {{ __('Best Selling Items') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.gross-profit') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-currency-dollar me-2"></i> {{ __('Gross Profit') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.pos.low-stock') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-box me-2"></i> {{ __('Minimum Stock Alert') }}
                            </a>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <a href="{{ route('report.restaurant.cash-discrepancies') }}" class="btn btn-outline-primary w-100 text-start d-flex align-items-center">
                                <i class="ti ti-cash me-2"></i> {{ __('Cash Discrepancies') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
