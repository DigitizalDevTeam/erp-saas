<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Stock Introduction') }} — {{ $intro->intro_no }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; }
        h2 { margin: 0 0 4px; }
        .muted { color: #666; font-size: 13px; }
        .box { border: 1px solid #ddd; padding: 12px; margin-top: 12px; }
        .row { display: flex; gap: 12px; margin-bottom: 8px; flex-wrap: wrap; }
        .col { flex: 1; min-width: 160px; }
        .label { font-size: 11px; color: #666; text-transform: uppercase; }
        .value { font-size: 14px; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; font-size: 13px; text-align: left; }
        th { background: #f5f5f5; }
        .num { text-align: right; }
        .processed {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 11px;
            color: #555;
        }
        @media print {
            .no-print { display: none !important; }
            body { margin: 12px; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:12px;">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        <a href="{{ route('productstock.show', $intro->id) }}" style="margin-left:8px;">{{ __('Back') }}</a>
    </div>

    <h2>{{ __('Stock Introduction') }}</h2>
    <div class="muted">{{ __('Product Stock document') }}</div>

    <div class="box">
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Document #') }}</div>
                <div class="value">{{ $intro->intro_no }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Date') }}</div>
                <div class="value">{{ Auth::user()->dateFormat($intro->intro_date) }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Warehouse') }}</div>
                <div class="value">{{ $intro->warehouse->name ?? '—' }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Notes') }}</div>
                <div class="value">{{ $intro->notes ?: '—' }}</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Product') }}</th>
                    <th>{{ __('SKU') }}</th>
                    <th class="num">{{ __('Quantity') }}</th>
                    <th>{{ __('Row') }}</th>
                    <th>{{ __('Shelf') }}</th>
                    <th>{{ __('Batch') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($intro->lines as $i => $line)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $line->product->name ?? '—' }}</td>
                        <td>{{ $line->product->sku ?? '—' }}</td>
                        <td class="num">{{ $line->quantity }}</td>
                        <td>{{ $line->row_no ?: '—' }}</td>
                        <td>{{ $line->shelf_no ?: '—' }}</td>
                        <td>{{ $line->batch_no ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="processed">{{ __('pos.document_processed_by_computer') }}</div>
</body>
</html>
