@extends('layouts.admin')
@section('page-title')
    {{ __('Minimum Stock Alert') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Minimum Stock Alert') }}</li>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.minimum-stock-alert') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Warehouse') }}</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">{{ __('All Warehouses') }}</option>
                        @foreach($warehouses as $wid => $wname)
                            <option value="{{ $wid }}" {{ (string) request('warehouse_id', $warehouseId) === (string) $wid ? 'selected' : '' }}>{{ $wname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Category') }}</label>
                    <select name="category_id" class="form-control">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach($categories as $cid => $cname)
                            <option value="{{ $cid }}" {{ (string) request('category_id') === (string) $cid ? 'selected' : '' }}>{{ $cname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Search name / SKU') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Default threshold') }}</label>
                    <input type="number" name="threshold" min="0" step="1" value="{{ request('threshold', $globalThreshold) }}" class="form-control">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.minimum-stock-alert') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.product.minimum-stock-alert.export', request()->query()) }}" class="btn btn-primary">
                        <i class="ti ti-file-export"></i> {{ __('Export CSV') }}
                    </a>
                    <a href="{{ route('reports.product.minimum-stock-alert', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-border-style">
            <p class="text-muted mb-3">
                {{ __('Low stock is checked per warehouse against each product\'s minimum stock level.') }}
                {{ __('Default threshold') }}: <strong>{{ $globalThreshold }}</strong>
                {{ __('(for items without a per-product setting)') }}
            </p>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('Warehouse') }}</th>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('SKU') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Type') }}</th>
                            <th class="text-end">{{ __('Warehouse Stock') }}</th>
                            <th class="text-end">{{ __('Low stock alert at') }}</th>
                            <th class="text-end">{{ __('Shortage') }}</th>
                            <th class="text-end">{{ __('Sale Price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php
                                $threshold = $r->low_stock_threshold !== null ? (float) $r->low_stock_threshold : (float) $globalThreshold;
                                $stockQty = (float) $r->warehouse_quantity;
                                $shortage = $threshold - $stockQty;
                            @endphp
                            <tr>
                                <td>{{ $r->warehouse_name }}</td>
                                <td>{{ $r->product_name }}</td>
                                <td>{{ $r->sku ?: '—' }}</td>
                                <td>{{ $r->category_name ?: '—' }}</td>
                                <td>{{ ucfirst($r->type) }}</td>
                                <td class="text-end">{{ $stockQty }}</td>
                                <td class="text-end">{{ $threshold }}</td>
                                <td class="text-end text-danger">{{ $shortage > 0 ? $shortage : 0 }}</td>
                                <td class="text-end">{{ Auth::user()->priceFormat($r->sale_price ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">{{ __('No products at or below the minimum stock alert.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
