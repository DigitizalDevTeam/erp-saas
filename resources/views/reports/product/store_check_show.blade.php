@extends('layouts.admin')
@section('page-title')
    {{ __('Store Check') }} #{{ $audit->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.store-check.history') }}">{{ __('Store Check History') }}</a></li>
    <li class="breadcrumb-item">#{{ $audit->id }}</li>
@endsection

@section('action-btn')
    <div class="float-end d-flex gap-2">
        <a href="{{ route('reports.product.store-check.show', $audit->id) }}?print=1" target="_blank" class="btn btn-sm btn-secondary">
            <i class="ti ti-printer"></i> {{ __('Print') }}
        </a>
        <a href="{{ route('reports.product.store-check.history', array_filter(['warehouse_id' => $audit->warehouse_id])) }}" class="btn btn-sm btn-outline-primary">{{ __('Back to history') }}</a>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">{{ __('Check Date') }}</div>
                    <div class="fw-semibold">{{ $audit->check_date ? Auth::user()->dateFormat($audit->check_date) : Auth::user()->dateFormat($audit->created_at) }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">{{ __('Warehouse') }}</div>
                    <div class="fw-semibold">{{ $audit->warehouse->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">{{ __('Period') }}</div>
                    <div class="fw-semibold">{{ ucfirst($audit->period_type) }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">{{ __('Saved At') }}</div>
                    <div class="fw-semibold">{{ Auth::user()->dateFormat($audit->created_at) }}</div>
                </div>
            </div>
            @if($audit->notes)
                <p><strong>{{ __('Notes') }}:</strong> {{ $audit->notes }}</p>
            @endif

            @php
                $diffCount = $audit->lines->filter(fn ($l) => $l->difference !== null && (float) $l->difference != 0)->count();
                $counted = $audit->lines->filter(fn ($l) => $l->physical_quantity !== null)->count();
            @endphp
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <div class="border rounded p-2">
                        <div class="text-muted small">{{ __('Products') }}</div>
                        <div class="fw-semibold">{{ $audit->lines->count() }}</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2">
                        <div class="text-muted small">{{ __('Counted') }}</div>
                        <div class="fw-semibold">{{ $counted }}</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2">
                        <div class="text-muted small">{{ __('Differences') }}</div>
                        <div class="fw-semibold {{ $diffCount ? 'text-danger' : '' }}">{{ $diffCount }}</div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('SKU') }}</th>
                            <th>{{ __('Row') }}</th>
                            <th>{{ __('Column') }}</th>
                            <th class="text-end">{{ __('System quantity') }}</th>
                            <th class="text-end">{{ __('Physical quantity') }}</th>
                            <th class="text-end">{{ __('Difference') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($audit->lines as $line)
                            <tr>
                                <td>{{ $line->product_name }}</td>
                                <td>{{ $line->sku ?: '—' }}</td>
                                <td>{{ $line->row_no ?: '—' }}</td>
                                <td>{{ $line->shelf_no ?: '—' }}</td>
                                <td class="text-end">{{ number_format((float) $line->system_quantity, 2) }}</td>
                                <td class="text-end">{{ $line->physical_quantity !== null ? number_format((float) $line->physical_quantity, 2) : '—' }}</td>
                                <td class="text-end {{ (float) ($line->difference ?? 0) < 0 ? 'text-danger' : ((float) ($line->difference ?? 0) > 0 ? 'text-success' : '') }}">
                                    {{ $line->difference !== null ? number_format((float) $line->difference, 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
