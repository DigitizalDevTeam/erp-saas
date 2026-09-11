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
        <a href="#" onclick="window.print(); return false;" class="btn btn-sm btn-primary-subtle" title="{{ __('Print') }}"><i class="ti ti-printer"></i></a>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <h4>{{ __('Warehouse transfer') }} #{{ $doc->id }}</h4>
            <p class="text-muted">{{ __('Date') }}: {{ $doc->date }}</p>
            <p><strong>{{ __('Product') }}:</strong> {{ $doc->product->name ?? ('#' . $doc->product_id) }}</p>
            <p><strong>{{ __('Quantity') }}:</strong> {{ number_format((float) $doc->quantity, 2) }}</p>
            <p><strong>{{ __('From') }}:</strong> {{ $doc->fromWarehouse->name ?? ('#' . $doc->from_warehouse) }}</p>
            <p><strong>{{ __('To') }}:</strong> {{ $doc->toWarehouse->name ?? ('#' . $doc->to_warehouse) }}</p>
            <a href="{{ route('warehouse-transfer.index') }}" class="btn btn-sm btn-outline-primary">{{ __('Warehouse transfers') }}</a>
        </div>
    </div>
@endsection
