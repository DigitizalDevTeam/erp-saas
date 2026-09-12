@extends('layouts.admin')
@section('page-title')
    {{ __('Store Check History') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.store-check') }}">{{ __('Store check') }}</a></li>
    <li class="breadcrumb-item">{{ __('History') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('reports.product.store-check') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i> {{ __('New Store Check') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.store-check.history') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Warehouse') }}</label>
                    <select name="warehouse_id" class="form-control">
                        <option value="">{{ __('All Warehouses') }}</option>
                        @foreach($warehouses as $wid => $wname)
                            <option value="{{ $wid }}" {{ (string) ($warehouseId ?? '') === (string) $wid ? 'selected' : '' }}>{{ $wname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('From date') }}</label>
                    <input type="date" name="date_from" value="{{ $dateFrom ?? '' }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('To date') }}</label>
                    <input type="date" name="date_to" value="{{ $dateTo ?? '' }}" class="form-control">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                    <a href="{{ route('reports.product.store-check.history') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
            </form>
            <p class="text-muted small mb-0 mt-2">
                {{ __('Browse saved store checks by warehouse and check date. Open a record to see system vs physical quantities.') }}
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-border-style">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('Check Date') }}</th>
                            <th>{{ __('Warehouse') }}</th>
                            <th class="text-end">{{ __('Lines') }}</th>
                            <th>{{ __('Period') }}</th>
                            <th>{{ __('Notes') }}</th>
                            <th>{{ __('Saved At') }}</th>
                            <th>{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($audits as $audit)
                            <tr>
                                <td>
                                    <a href="{{ route('reports.product.store-check.show', $audit->id) }}" class="fw-semibold">
                                        {{ $audit->check_date ? Auth::user()->dateFormat($audit->check_date) : Auth::user()->dateFormat($audit->created_at) }}
                                    </a>
                                </td>
                                <td>{{ $audit->warehouse->name ?? '—' }}</td>
                                <td class="text-end">{{ $audit->lines_count }}</td>
                                <td>{{ ucfirst($audit->period_type) }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($audit->notes, 50) ?: '—' }}</td>
                                <td>{{ Auth::user()->dateFormat($audit->created_at) }}</td>
                                <td>
                                    <a href="{{ route('reports.product.store-check.show', $audit->id) }}" class="btn btn-sm btn-primary" title="{{ __('Open') }}">
                                        <i class="ti ti-eye"></i>
                                    </a>
                                    <a href="{{ route('reports.product.store-check.show', $audit->id) }}?print=1" target="_blank" class="btn btn-sm btn-secondary" title="{{ __('Print') }}">
                                        <i class="ti ti-printer"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    {{ __('No store checks found for the selected warehouse/date filters.') }}
                                    <div class="mt-2">
                                        <a href="{{ route('reports.product.store-check') }}" class="btn btn-sm btn-primary">{{ __('Create Store Check') }}</a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($audits->hasPages())
                <div class="mt-3">{{ $audits->links() }}</div>
            @endif
        </div>
    </div>
@endsection
