<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Store Check') }} #{{ $audit->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111; margin: 24px; }
        h2 { margin: 0 0 8px; }
        .meta { margin-bottom: 14px; color: #444; font-size: 13px; }
        .meta span { display: inline-block; margin-right: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #f5f5f5; }
        .num { text-align: right; }
        @media print { .no-print { display: none; } body { margin: 12px; } }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:right;margin-bottom:10px;">
        <button onclick="window.print()">{{ __('Print') }}</button>
    </div>

    <h2>{{ __('Store Check') }} #{{ $audit->id }}</h2>
    <div class="meta">
        <span><strong>{{ __('Check Date') }}:</strong> {{ $audit->check_date ? \Auth::user()->dateFormat($audit->check_date) : \Auth::user()->dateFormat($audit->created_at) }}</span>
        <span><strong>{{ __('Warehouse') }}:</strong> {{ $audit->warehouse->name ?? '—' }}</span>
        <span><strong>{{ __('Period') }}:</strong> {{ ucfirst($audit->period_type) }}</span>
    </div>
    @if($audit->notes)
        <div class="meta"><strong>{{ __('Notes') }}:</strong> {{ $audit->notes }}</div>
    @endif

    <table>
        <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Row') }}</th>
                <th>{{ __('Column') }}</th>
                <th class="num">{{ __('System quantity') }}</th>
                <th class="num">{{ __('Physical quantity') }}</th>
                <th class="num">{{ __('Difference') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($audit->lines as $line)
                <tr>
                    <td>{{ $line->product_name }}</td>
                    <td>{{ $line->sku ?: '—' }}</td>
                    <td>{{ $line->row_no ?: '—' }}</td>
                    <td>{{ $line->shelf_no ?: '—' }}</td>
                    <td class="num">{{ number_format((float) $line->system_quantity, 2) }}</td>
                    <td class="num">{{ $line->physical_quantity !== null ? number_format((float) $line->physical_quantity, 2) : '—' }}</td>
                    <td class="num">{{ $line->difference !== null ? number_format((float) $line->difference, 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
