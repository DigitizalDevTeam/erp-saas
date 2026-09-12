@extends('layouts.admin')
@section('page-title')
    {{ __('Store check') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.product.index') }}">{{ __('Product system reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Store check') }}</li>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success mb-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mb-3">{{ session('error') }}</div>
    @endif

    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="get" action="{{ route('reports.product.store-check') }}" class="row g-3 align-items-end" id="store-check-filters">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Warehouse') }} <x-required></x-required></label>
                    <select name="warehouse_id" class="form-control" required>
                        <option value="">{{ __('Select Warehouse') }}</option>
                        @foreach($warehouses as $wid => $wname)
                            <option value="{{ $wid }}" {{ (string) ($warehouseId ?? '') === (string) $wid ? 'selected' : '' }}>{{ $wname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Check Date') }} <x-required></x-required></label>
                    <input type="date" name="check_date" value="{{ $checkDate ?? now()->format('Y-m-d') }}" class="form-control" required>
                </div>
                <div class="col-md-12">
                    <label class="form-label d-block">{{ __('Period') }}</label>
                    <div class="btn-group flex-wrap" role="group">
                        <input type="radio" class="btn-check" name="period_type" id="period_all" value="all" {{ $periodType === 'all' ? 'checked' : '' }}>
                        <label class="btn btn-outline-primary" for="period_all">{{ __('All') }}</label>
                        <input type="radio" class="btn-check" name="period_type" id="period_week" value="week" {{ $periodType === 'week' ? 'checked' : '' }}>
                        <label class="btn btn-outline-primary" for="period_week">{{ __('Week') }}</label>
                        <input type="radio" class="btn-check" name="period_type" id="period_month" value="month" {{ $periodType === 'month' ? 'checked' : '' }}>
                        <label class="btn btn-outline-primary" for="period_month">{{ __('Month') }}</label>
                        <input type="radio" class="btn-check" name="period_type" id="period_custom" value="custom" {{ $periodType === 'custom' ? 'checked' : '' }}>
                        <label class="btn btn-outline-primary" for="period_custom">{{ __('Custom range') }}</label>
                    </div>
                </div>
                <div class="col-md-3 period-field period-week {{ $periodType === 'week' ? '' : 'd-none' }}">
                    <label class="form-label">{{ __('Week reference date') }}</label>
                    <input type="date" name="week_ref" value="{{ request('week_ref', now()->format('Y-m-d')) }}" class="form-control">
                </div>
                <div class="col-md-3 period-field period-month {{ $periodType === 'month' ? '' : 'd-none' }}">
                    <label class="form-label">{{ __('Month') }}</label>
                    <input type="month" name="month_ref" value="{{ request('month_ref', now()->format('Y-m')) }}" class="form-control">
                </div>
                <div class="col-md-3 period-field period-custom {{ $periodType === 'custom' ? '' : 'd-none' }}">
                    <label class="form-label">{{ __('From') }}</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
                </div>
                <div class="col-md-3 period-field period-custom {{ $periodType === 'custom' ? '' : 'd-none' }}">
                    <label class="form-label">{{ __('To') }}</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Category') }}</label>
                    <select name="category_id" class="form-control">
                        <option value="">{{ __('All products') }}</option>
                        @foreach($categories as $cid => $cname)
                            <option value="{{ $cid }}" {{ (string) request('category_id') === (string) $cid ? 'selected' : '' }}>{{ $cname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Row') }}</label>
                    <input type="text" name="row_filter" value="{{ request('row_filter') }}" class="form-control" placeholder="{{ __('Filter row…') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('Column') }} <span class="text-muted small">({{ __('location') }})</span></label>
                    <input type="text" name="column_filter" value="{{ request('column_filter') }}" class="form-control" placeholder="{{ __('Filter column…') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Search name / SKU') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search…') }}">
                </div>
                <div class="col-auto">
                        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('reports.product.store-check') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                        <a href="{{ route('reports.product.store-check.history') }}" class="btn btn-outline-primary">
                            <i class="ti ti-history"></i> {{ __('History') }}
                        </a>
                        @if($warehouseId)
                        <a href="{{ route('reports.product.store-check', array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn btn-secondary">
                            <i class="ti ti-printer"></i> {{ __('Print') }}
                        </a>
                        @endif
                </div>
            </form>
        </div>
    </div>

    @if(!empty($history) && $history->count())
        <div class="card mb-3 no-print">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ __('Recent Store Checks') }}</h5>
                <a href="{{ route('reports.product.store-check.history') }}" class="btn btn-sm btn-outline-primary">{{ __('View all') }}</a>
            </div>
            <div class="card-body table-border-style py-2">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Warehouse') }}</th>
                                <th>{{ __('Notes') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($history->take(5) as $h)
                                <tr>
                                    <td>{{ $h->check_date ? Auth::user()->dateFormat($h->check_date) : Auth::user()->dateFormat($h->created_at) }}</td>
                                    <td>{{ $h->warehouse->name ?? '—' }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($h->notes, 40) ?: '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('reports.product.store-check.show', $h->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Open') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <div id="stock-check-printable">
        <div class="card">
            <div class="card-body table-border-style">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <strong>{{ __('Warehouse') }}:</strong> {{ $warehouseId ? ($warehouses[$warehouseId] ?? '—') : __('Select a warehouse to count stock') }}
                        &nbsp;|&nbsp;
                        <strong>{{ __('Check Date') }}:</strong> {{ $checkDate ?? now()->format('Y-m-d') }}
                        &nbsp;|&nbsp;
                        <strong>{{ __('Period') }}:</strong> {{ $periodLabel }}
                        <div class="text-muted small">{{ __('System quantity is warehouse stock on hand. Physical quantity is your counted amount.') }}</div>
                    </div>
                </div>

                @if(!$warehouseId)
                    <p class="text-center text-muted mb-0">{{ __('Select a warehouse and date, then click Apply to start the store check.') }}</p>
                @elseif($products->count() > 0)
                    {{ Form::open(['route' => 'reports.product.store-check.save', 'method' => 'post', 'id' => 'stock-audit-form']) }}
                    @csrf
                    <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
                    <input type="hidden" name="check_date" value="{{ $checkDate }}">
                    <input type="hidden" name="period_type" value="{{ $periodType }}">
                    <input type="hidden" name="week_ref" value="{{ request('week_ref', now()->format('Y-m-d')) }}">
                    <input type="hidden" name="month_ref" value="{{ request('month_ref', now()->format('Y-m')) }}">
                    <input type="hidden" name="date_from" value="{{ request('date_from') }}">
                    <input type="hidden" name="date_to" value="{{ request('date_to') }}">

                    <div class="row g-2 mb-3 no-print">
                        <div class="col-md-8">
                            <label class="form-label">{{ __('Notes') }} <span class="text-muted">({{ __('optional') }})</span></label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="{{ __('Count notes, team, etc.') }}">{{ old('notes') }}</textarea>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100"><i class="ti ti-device-floppy"></i> {{ __('Save stock audit') }}</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped align-middle" id="store-check-table">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Row') }}</th>
                                <th>{{ __('Column') }}</th>
                                <th class="text-end">{{ __('System quantity') }}</th>
                                <th class="text-end no-print" style="min-width: 8rem;">{{ __('Physical quantity') }}</th>
                                <th class="text-end print-only d-none">{{ __('Physical quantity') }}</th>
                                <th class="text-end">{{ __('Difference') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($products as $p)
                                @php
                                    $sys = (float) ($p->system_quantity ?? $p->quantity ?? 0);
                                @endphp
                                <tr data-system="{{ number_format($sys, 10, '.', '') }}">
                                    <td>{{ $p->name }}</td>
                                    <td>{{ $p->sku ?? '—' }}</td>
                                    <td>{{ $p->row_no ?? '—' }}</td>
                                    <td>{{ $p->shelf_no ?? '—' }}</td>
                                    <td class="text-end system-qty">{{ number_format($sys, 2) }}</td>
                                    <td class="text-end no-print">
                                        <input type="hidden" name="product_ids[]" value="{{ $p->id }}">
                                        <input type="number"
                                               name="physical[{{ $p->id }}]"
                                               step="0.0001"
                                               lang="en"
                                               class="form-control form-control-sm text-end physical-qty"
                                               placeholder="—"
                                               value="{{ old('physical.'.$p->id) }}"
                                               data-product-id="{{ $p->id }}">
                                    </td>
                                    <td class="text-end print-only physical-print d-none">—</td>
                                    <td class="text-end diff-cell">—</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ Form::close() }}
                @else
                    <p class="text-center text-muted mb-0">{{ __('No products found.') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('css-page')
<style>
@media print {
    .no-print, .card .no-print, .sidebar, header, nav, .breadcrumb, .dash-hasmenu, .dash-submenu { display: none !important; }
    .d-none-print-info { display: block !important; }
    .print-only.d-none { display: table-cell !important; }
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .content-wrapper { padding: 0 !important; }
    #stock-check-printable .card { border: none !important; box-shadow: none !important; }
    @page { size: auto; margin: 12mm; }
}
.physical-qty { max-width: 8rem; margin-left: auto; }
.d-none-print-info { display: none; }
</style>
@endpush

@push('script-page')
<script>
(function () {
    function parseNum(v) {
        if (v === '' || v === null || v === undefined) return null;
        var n = parseFloat(String(v).replace(',', '.'));
        return isNaN(n) ? null : n;
    }
    function formatQty(n) {
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    }
    function updateRow(tr) {
        var sys = parseNum(tr.getAttribute('data-system'));
        if (sys === null) sys = 0;
        var inp = tr.querySelector('.physical-qty');
        var phys = inp ? parseNum(inp.value) : null;
        var diffEl = tr.querySelector('.diff-cell');
        var printCell = tr.querySelector('.physical-print');
        if (phys === null) {
            diffEl.textContent = '—';
            if (printCell) printCell.textContent = '—';
            return;
        }
        diffEl.textContent = formatQty(phys - sys);
        if (printCell) printCell.textContent = formatQty(phys);
    }
    document.querySelectorAll('#store-check-table tbody tr').forEach(function (tr) {
        var inp = tr.querySelector('.physical-qty');
        if (inp) {
            inp.addEventListener('input', function () { updateRow(tr); });
            if (inp.value !== '') updateRow(tr);
        }
    });
    var filt = document.getElementById('store-check-filters');
    if (filt) {
        function syncPeriodFields() {
            var t = filt.querySelector('input[name=\"period_type\"]:checked');
            var v = t ? t.value : 'all';
            filt.querySelectorAll('.period-field').forEach(function (el) { el.classList.add('d-none'); });
            if (v === 'week') filt.querySelectorAll('.period-week').forEach(function (el) { el.classList.remove('d-none'); });
            if (v === 'month') filt.querySelectorAll('.period-month').forEach(function (el) { el.classList.remove('d-none'); });
            if (v === 'custom') filt.querySelectorAll('.period-custom').forEach(function (el) { el.classList.remove('d-none'); });
        }
        filt.querySelectorAll('input[name=\"period_type\"]').forEach(function (r) {
            r.addEventListener('change', syncPeriodFields);
        });
        syncPeriodFields();
    }
    window.addEventListener('beforeprint', function () {
        document.querySelectorAll('#store-check-table tbody tr').forEach(updateRow);
    });
})();
</script>
@endpush
