@extends('layouts.contractheader')
@section('page-title')
    {{ __('Experience Certificate') }}
@endsection

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-sm btn-primary" id="download-pdf-btn">
            <i class="ti ti-download"></i> {{ __('Download PDF') }}
        </button>
    </div>
    <div class="card shadow-sm">
        <div class="card-body" id="boxes">
            <div class="px-3 py-2">{!! $experience_certificate->content !!}</div>
        </div>
    </div>
</div>
@endsection

@push('script-page')
    <script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
        function downloadPdf() {
            var element = document.getElementById('boxes');
            if (!element) {
                return;
            }
            var opt = {
                filename: @json(($employees->name ?? 'experience-certificate') . '-experience-certificate.pdf'),
                margin: [0.4, 0.4, 0.4, 0.4],
                image: {type: 'jpeg', quality: 0.98},
                html2canvas: {scale: 2, useCORS: true, letterRendering: true},
                jsPDF: {unit: 'in', format: 'a4', orientation: 'portrait'}
            };
            html2pdf().set(opt).from(element).save();
        }

        $(window).on('load', function () {
            downloadPdf();
        });
        $(document).on('click', '#download-pdf-btn', function () {
            downloadPdf();
        });
    </script>
@endpush
