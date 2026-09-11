@extends('layouts.admin')
@section('page-title')
    {{ __('Warehouse stock') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Warehouse stock') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="window.print(); return false;" class="btn btn-sm btn-primary-subtle" data-bs-toggle="tooltip" title="{{ __('Print') }}">
            <i class="ti ti-printer"></i>
        </a>
    </div>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Warehouse') }}</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">{{ __('All warehouses') }}</option>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" {{ (string) request('warehouse_id') === (string) $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Search name / SKU') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.warehouse-stock') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="col-auto ms-auto">
                    <a href="{{ route('reports.product.warehouse-stock', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                        <i class="ti ti-printer"></i> {{ __('Print') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body table-border-style">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th>{{ __('Warehouse') }}</th>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('SKU') }}</th>
                        <th>{{ __('Row') }}</th>
                        <th>{{ __('Shelf') }}</th>
                        <th class="text-end">{{ __('Qty') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td>{{ $r->warehouse_name }}</td>
                            <td>{{ $r->product_name }}</td>
                            <td>{{ $r->sku ?? '—' }}</td>
                            <td>{{ $r->row_no ?? '—' }}</td>
                            <td>{{ $r->shelf_no ?? '—' }}</td>
                            <td class="text-end">{{ number_format((float) $r->quantity, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">{{ __('No warehouse stock rows.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
