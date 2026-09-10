{{-- Shared styles / standalone chrome for POS compact receipts. Call _prepare.php from parent first. --}}
<style id="pos-receipt-styles">
    .pos-print-header .header-sep { border-bottom: 1px dotted #333; margin: 6px 0; }
    .pos-print-header .logo-box { max-width: 100px; height: 56px; margin: 0 auto 6px; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff; }
    .pos-print-header .logo-box img,
    #printarea .logo-box img,
    .modal-body #printarea .logo-box img {
        max-width: 100px !important;
        max-height: 56px !important;
        width: auto !important;
        height: auto !important;
        object-fit: contain;
    }
    .pos-compact-kv { display: flex; justify-content: space-between; gap: 8px; margin: 2px 0; color: #111; font-size: 0.9rem; }
    .pos-compact-kv .k { color: #222; font-weight: 600; white-space: nowrap; }
    .pos-compact-kv .v { text-align: right; color: #111; word-break: break-word; }
    .pos-compact-table { width: 100%; border-collapse: collapse; color: #111; font-size: 0.85rem; }
    .pos-compact-table th { background: #111; color: #fff; padding: 4px 5px; font-weight: 700; }
    .pos-compact-table td { padding: 4px 5px; border-bottom: 1px solid #ddd; color: #111; vertical-align: top; }
    .pos-compact-table .num { text-align: right; white-space: nowrap; }
    .pos-compact-section { margin: 6px 0; color: #111; }
    .pos-compact-supplier, .pos-compact-customer, .pos-compact-body { color: #111; }
    .segunda-via-watermark { position: absolute; left: 0; top: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 10; opacity: 0.12; font-size: 4rem; font-weight: bold; color: #000; transform: rotate(-35deg); white-space: nowrap; }
    .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; max-width: 100%; max-height: 100%; }
    .vat-summary-wrap { overflow-x: auto; max-width: 100%; }
    .vat-summary-wrap table { min-width: 320px; width: 100%; }
    .pos-print-layout-slip #printarea { max-width: 80mm; margin-left: auto; margin-right: auto; font-size: 0.95rem; }
    .pos-print-layout-slip .pos-print-header .logo-box { max-width: 72px; height: 40px; }
    .pos-print-layout-a5 #printarea { max-width: 148mm; margin-left: auto; margin-right: auto; }
    .pos-print-layout-a4 #printarea, .pos-print-layout-receipt #printarea { max-width: 210mm; margin-left: auto; margin-right: auto; }
    .pos-mz-header { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; margin-bottom: 8px; }
    .pos-mz-badge { background: #0CAF60; color: #fff; padding: 8px 10px; font-weight: 700; text-align: center; min-width: 140px; }
    .pos-mz-table th { background: #0CAF60; color: #fff; padding: 5px 4px; font-size: 0.78rem; }
    .pos-mz-table td { padding: 4px; font-size: 0.8rem; border-bottom: 1px solid #ccc; color: #111; }
    .pos-mz-bill { display: flex; gap: 12px; margin: 8px 0; }
    .pos-mz-bill > div { flex: 1; border: 1px solid #ddd; padding: 6px; color: #111; }
    @media print {
        body * { visibility: hidden; }
        #printarea, #printarea * { visibility: visible; }
        #printarea { position: absolute; left: 0; top: 0; width: 100%; color: #111 !important; }
        @page pos-slip { size: 80mm auto; margin: 3mm; }
        @page pos-a5 { size: A5 portrait; margin: 10mm; }
        @page pos-a4 { size: A4 portrait; margin: 10mm; }
        @page pos-receipt { margin: 8mm; }
        #printarea.pos-print-layout-slip { page: pos-slip; width: 72mm !important; max-width: 72mm !important; margin: 0 auto !important; font-size: 12px !important; }
        #printarea.pos-print-layout-a5 { page: pos-a5; width: 100% !important; max-width: 148mm !important; }
        #printarea.pos-print-layout-a4, #printarea.pos-print-layout-receipt { page: pos-receipt; width: 100% !important; max-width: 210mm !important; }
        .receipt-copy { page-break-after: always; }
        .receipt-copy:last-child { page-break-after: auto; }
        .no-print-copies { display: none !important; }
        .pos-compact-supplier, .pos-compact-customer, .pos-compact-table, .pos-compact-kv { color: #000 !important; }
    }
</style>
@if($standalone)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Receipt') }} - {{ $details['pos_id'] ?? '' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/main.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <script src="{{ asset('js/jquery.min.js') }}"></script>
</head>
<body class="pos-print-standalone pos-print-layout-{{ $printLayout }}" style="padding:1rem; background:#f5f5f5;">
@endif
