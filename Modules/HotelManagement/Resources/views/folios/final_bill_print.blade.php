@extends('layouts.admin')
@section('page-title'){{ __('Final bill') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.folios.show', $folio) }}">{{ __('Room bill') }}</a></li>
    <li class="breadcrumb-item">{{ __('Final bill') }}</li>
@endsection
@section('content')
@php
    $companyTitle = \App\Models\Utility::companyData(Auth::user()->creatorId(), 'title_text') ?: config('app.name');
@endphp
<div class="mb-3 d-flex flex-wrap gap-2">
    <a href="{{ route('hotel.folios.show', $folio) }}" class="btn btn-outline-secondary">{{ __('Back to room bill') }}</a>
    <button type="button" class="btn btn-primary" id="folio-final-bill-pdf">{{ __('Download PDF') }}</button>
</div>
<div id="folio-final-bill" class="card shadow-sm">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <h4 class="mb-1">{{ $companyTitle }}</h4>
            <p class="mb-0 text-muted">{{ __('Final bill') }} — {{ $folio->folio_no }}</p>
        </div>
        <p class="mb-1"><strong>{{ __('Guest') }}:</strong> {{ trim(($folio->stay->reservation->guest->first_name ?? '').' '.($folio->stay->reservation->guest->last_name ?? '')) }}</p>
        @if($folio->stay->reservation->room ?? null)
            <p class="mb-3"><strong>{{ __('Room') }}:</strong> {{ $folio->stay->reservation->room->room_no }}</p>
        @endif
        <table class="table table-bordered">
            <thead>
                <tr><th>{{ __('Description') }}</th><th class="text-end">{{ __('Qty') }}</th><th class="text-end">{{ __('Unit') }}</th><th class="text-end">{{ __('Total') }}</th></tr>
            </thead>
            <tbody>
                @foreach($folio->lines as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="text-end">{{ $line->quantity }}</td>
                        <td class="text-end">{{ Auth::user()->priceFormat($line->unit_price) }}</td>
                        <td class="text-end">{{ Auth::user()->priceFormat($line->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="row justify-content-end">
            <div class="col-md-5">
                <p class="d-flex justify-content-between mb-1"><span>{{ __('Subtotal') }}</span><span>{{ Auth::user()->priceFormat($folio->sub_total) }}</span></p>
                <p class="d-flex justify-content-between mb-1"><span>{{ __('Tax') }}</span><span>{{ Auth::user()->priceFormat($folio->tax_total) }}</span></p>
                <p class="d-flex justify-content-between mb-1"><span>{{ __('Discount') }}</span><span>{{ Auth::user()->priceFormat($folio->discount_total) }}</span></p>
                <hr>
                <p class="d-flex justify-content-between fw-bold mb-1"><span>{{ __('Grand total') }}</span><span>{{ Auth::user()->priceFormat($folio->grand_total) }}</span></p>
                <p class="d-flex justify-content-between mb-1"><span>{{ __('Paid') }}</span><span>{{ Auth::user()->priceFormat($folio->paid_total) }}</span></p>
                @php $bal = (float) $folio->grand_total - (float) $folio->paid_total; @endphp
                <p class="d-flex justify-content-between mb-0"><span>{{ __('Balance') }}</span><span>{{ Auth::user()->priceFormat(max(0, $bal)) }}</span></p>
            </div>
        </div>
        @if($folio->payments->isNotEmpty())
            <h6 class="mt-4">{{ __('Payments') }}</h6>
            <table class="table table-sm">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Reference') }}</th></tr></thead>
                <tbody>
                    @foreach($folio->payments as $p)
                        <tr>
                            <td>{{ $p->created_at }}</td>
                            <td>{{ Auth::user()->priceFormat($p->amount) }}</td>
                            <td>{{ $p->reference ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@push('script-page')
<script src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
<script>
(function () {
    var btn = document.getElementById('folio-final-bill-pdf');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var el = document.getElementById('folio-final-bill');
        if (!el || typeof html2pdf === 'undefined') return;
        var opt = {
            margin: [0.3, 0.3, 0.3, 0.3],
            filename: '{{ $folio->folio_no }}-final-bill.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(el).save();
    });
})();
</script>
@endpush
@endsection
