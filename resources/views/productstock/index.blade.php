@extends('layouts.admin')
@section('page-title')
    {{__('Manage Product Stock')}}
@endsection
@push('script-page')
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Product Stock')}}</li>
@endsection
@section('action-btn')
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
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
