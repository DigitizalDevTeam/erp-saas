<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Asset Label') }} — {{ $asset->code }}</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; }
        .label {
            width: {{ $dimensions['width'] }}mm;
            min-height: {{ $dimensions['height'] }}mm;
            border: 1px solid #111;
            padding: 3mm;
            margin: 5mm auto;
            box-sizing: border-box;
        }
        .label .company { font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .label .name { font-size: 12px; font-weight: bold; margin-top: 1mm; }
        .label .code { font-size: 12px; margin-top: 1mm; }
        .label .qr { margin: 2mm 0; text-align: center; }
        .label .meta { font-size: 10px; line-height: 1.5; }
        .no-print { text-align: center; margin-top: 5mm; }
        .no-print button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    </style>
</head>
<body onload="window.print()">
    <div class="label">
        <div class="company">{{ \App\Models\User::find($asset->created_by)->name ?? '' }}</div>
        <div class="name">{{ $asset->name }}</div>
        <div class="code">{{ $asset->code }}</div>
        <div class="qr">{!! DNS2D::getBarcodeHTML($profileUrl, 'QRCODE', 3, 3) !!}</div>
        <div class="meta">
            {{ __('Category') }}: {{ $asset->category ? $asset->category->code.' — '.$asset->category->name : '—' }}<br>
            {{ __('Department') }}: {{ $asset->department->name ?? '—' }}<br>
            {{ __('Location') }}: {{ $asset->location ? $asset->location->code.' — '.$asset->location->name : '—' }}<br>
            {{ __('Custodian') }}: {{ $asset->custodian->name ?? '—' }}
        </div>
    </div>
    <div class="no-print">
        <button onclick="window.print()">{{ __('Print') }}</button>
    </div>
</body>
</html>
