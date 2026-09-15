@extends('layouts.admin')
@section('page-title')
    {{ __('Warehouse transfer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.transactions') }}">{{ __('Product transactions') }}</a></li>
    <li class="breadcrumb-item">{{ __('Transfer') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('warehouse-transfer.print', $doc->id) }}" target="_blank" class="btn btn-sm btn-primary me-1" title="{{ __('Print') }}"><i class="ti ti-printer"></i></a>
        <a href="#" onclick="window.print(); return false;" class="btn btn-sm btn-primary-subtle" title="{{ __('Quick print') }}"><i class="ti ti-printer"></i></a>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <h4>{{ __('Warehouse transfer') }} #{{ $doc->id }}</h4>
            <p class="text-muted">{{ __('Date') }}: {{ $doc->date }}</p>
            <p><strong>{{ __('Product') }}:</strong>
                @if(!empty($doc->product))
                    {{ $doc->product->name }}@if(method_exists($doc->product, 'trashed') && $doc->product->trashed()) ({{ __('Product deleted') }})@endif
                @else
                    {{ __('Product deleted') }} (#{{ $doc->product_id }})
                @endif
            </p>
            <p><strong>{{ __('Quantity') }}:</strong> {{ number_format((float) $doc->quantity, 2) }}</p>
            <p><strong>{{ __('From') }}:</strong> {{ $doc->fromWarehouse->name ?? ('#' . $doc->from_warehouse) }}</p>
            <p><strong>{{ __('To') }}:</strong> {{ $doc->toWarehouse->name ?? ('#' . $doc->to_warehouse) }}</p>

            <div class="mt-4 pt-3 border-top">
                <h6 class="mb-3">{{ __('Receiver Confirmation') }}</h6>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="border-top pt-2 mt-5 small">{{ __('Receiver Name') }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="border-top pt-2 mt-5 small">{{ __('Signature') }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="border-top pt-2 mt-5 small">{{ __('Date') }}</div>
                    </div>
                </div>
            </div>

            <a href="{{ route('warehouse-transfer.index') }}" class="btn btn-sm btn-outline-primary mt-4">{{ __('Warehouse transfers') }}</a>
        </div>
    </div>
@endsection
