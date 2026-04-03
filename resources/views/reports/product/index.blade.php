@extends('layouts.admin')
@section('page-title')
    {{ __('Product system reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Product system reports') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Store check') }}</h5>
                    <p class="text-muted small">{{ __('Current stock per product (global quantity).') }}</p>
                    <a href="{{ route('reports.product.store-check') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Warehouse stock') }}</h5>
                    <p class="text-muted small">{{ __('Quantity by warehouse with row / shelf from the product card.') }}</p>
                    <a href="{{ route('reports.product.warehouse-stock') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Product transactions') }}</h5>
                    <p class="text-muted small">{{ __('Purchase, sale, POS, manual, loss, and warehouse transfers (IN / OUT).') }}</p>
                    <a href="{{ route('reports.product.transactions') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Last 5 supplies') }}</h5>
                    <p class="text-muted small">{{ __('Select a product to see recent purchases: supplier, unit price, date.') }}</p>
                    <a href="{{ route('reports.product.last-supplies') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Row & column check') }}</h5>
                    <p class="text-muted small">{{ __('Products with row/shelf set, grouped by location.') }}</p>
                    <a href="{{ route('reports.product.location-check') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 border-0 bg-light">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Classic product stock log') }}</h5>
                    <p class="text-muted small">{{ __('Historical stock report lines (existing screen).') }}</p>
                    <a href="{{ route('report.product.stock.report') }}" class="btn btn-outline-secondary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
    </div>
@endsection
