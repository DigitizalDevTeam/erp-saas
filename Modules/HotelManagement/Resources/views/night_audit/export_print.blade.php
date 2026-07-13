@extends('layouts.admin')
@section('page-title'){{ __('Night audit report') }} — {{ $preview['audit_date'] ?? '' }}@endsection
@section('content')
@php
    $companyTitle = \App\Models\Utility::companyData(Auth::user()->creatorId(), 'title_text') ?: config('app.name');
@endphp
<div class="mb-3 d-flex flex-wrap gap-2 no-print">
    <a href="{{ route('hotel.night-audit.index', ['audit_date' => $preview['audit_date'] ?? '']) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
    <button type="button" class="btn btn-primary" id="night-audit-pdf-btn">{{ __('Download PDF') }}</button>
    <button type="button" class="btn btn-outline-primary" onclick="window.print()">{{ __('Print') }}</button>
</div>
<div id="night-audit-export" class="card shadow-sm">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h4 class="mb-1">{{ $companyTitle }}</h4>
            <p class="mb-0 text-muted">{{ __('Night audit report') }} — {{ $preview['audit_date'] ?? '' }}</p>
            <p class="small text-muted">{{ __('Generated') }}: {{ now()->format('Y-m-d H:i') }} · {{ Auth::user()->name }}</p>
        </div>
        @include('hotelmanagement::night_audit.partials._summary_general')
        @include('hotelmanagement::night_audit.partials._summary_other')
        @include('hotelmanagement::night_audit.partials._report_previews')
        @include('hotelmanagement::night_audit.partials._executive_report')
        @include('hotelmanagement::night_audit.partials._checklist')
    </div>
</div>
@push('script-page')
<script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
<script>
(function () {
    var btn = document.getElementById('night-audit-pdf-btn');
    if (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById('night-audit-export');
            if (!el || typeof html2pdf === 'undefined') return;
            html2pdf().set({
                margin: [0.3, 0.3, 0.3, 0.3],
                filename: 'night-audit-{{ $preview['audit_date'] ?? 'report' }}.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2 },
                jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
            }).from(el).save();
        });
    }

    @if(request()->boolean('autoprint'))
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
    @endif
})();
</script>
@endpush
<style>
@media print {
    .no-print { display: none !important; }
}
</style>
@endsection
