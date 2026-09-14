<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Warehouse Write-off') }} - {{ $writeOffNo }}</title>
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
        @media print { .no-print { display: none; } body { margin: 15px; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:10px;">
        <button onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @include('warehouse-loss._document', [
        'record' => $record,
        'lines' => $lines,
        'writeOffNo' => $writeOffNo,
        'totalCost' => $totalCost ?? 0,
        'totalCostWithVat' => $totalCostWithVat ?? 0,
    ])
</body>
</html>
