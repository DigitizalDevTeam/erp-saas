<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Warehouse Write-off') }} - {{ __('Combined Print') }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111; margin: 30px; }
        h2 { margin-bottom: 6px; }
        .muted { color: #555; }
        .box { border: 1px solid #ddd; padding: 12px; margin-top: 12px; }
        .row { display: flex; gap: 12px; margin-bottom: 8px; }
        .col { flex: 1; }
        .label { font-size: 12px; color: #666; text-transform: uppercase; }
        .value { font-size: 14px; margin-top: 3px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 13px; }
        table.items th { background: #f5f5f5; }
        table.items .num { text-align: right; }
        .sign-wrap { margin-top: 32px; display: flex; gap: 20px; }
        .sign { flex: 1; }
        .line { margin-top: 38px; border-top: 1px solid #333; padding-top: 6px; font-size: 12px; }
        .writeoff-page { page-break-after: always; }
        .writeoff-page:last-child { page-break-after: auto; }
        @media print { .no-print { display: none; } body { margin: 15px; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:10px;">
        <button onclick="window.print()">{{ __('Print') }}</button>
        <span class="muted" style="margin-left:10px;">{{ count($documents) }} {{ __('document(s)') }}</span>
    </div>

    @foreach($documents as $doc)
        <div class="writeoff-page">
            @include('warehouse-loss._document', [
                'record' => $doc['record'],
                'lines' => $doc['lines'],
                'writeOffNo' => $doc['writeOffNo'],
            ])
        </div>
    @endforeach
</body>
</html>
