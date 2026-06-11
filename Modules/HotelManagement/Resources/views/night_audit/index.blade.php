@extends('layouts.admin')
@section('page-title'){{ __('Night Audit') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Night Audit') }}</li>
@endsection

@push('css-page')
<style>
    .hotel-night-audit .step-num {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--bs-primary);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .hotel-night-audit-procedure { border-left: 4px solid var(--bs-primary) !important; }
    .hotel-night-audit-rules { background: rgba(16, 185, 129, 0.12) !important; }
    .hotel-night-audit .summary-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }
    .hotel-night-audit .summary-card { border-top: 3px solid transparent; }
    .hotel-night-audit .summary-card--blue { border-top-color: #3b82f6; }
    .hotel-night-audit .summary-card--green { border-top-color: #10b981; }
    .hotel-night-audit .summary-card--purple { border-top-color: #8b5cf6; }
    .hotel-night-audit .summary-card--orange { border-top-color: #f59e0b; }
    .hotel-night-audit .summary-card--cyan { border-top-color: #0ea5e9; }
    .hotel-night-audit .summary-lines li { padding: 2px 0; }
    .hotel-night-audit .audit-status-badge { font-size: 0.8rem; padding: 0.35rem 0.75rem; }
    .hotel-night-audit .audit-confirmed-box {
        border: 2px solid #10b981;
        border-radius: 8px;
        background: rgba(16, 185, 129, 0.06);
        display: none;
    }
    .hotel-night-audit.is-confirmed .audit-confirmed-box { display: block; }
    .hotel-night-audit.is-confirmed .audit-pending-hint { opacity: 0.6; }
    .hotel-night-audit #audit-results.d-none { display: none !important; }
    @media print {
        .hotel-night-audit-procedure,
        .hotel-night-audit-filters,
        .hotel-night-audit-footer-actions .btn,
        .hotel-night-audit-history { display: none !important; }
    }
</style>
@endpush

@section('content')
@php
    $defaultDate = $auditDate ?? request('audit_date', date('Y-m-d', strtotime('-1 day')));
    $displayDate = \Carbon\Carbon::parse($defaultDate)->format('d/m/Y');
    $preview = $preview ?? [];
@endphp

<div class="hotel-night-audit" id="hotel-night-audit-root">
    <div class="row g-3">
        <div class="col-xl-3 col-lg-4 d-none d-lg-block">
            @include('hotelmanagement::night_audit.partials._procedure_panel')
        </div>

        <div class="col-xl-9 col-lg-8">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <p class="text-muted small mb-0 d-lg-none">{{ __('Procedure: Night Audit') }}</p>
                </div>
                <span class="badge bg-{{ ($preview['is_closed'] ?? false) ? 'success' : 'warning' }} {{ ($preview['is_closed'] ?? false) ? '' : 'text-dark' }} audit-status-badge" id="audit-status-badge">
                    {{ __('Audit status') }}: <span id="audit-status-text">{{ ($preview['is_closed'] ?? false) ? __('Closed') : __('Pending') }}</span>
                </span>
            </div>

            <div class="alert alert-light border small mb-3">
                <i class="ti ti-info-circle me-1"></i>
                {{ __('Night audit closes the business day: open POS shifts are finalized, the day is locked, and summaries are saved. Suggested run time: :time.', ['time' => $nightAuditTime ?? '02:00']) }}
            </div>

            <div class="card shadow-sm border-0 mb-3 hotel-night-audit-filters">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label small fw-semibold">{{ __('Audit date') }}</label>
                            <input type="date" class="form-control" id="audit-date" value="{{ $defaultDate }}">
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-semibold">{{ __('Shift') }}</label>
                            <select class="form-select" id="audit-shift">
                                <option value="night" selected>{{ __('Night') }}</option>
                                <option value="day">{{ __('Day') }}</option>
                                <option value="afternoon">{{ __('Afternoon') }}</option>
                            </select>
                        </div>
                        <div class="col-md-5 col-12">
                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                <button type="button" class="btn btn-outline-secondary" id="btn-audit-preview">
                                    <i class="ti ti-eye me-1"></i>{{ __('Preview') }}
                                </button>
                                <button type="button" class="btn btn-primary" id="btn-audit-generate">
                                    <i class="ti ti-refresh me-1"></i>{{ __('Generate audit') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="audit-empty-state" class="card shadow-sm border-0 mb-3 d-none">
                <div class="card-body text-center py-5 text-muted">
                    <i class="ti ti-report-analytics display-4 d-block mb-2 opacity-50"></i>
                    <p class="mb-0">{{ __('Select a date and click Preview or Generate audit to view summaries and reports.') }}</p>
                </div>
            </div>

            <div id="audit-results">
                <h6 class="fw-semibold mb-3">{{ __('General summary') }}</h6>
                @include('hotelmanagement::night_audit.partials._summary_general')
                @include('hotelmanagement::night_audit.partials._summary_other')
                @include('hotelmanagement::night_audit.partials._report_previews')
                @include('hotelmanagement::night_audit.partials._checklist')
            </div>

            <div class="card shadow-sm border-0 hotel-night-audit-footer-actions">
                <div class="card-body">
                    <div class="alert alert-info border-0 small mb-3 audit-pending-hint" id="audit-hint">
                        <i class="ti ti-info-circle me-1"></i>
                        {{ __('After verifying all reports and amounts, click Confirm audit to finalize.') }}
                    </div>

                    <div class="audit-confirmed-box text-center py-3 px-3 mb-3" id="audit-confirmed-box">
                        <i class="ti ti-lock text-success fs-4 d-block mb-1"></i>
                        <strong class="text-success">{{ __('Audit confirmed') }}</strong>
                        <p class="small text-muted mb-0 mt-1">
                            {{ __('Date') }}: <span id="confirmed-date">{{ $displayDate }}</span>
                            · {{ __('Time') }}: <span id="confirmed-time">23:59</span>
                            · {{ __('User') }}: {{ Auth::user()->name }}
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                        <a href="{{ route('hotel.night-audit.export', ['audit_date' => $defaultDate]) }}" class="btn btn-outline-secondary" id="btn-audit-print" target="_blank">
                            <i class="ti ti-printer me-1"></i>{{ __('Print / Export all') }}
                        </a>
                        @if($canManage ?? false)
                            <form method="post" action="{{ route('hotel.night-audit.run') }}" class="d-inline" id="night-audit-run-form" onsubmit="return confirm('{{ __('Run night audit? This locks the selected date and closes open POS shifts.') }}');">
                                @csrf
                                <input type="hidden" name="audit_date" id="night-audit-run-date" value="{{ $defaultDate ?? date('Y-m-d', strtotime('-1 day')) }}">
                                <button type="submit" class="btn btn-primary" id="btn-audit-confirm">
                                    <i class="ti ti-check me-1"></i>{{ __('Run night audit & lock day') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            @if(isset($audits) && $audits->isNotEmpty())
                <div class="card shadow-sm border-0 mt-3 hotel-night-audit-history">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <h6 class="mb-0">{{ __('Previous audits') }}</h6>
                        <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#audit-history-collapse">
                            {{ __('Toggle') }}
                        </button>
                    </div>
                    <div class="collapse" id="audit-history-collapse">
                        <div class="card-body table-border-style p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Date') }}</th>
                                            <th>{{ __('Room revenue') }}</th>
                                            <th>{{ __('Service revenue') }}</th>
                                            <th>{{ __('Total revenue') }}</th>
                                            <th>{{ __('POS payments') }}</th>
                                            <th>{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($audits as $audit)
                                            <tr>
                                                <td>{{ $audit->audit_date }}</td>
                                                <td>{{ Auth::user()->priceFormat($audit->room_revenue) }}</td>
                                                <td>{{ Auth::user()->priceFormat($audit->service_revenue) }}</td>
                                                <td>{{ Auth::user()->priceFormat($audit->total_revenue) }}</td>
                                                <td>{{ Auth::user()->priceFormat($audit->pos_payments_total ?? 0) }}</td>
                                                <td>
                                                    <span class="badge bg-{{ $audit->status === 'closed' ? 'success' : 'warning' }}">
                                                        {{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $audit->status))) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="d-lg-none mt-3">
        @include('hotelmanagement::night_audit.partials._procedure_panel')
    </div>
</div>
@endsection

@push('script-page')
<script>
(function () {
    var root = document.getElementById('hotel-night-audit-root');
    if (!root) return;

    var dateInput = document.getElementById('audit-date');
    var emptyState = document.getElementById('audit-empty-state');
    var results = document.getElementById('audit-results');
    var statusBadge = document.getElementById('audit-status-badge');
    var statusText = document.getElementById('audit-status-text');
    var btnPreview = document.getElementById('btn-audit-preview');
    var btnGenerate = document.getElementById('btn-audit-generate');
    var btnConfirm = document.getElementById('btn-audit-confirm');
    var btnPrint = document.getElementById('btn-audit-print');
    var confirmedDate = document.getElementById('confirmed-date');

    function formatDisplayDate(iso) {
        if (!iso) return '';
        var p = iso.split('-');
        if (p.length !== 3) return iso;
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function showResults(mode) {
        emptyState.classList.add('d-none');
        results.classList.remove('d-none');
        if (btnConfirm) btnConfirm.disabled = false;
        if (btnPrint) btnPrint.disabled = false;
        if (mode === 'preview') {
            statusBadge.className = 'badge bg-info text-dark audit-status-badge';
            statusText.textContent = '{{ __("Preview") }}';
        } else {
            statusBadge.className = 'badge bg-primary audit-status-badge';
            statusText.textContent = '{{ __("Generated") }}';
        }
        if (confirmedDate && dateInput) {
            confirmedDate.textContent = formatDisplayDate(dateInput.value);
        }
    }

    function reloadWithDate() {
        if (!dateInput) return;
        var url = new URL(window.location.href);
        url.searchParams.set('audit_date', dateInput.value);
        window.location.href = url.toString();
    }

    if (btnPreview) {
        btnPreview.addEventListener('click', reloadWithDate);
    }
    if (btnGenerate) {
        btnGenerate.addEventListener('click', reloadWithDate);
    }

    var runDate = document.getElementById('night-audit-run-date');
    if (dateInput && runDate) {
        dateInput.addEventListener('change', function () {
            runDate.value = dateInput.value;
            reloadWithDate();
        });
        runDate.value = dateInput.value;
    }

    showResults('generate');
})();
</script>
@endpush
