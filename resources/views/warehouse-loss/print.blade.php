<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Warehouse Write-off') }} - {{ $record->write_off_no ?? ('WO-' . $record->id) }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111; margin: 30px; }
        h2 { margin-bottom: 6px; }
        .muted { color: #555; }
        .box { border: 1px solid #ddd; padding: 12px; margin-top: 12px; }
        .row { display: flex; gap: 12px; margin-bottom: 8px; }
        .col { flex: 1; }
        .label { font-size: 12px; color: #666; text-transform: uppercase; }
        .value { font-size: 14px; margin-top: 3px; }
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

    <h2>{{ __('Warehouse Write-off Document') }}</h2>
    <div class="muted">{{ __('For damaged / deteriorated / expired / missing goods') }}</div>

    <div class="box">
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Write-off #') }}</div>
                <div class="value">{{ $record->write_off_no ?? ('WO-' . $record->id) }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Date') }}</div>
                <div class="value">{{ \Auth::user()->dateFormat($record->date) }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Warehouse') }}</div>
                <div class="value">{{ $record->warehouse->name ?? '-' }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Product') }}</div>
                <div class="value">{{ $record->product->name ?? '-' }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Quantity') }}</div>
                <div class="value">{{ $record->quantity }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Reason') }}</div>
                <div class="value">{{ $record->reason ?? '-' }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Delivery Note Required') }}</div>
                <div class="value">{{ (int) ($record->delivery_note_required ?? 0) === 1 ? __('Yes') : __('No') }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Destination / Delivery Reference') }}</div>
                <div class="value">{{ $record->delivery_destination ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="sign-wrap">
        <div class="sign">
            <div class="line">{{ __('Issued By') }}: {{ $record->issuer_name ?? '-' }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Authorized By') }}: {{ $record->authorized_by ?? '-' }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Witness') }}: {{ $record->witness_name ?? '-' }}</div>
        </div>
    </div>
</body>
</html>

