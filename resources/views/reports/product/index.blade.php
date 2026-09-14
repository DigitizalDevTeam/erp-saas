@extends('layouts.admin')
@section('page-title')
    {{ __('Product system reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Product system reports') }}</li>
@endsection

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-1">{{ __('Stock overview') }}</h4>
            <p class="text-muted small mb-3">{{ __('Quick status of stock, alerts, and pending actions.') }}</p>
        </div>
    </div>

    <div class="row mb-4">
        @foreach ($kpis as $key => $kpi)
            @php
                $available = !empty($kpi['available']);
                $label = $kpi['label'] ?? $key;
                $display = $kpi['display'] ?? 'count';
                $routeName = $kpi['route'] ?? null;
                $canLink = $available && $routeName && \Illuminate\Support\Facades\Route::has($routeName);
            @endphp
            <div class="col-md-6 col-lg-4 col-xl-3 mb-3">
                @if ($canLink)
                    <a href="{{ route($routeName) }}" class="text-decoration-none text-reset d-block h-100">
                @endif
                <div class="card h-100 {{ $available ? '' : 'bg-light border-0' }} {{ $canLink ? 'border-primary border-opacity-25' : '' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h6 class="card-title mb-2 text-muted">{{ $label }}</h6>
                            @if ($available && $canLink)
                                <span class="small text-primary">{{ __('View') }}</span>
                            @endif
                        </div>

                        @if (!$available)
                            <div class="fs-3 fw-semibold text-secondary">{{ __('N/A') }}</div>
                            <p class="mb-0 small text-muted">{{ $kpi['hint'] ?? __('Workflow not available yet') }}</p>
                        @elseif ($display === 'warehouse_list')
                            @php $warehouses = $kpi['warehouses'] ?? []; @endphp
                            <div class="fs-4 fw-semibold mb-2">{{ count($warehouses) }} <span class="fs-6 fw-normal text-muted">{{ __('warehouses') }}</span></div>
                            @if (count($warehouses) === 0)
                                <p class="mb-0 small text-muted">{{ __('No warehouse stock recorded.') }}</p>
                            @else
                                <ul class="list-unstyled mb-0 small" style="max-height: 9rem; overflow-y: auto;">
                                    @foreach ($warehouses as $wh)
                                        <li class="d-flex justify-content-between gap-2 py-1 border-bottom border-light">
                                            <span class="text-truncate">{{ $wh['name'] }}</span>
                                            <span class="text-nowrap text-muted">
                                                {{ number_format((float) $wh['qty'], 2) }}
                                                · {{ Auth::user()->priceFormat($wh['value'] ?? 0) }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @elseif ($display === 'currency')
                            <div class="fs-3 fw-semibold">{{ Auth::user()->priceFormat($kpi['value'] ?? 0) }}</div>
                            <p class="mb-0 small text-muted">{{ __('Purchase value (ex VAT)') }}</p>
                        @else
                            <div class="fs-3 fw-semibold">{{ number_format((int) ($kpi['value'] ?? 0)) }}</div>
                        @endif
                    </div>
                </div>
                @if ($canLink)
                    </a>
                @endif
            </div>
        @endforeach
    </div>

    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-1">{{ __('Reports') }}</h4>
            <p class="text-muted small mb-3">{{ __('Open full report screens for filters, export, and detail.') }}</p>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Store check') }}</h5>
                    <p class="text-muted small">{{ __('Count physical stock by warehouse and date. Saved checks stay in history for later reference.') }}</p>
                    <a href="{{ route('reports.product.store-check') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                    <a href="{{ route('reports.product.store-check.history') }}" class="btn btn-outline-primary btn-sm">{{ __('History') }}</a>
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
                    <h5 class="card-title">{{ __('Total stock value') }}</h5>
                    <p class="text-muted small">{{ __('Stock quantity with purchase and sale values, with and without VAT.') }}</p>
                    <a href="{{ route('reports.product.total-stock-value') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('High-value stock movements') }}</h5>
                    <p class="text-muted small">{{ __('Products with the highest sales rotation (quantity sold via invoice and POS).') }}</p>
                    <a href="{{ route('reports.product.high-rotation') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Out-of-stock products') }}</h5>
                    <p class="text-muted small">{{ __('Products and kits with stock equal to zero or below.') }}</p>
                    <a href="{{ route('reports.product.out-of-stock') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Minimum Stock Alert') }}</h5>
                    <p class="text-muted small">{{ __('Products with stock equal to or less than their Low stock alert at value.') }}</p>
                    <a href="{{ route('reports.product.minimum-stock-alert') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Products near expiry') }}</h5>
                    <p class="text-muted small">{{ __('Lots with remaining stock expiring within the selected number of days.') }}</p>
                    <a href="{{ route('reports.product.near-expiry') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">{{ __('Expired products') }}</h5>
                    <p class="text-muted small">{{ __('Lots whose expiry date has passed.') }}</p>
                    <a href="{{ route('reports.product.expired-products') }}" class="btn btn-primary btn-sm">{{ __('Open') }}</a>
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
