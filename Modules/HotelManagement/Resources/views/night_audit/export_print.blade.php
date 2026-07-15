@extends('layouts.admin')
@section('page-title'){{ __('Night audit report') }} — {{ $preview['audit_date'] ?? '' }}@endsection
@section('content')
@php
    $companyTitle = \App\Models\Utility::companyData(Auth::user()->creatorId(), 'title_text') ?: config('app.name');
    $autoPrint = request()->boolean('autoprint');
@endphp
<div class="mb-3 d-flex flex-wrap gap-2 no-print">
    <a href="{{ route('hotel.night-audit.index', ['audit_date' => $preview['audit_date'] ?? '']) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
    <button type="button" class="btn btn-primary" id="night-audit-pdf-btn">{{ __('Download PDF') }}</button>
    <button type="button" class="btn btn-outline-primary" id="night-audit-print-btn">{{ __('Print') }}</button>
    @if($autoPrint)
        <span class="align-self-center small text-muted">{{ __('Opening print dialog automatically…') }}</span>
    @endif
</div>
<div id="night-audit-export" class="card shadow-sm night-audit-print-root">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h4 class="mb-1">{{ $companyTitle }}</h4>
            <p class="mb-0 text-muted">{{ __('Night audit report') }} — {{ $preview['audit_date'] ?? '' }}</p>
            <p class="small text-muted">{{ __('Generated') }}: {{ now()->format('Y-m-d H:i') }} · {{ Auth::user()->name }}</p>
        </div>

        <div class="night-audit-print-section">
            @include('hotelmanagement::night_audit.partials._summary_general')
        </div>
        <div class="night-audit-print-section page-break-before">
            @include('hotelmanagement::night_audit.partials._summary_other')
        </div>
        <div class="night-audit-print-section page-break-before">
            @include('hotelmanagement::night_audit.partials._report_previews')
        </div>
        <div class="night-audit-print-section page-break-before">
            @include('hotelmanagement::night_audit.partials._executive_report')
        </div>
        <div class="night-audit-print-section page-break-before">
            @include('hotelmanagement::night_audit.partials._checklist')
        </div>

        @if(!empty($preview['cash_register']))
        <div class="night-audit-print-section page-break-before">
            @php $fmtCash = fn ($n) => Auth::user()->priceFormat($n); $cr = $preview['cash_register']; @endphp
            <h6 class="fw-semibold mb-3">{{ __('Cash register / petty cash') }}</h6>
            <table class="table table-sm table-bordered">
                <tbody>
                    <tr><td>{{ __('Opening balance') }}</td><td class="text-end">{{ $fmtCash($cr['opening_balance'] ?? 0) }}</td></tr>
                    <tr><td>{{ __('Cash inflows') }}</td><td class="text-end">{{ $fmtCash($cr['cash_inflows'] ?? 0) }}</td></tr>
                    <tr><td>{{ __('Cash outflows (expenses)') }}</td><td class="text-end">{{ $fmtCash($cr['cash_outflows'] ?? 0) }}</td></tr>
                    <tr class="fw-bold"><td>{{ __('Closing balance') }}</td><td class="text-end">{{ $fmtCash($cr['closing_balance'] ?? 0) }}</td></tr>
                </tbody>
            </table>
        </div>
        @endif

        @php $shiftRows = $preview['executive']['shifts'] ?? []; @endphp
        @if(is_array($shiftRows) && count($shiftRows))
        <div class="night-audit-print-section page-break-before">
            <h6 class="fw-semibold mb-3">{{ __('Cashier shifts') }}</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>{{ __('Cashier') }}</th>
                            <th>{{ __('Opened') }}</th>
                            <th>{{ __('Closed') }}</th>
                            <th class="text-end">{{ __('Cash collected') }}</th>
                            <th class="text-end">{{ __('Closing cash') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($shiftRows as $row)
                            <tr>
                                <td>{{ $row['cashier'] ?? '—' }}</td>
                                <td>{{ $row['opened_at'] ?? '—' }}</td>
                                <td>{{ $row['closed_at'] ?? '—' }}</td>
                                <td class="text-end">{{ Auth::user()->priceFormat($row['cash_collected'] ?? 0) }}</td>
                                <td class="text-end">{{ Auth::user()->priceFormat($row['closing_cash'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>
@push('script-page')
<script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
<script>
(function () {
    function downloadPdf() {
        var el = document.getElementById('night-audit-export');
        if (!el || typeof html2pdf === 'undefined') return Promise.resolve();
        return html2pdf().set({
            margin: [0.3, 0.3, 0.3, 0.3],
            filename: 'night-audit-{{ $preview['audit_date'] ?? 'report' }}.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css', 'legacy'] }
        }).from(el).save();
    }

    var btn = document.getElementById('night-audit-pdf-btn');
    if (btn) {
        btn.addEventListener('click', function () { downloadPdf(); });
    }
    var printBtn = document.getElementById('night-audit-print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function () { window.print(); });
    }

    @if($autoPrint)
    window.addEventListener('load', function () {
        setTimeout(function () {
            window.print();
            // After print dialog closes (or is dismissed), also generate PDF once.
            setTimeout(function () {
                downloadPdf().catch(function () {});
            }, 1200);
        }, 600);
    });
    @endif
})();
</script>
@endpush
<style>
.night-audit-print-section { margin-bottom: 1.5rem; }
@media print {
    .no-print { display: none !important; }
    .dash-sidebar, .dash-header, .navbar, footer, .no-print { display: none !important; }
    .page-break-before { page-break-before: always; break-before: page; }
    .night-audit-print-root, .night-audit-print-root .card-body {
        box-shadow: none !important;
        border: none !important;
    }
    body { background: #fff !important; }
}
</style>
@endsection
