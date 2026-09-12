<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Warehouse Transfer') }} - {{ $batchLabel }}</title>
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
        .sign-wrap { margin-top: 40px; display: flex; gap: 24px; }
        .sign { flex: 1; }
        .line { margin-top: 42px; border-top: 1px solid #333; padding-top: 6px; font-size: 12px; }
        .receiver-block { margin-top: 36px; border: 1px solid #ddd; padding: 14px 16px; }
        .receiver-block h3 { margin: 0 0 12px; font-size: 15px; }
        .receiver-fields { display: flex; gap: 24px; }
        .receiver-fields .field { flex: 1; }
        .receiver-fields .blank { margin-top: 36px; border-top: 1px solid #333; padding-top: 6px; font-size: 12px; min-height: 1.2em; }
        @media print { .no-print { display: none; } body { margin: 15px; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:10px;">
        <button onclick="window.print()">{{ __('Print') }}</button>
    </div>

    <h2>{{ __('Warehouse Transfer Document') }}</h2>
    <div class="muted">{{ __('Stock transfer between warehouses') }}</div>

    <div class="box">
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Batch') }}</div>
                <div class="value">{{ $batchLabel }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Date') }}</div>
                <div class="value">{{ \Auth::user()->dateFormat($record->date) }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('From Warehouse') }}</div>
                <div class="value">{{ $record->fromWarehouse->name ?? '-' }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('To Warehouse') }}</div>
                <div class="value">{{ $record->toWarehouse->name ?? '-' }}</div>
            </div>
        </div>

        <table class="items" aria-label="{{ __('Products') }}">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Product') }}</th>
                    <th class="num">{{ __('Quantity') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $i => $line)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $line->product->name ?? '-' }}</td>
                        <td class="num">{{ $line->quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sign-wrap">
        <div class="sign">
            <div class="line">{{ __('Issued By') }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Authorized By') }}</div>
        </div>
    </div>

    <div class="receiver-block">
        <h3>{{ __('Receiver Confirmation') }}</h3>
        <div class="receiver-fields">
            <div class="field">
                <div class="blank">{{ __('Receiver Name') }}</div>
            </div>
            <div class="field">
                <div class="blank">{{ __('Signature') }}</div>
            </div>
            <div class="field">
                <div class="blank">{{ __('Date') }}</div>
            </div>
        </div>
    </div>
</body>
</html>
