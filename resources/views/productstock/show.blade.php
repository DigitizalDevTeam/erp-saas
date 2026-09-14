@extends('layouts.admin')
@section('page-title')
    {{ __('Stock Introduction') }} — {{ $intro->intro_no }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('productstock.index') }}">{{ __('Product Stock') }}</a></li>
    <li class="breadcrumb-item">{{ $intro->intro_no }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('productstock.print-intro', $intro->id) }}" target="_blank" class="btn btn-sm btn-primary">
            <i class="ti ti-printer"></i> {{ __('Print') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3"><strong>{{ __('Document #') }}:</strong> {{ $intro->intro_no }}</div>
                <div class="col-md-3"><strong>{{ __('Date') }}:</strong> {{ Auth::user()->dateFormat($intro->intro_date) }}</div>
                <div class="col-md-3"><strong>{{ __('Warehouse') }}:</strong> {{ $intro->warehouse->name ?? '—' }}</div>
                <div class="col-md-3"><strong>{{ __('Notes') }}:</strong> {{ $intro->notes ?: '—' }}</div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('SKU') }}</th>
                            <th class="text-end">{{ __('Quantity') }}</th>
                            <th>{{ __('Row') }}</th>
                            <th>{{ __('Shelf') }}</th>
                            <th>{{ __('Batch') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($intro->lines as $i => $line)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $line->product->name ?? '—' }}</td>
                                <td>{{ $line->product->sku ?? '—' }}</td>
                                <td class="text-end">{{ $line->quantity }}</td>
                                <td>{{ $line->row_no ?: '—' }}</td>
                                <td>{{ $line->shelf_no ?: '—' }}</td>
                                <td>{{ $line->batch_no ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <a href="{{ route('productstock.index') }}" class="btn btn-outline-primary btn-sm">{{ __('Back') }}</a>
            <a href="{{ route('productstock.print-intro', $intro->id) }}" target="_blank" class="btn btn-primary btn-sm">
                <i class="ti ti-printer"></i> {{ __('Print') }}
            </a>
        </div>
    </div>
@endsection
