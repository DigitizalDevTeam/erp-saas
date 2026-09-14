@extends('layouts.admin')
@section('page-title')
    {{__('Manage Product Stock')}}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Product Stock')}}</li>
@endsection
@section('action-btn')
    <div class="float-end">
        <a href="{{ route('productstock.print', array_filter(['warehouse_id' => $warehouseId])) }}"
           target="_blank"
           class="btn btn-sm btn-outline-primary"
           data-bs-toggle="tooltip"
           title="{{ __('Print Product Stock') }}">
            <i class="ti ti-printer"></i> {{ __('Print') }}
        </a>
        @can('edit product & service')
            <a href="#" data-size="lg" data-url="{{ route('productstock.create') }}" data-ajax-popup="true"
                data-bs-toggle="tooltip" title="{{ __('Stock Introduction') }}" data-title="{{ __('Stock Introduction') }}"
                class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Stock Introduction') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Stock Introduction History') }}</h5>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Document #') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    <th>{{ __('Lines') }}</th>
                                    <th>{{ __('Products') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($introductions as $intro)
                                    <tr>
                                        <td>
                                            <a href="{{ route('productstock.show', $intro->id) }}">{{ $intro->intro_no }}</a>
                                        </td>
                                        <td>{{ Auth::user()->dateFormat($intro->intro_date) }}</td>
                                        <td>{{ $intro->warehouse->name ?? '—' }}</td>
                                        <td>{{ $intro->lines->count() }}</td>
                                        <td>
                                            {{ $intro->lines->take(3)->map(fn ($l) => $l->product->name ?? ('#'.$l->product_id))->implode(', ') }}
                                            @if($intro->lines->count() > 3)…@endif
                                        </td>
                                        <td>
                                            <a href="{{ route('productstock.show', $intro->id) }}" class="btn btn-sm btn-outline-primary" title="{{ __('View') }}">
                                                <i class="ti ti-eye"></i>
                                            </a>
                                            <a href="{{ route('productstock.print-intro', $intro->id) }}" target="_blank" class="btn btn-sm btn-primary" title="{{ __('Print') }}">
                                                <i class="ti ti-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">{{ __('No stock introductions yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Products') }}</h5>
                </div>
                <div class="card-body table-border-style">
                    <form method="get" action="{{ route('productstock.index') }}" class="row g-2 align-items-end mb-3">
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label" for="warehouse_id">{{ __('Warehouse') }}</label>
                            <select name="warehouse_id" id="warehouse_id" class="form-control select">
                                <option value="">{{ __('All Warehouses') }}</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" {{ (string) $warehouseId === (string) $warehouse->id ? 'selected' : '' }}>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="ti ti-filter"></i> {{ __('Filter') }}
                            </button>
                            @if($warehouseId)
                                <a href="{{ route('productstock.index') }}" class="btn btn-secondary btn-sm">{{ __('Clear') }}</a>
                            @endif
                            <a href="{{ route('productstock.print', array_filter(['warehouse_id' => $warehouseId])) }}"
                               target="_blank"
                               class="btn btn-outline-primary btn-sm">
                                <i class="ti ti-printer"></i> {{ __('Print') }}
                            </a>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Sku') }}</th>
                                <th>{{ __('Current Quantity') }}@if($warehouseId)<span class="text-muted small"> ({{ __('Warehouse') }})</span>@endif</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($productServices as $productService)
                                <tr class="font-style">
                                    <td>{{ $productService->name }}</td>
                                    <td>{{ $productService->sku }}</td>
                                    <td>{{ $productService->display_quantity ?? $productService->quantity }}</td>
                                    <td class="Action">
                                        <div class="action-btn me-2">
                                            <a data-size="md" href="#" class="mx-3 btn btn-sm align-items-center bg-info" data-url="{{ route('productstock.edit', $productService->id) }}" data-ajax-popup="true"  data-size="xl" data-bs-toggle="tooltip" title="{{__('Update Quantity')}}">
                                                <i class="ti ti-plus text-white"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
