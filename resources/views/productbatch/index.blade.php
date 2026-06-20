@extends('layouts.admin')
@section('page-title')
    {{ __('Batch / Lot monitor') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('productstock.index') }}">{{ __('Product Stock') }}</a></li>
    <li class="breadcrumb-item">{{ __('Batch / Lot monitor') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0">{{ __('Filters') }}</h5>
                    <a href="{{ route('product-batches.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
                <div class="card-body">
                    <form method="get" action="{{ route('product-batches.index') }}" class="row g-3">
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Search') }}</label>
                            <input type="text" name="search" class="form-control" value="{{ request('search') }}"
                                placeholder="{{ __('Product name, SKU, batch…') }}">
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Product') }}</label>
                            <select name="product_id" class="form-control select">
                                <option value="">{{ __('All products') }}</option>
                                @foreach ($products as $prod)
                                    <option value="{{ $prod->id }}" @selected((string) request('product_id') === (string) $prod->id)>
                                        {{ $prod->name }}@if ($prod->sku) ({{ $prod->sku }}) @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Batch no.') }}</label>
                            <input type="text" name="batch_no" class="form-control" value="{{ request('batch_no') }}"
                                placeholder="{{ __('Contains…') }}">
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Warehouse') }}</label>
                            <select name="warehouse_filter" class="form-control select">
                                <option value="">{{ __('All warehouses') }}</option>
                                <option value="unassigned" @selected(request('warehouse_filter') === 'unassigned')>{{ __('Unassigned') }}</option>
                                @foreach ($warehouses as $wh)
                                    <option value="{{ $wh->id }}" @selected((string) request('warehouse_filter') === (string) $wh->id)>
                                        {{ $wh->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Source') }}</label>
                            <select name="source" class="form-control select">
                                <option value="">{{ __('All') }}</option>
                                <option value="purchase" @selected(request('source') === 'purchase')>{{ __('Purchase') }}</option>
                                <option value="manual" @selected(request('source') === 'manual')>{{ __('Manual stock') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Remaining qty') }}</label>
                            <select name="remaining" class="form-control select">
                                <option value="with_stock" @selected(request('remaining', 'with_stock') === 'with_stock')>{{ __('With stock only') }}</option>
                                <option value="depleted" @selected(request('remaining') === 'depleted')>{{ __('Depleted only') }}</option>
                                <option value="all" @selected(request('remaining') === 'all')>{{ __('All') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Expiry status') }}</label>
                            <select name="expiry_status" class="form-control select" id="expiry_status">
                                <option value="all" @selected(request('expiry_status', 'all') === 'all')>{{ __('All') }}</option>
                                <option value="valid" @selected(request('expiry_status') === 'valid')>{{ __('Not expired') }}</option>
                                <option value="expired" @selected(request('expiry_status') === 'expired')>{{ __('Expired') }}</option>
                                <option value="expiring_soon" @selected(request('expiry_status') === 'expiring_soon')>{{ __('Expiring soon') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3" id="expiring_days_wrap" style="{{ request('expiry_status') === 'expiring_soon' ? '' : 'display:none;' }}">
                            <label class="form-label">{{ __('Within days') }}</label>
                            <input type="number" name="expiring_days" class="form-control" min="1" max="365"
                                value="{{ request('expiring_days', 30) }}">
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Expiry from') }}</label>
                            <input type="date" name="expiry_from" class="form-control" value="{{ request('expiry_from') }}">
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Expiry to') }}</label>
                            <input type="date" name="expiry_to" class="form-control" value="{{ request('expiry_to') }}">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="hidden" name="include_no_expiry" value="0">
                                <input class="form-check-input" type="checkbox" name="include_no_expiry" value="1" id="include_no_expiry"
                                    @checked(request()->boolean('include_no_expiry'))>
                                <label class="form-check-label" for="include_no_expiry">{{ __('Include lots with no expiry date (when using expiry filters)') }}</label>
                            </div>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Mfg from') }}</label>
                            <input type="date" name="mfg_from" class="form-control" value="{{ request('mfg_from') }}">
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Mfg to') }}</label>
                            <input type="date" name="mfg_to" class="form-control" value="{{ request('mfg_to') }}">
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Sort') }}</label>
                            <select name="sort" class="form-control select">
                                <option value="expiry_asc" @selected(request('sort', 'expiry_asc') === 'expiry_asc')>{{ __('Expiry (soonest first)') }}</option>
                                <option value="expiry_desc" @selected(request('sort') === 'expiry_desc')>{{ __('Expiry (latest first)') }}</option>
                                <option value="created_desc" @selected(request('sort') === 'created_desc')>{{ __('Newest lots') }}</option>
                                <option value="remaining_asc" @selected(request('sort') === 'remaining_asc')>{{ __('Remaining qty (low first)') }}</option>
                                <option value="product_asc" @selected(request('sort') === 'product_asc')>{{ __('Product name A–Z') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label">{{ __('Per page') }}</label>
                            <select name="per_page" class="form-control select">
                                @foreach ([10, 25, 50, 100] as $pp)
                                    <option value="{{ $pp }}" @selected((string) request('per_page', 25) === (string) $pp)>{{ $pp }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="hidden" name="purchase_only" value="0">
                                <input class="form-check-input" type="checkbox" name="purchase_only" value="1" id="purchase_only"
                                    @checked(request()->boolean('purchase_only'))>
                                <label class="form-check-label" for="purchase_only">{{ __('Only lots linked to a purchase') }}</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('Apply filters') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Lots') }} ({{ $lots->total() }})</h5>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('SKU') }}</th>
                                    <th>{{ __('Method') }}</th>
                                    <th>{{ __('Batch') }}</th>
                                    <th>{{ __('Mfg') }}</th>
                                    <th>{{ __('Expiry') }}</th>
                                    <th class="text-end">{{ __('Days') }}</th>
                                    <th class="text-end">{{ __('Qty in') }}</th>
                                    <th class="text-end">{{ __('Remaining') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    <th>{{ __('Source') }}</th>
                                    <th>{{ __('Purchase') }}</th>
                                    <th>{{ __('Created') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lots as $lot)
                                    @php
                                        $today = \Carbon\Carbon::today();
                                        $exp = $lot->expiry_date ? \Carbon\Carbon::parse($lot->expiry_date)->startOfDay() : null;
                                        $daysLeft = $exp ? $today->diffInDays($exp, false) : null;
                                        $expired = $exp && $exp->lt($today);
                                    @endphp
                                    <tr>
                                        <td>{{ $lot->product_name }}</td>
                                        <td>{{ $lot->product_sku ?: '—' }}</td>
                                        <td>
                                            @if($lot->batch_tracking ?? false)
                                                <span class="badge bg-info text-uppercase">{{ strtoupper($lot->batch_consumption_method ?? 'fefo') }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>{{ $lot->batch_no ?: '—' }}</td>
                                        <td>{{ $lot->mfg_date ? \Carbon\Carbon::parse($lot->mfg_date)->format('Y-m-d') : '—' }}</td>
                                        <td>{{ $lot->expiry_date ? \Carbon\Carbon::parse($lot->expiry_date)->format('Y-m-d') : '—' }}</td>
                                        <td class="text-end">
                                            @if ($lot->expiry_date)
                                                @if ($expired)
                                                    <span class="badge bg-danger">{{ __('Expired') }}</span>
                                                @else
                                                    <span class="badge bg-{{ $daysLeft !== null && $daysLeft <= 30 ? 'warning text-dark' : 'success' }}">{{ max(0, (int) $daysLeft) }}</span>
                                                @endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $lot->quantity_in }}</td>
                                        <td class="text-end">{{ $lot->quantity_remaining }}</td>
                                        <td>{{ $lot->warehouse_name ?: __('Unassigned') }}</td>
                                        <td>
                                            @if ($lot->source === 'purchase')
                                                <span class="badge bg-primary">{{ __('Purchase') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ __('Manual') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if (!empty($lot->purchase_id))
                                                <a href="{{ route('purchase.show', \Crypt::encrypt($lot->purchase_id)) }}" class="btn btn-sm btn-outline-primary py-0">{{ __('View') }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td><small class="text-muted">{{ $lot->created_at ? \Carbon\Carbon::parse($lot->created_at)->format('Y-m-d H:i') : '—' }}</small></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center text-muted py-4">{{ __('No lots match your filters.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        {{ $lots->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var sel = document.getElementById('expiry_status');
            var wrap = document.getElementById('expiring_days_wrap');
            function toggle() {
                if (!sel || !wrap) return;
                wrap.style.display = sel.value === 'expiring_soon' ? '' : 'none';
            }
            if (sel) sel.addEventListener('change', toggle);
            toggle();
        });
    </script>
@endpush
