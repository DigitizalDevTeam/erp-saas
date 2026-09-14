<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Product Stock') }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; }
        h2 { margin: 0 0 4px; }
        .muted { color: #666; font-size: 13px; }
        .meta { margin: 12px 0 16px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
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
        <a href="{{ route('productstock.index', array_filter(['warehouse_id' => $warehouseId])) }}" style="margin-left:8px;">{{ __('Back') }}</a>
    </div>

    <h2>{{ __('Product Stock') }}</h2>
    <div class="muted">{{ !empty($warehouseName) ? (__('Warehouse') . ': ' . $warehouseName) : __('All Warehouses') }}</div>
    <div class="meta">
        {{ __('Printed at') }}: {{ now()->format('Y-m-d H:i') }}
        · {{ __('Company') }}: {{ \App\Models\Utility::getValByName('company_name') ?: '—' }}
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('SKU') }}</th>
                <th class="num">{{ __('Current Quantity') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($productServices as $i => $productService)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $productService->name }}</td>
                    <td>{{ $productService->sku ?: '—' }}</td>
                    <td class="num">{{ $productService->display_quantity ?? $productService->quantity }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align:center;">{{ __('No products found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="processed">{{ __('pos.document_processed_by_computer') }}</div>
    <script>
        window.addEventListener('load', function () {
            if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
                window.print();
            }
        });
    </script>
</body>
</html>
