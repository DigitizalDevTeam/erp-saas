{{-- UTHARA Mapa de Inventário — print layout --}}
<!DOCTYPE html>
<html lang="pt-MZ">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('Inventory Map') }}</title>
    <style>
        :root{--g:#16784a;--d:#17352a;--bg:#f4f7f5;--line:#dce5e0;--muted:#65756d;--warn:#f4b740;--red:#c94a4a}
        *{box-sizing:border-box}
        body{margin:0;font:13px Arial,Helvetica,sans-serif;background:#fff;color:#20362c}
        .wrap{max-width:210mm;margin:0 auto;padding:12mm 14mm}
        .head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;border-bottom:2px solid var(--g);padding-bottom:10px;margin-bottom:14px}
        .brand{font-size:22px;font-weight:800;letter-spacing:2px;color:var(--g)}
        .brand small{display:block;font-size:10px;letter-spacing:1px;color:var(--muted);font-weight:600}
        .head h1{margin:0;font-size:20px;color:var(--d)}
        .head p{margin:4px 0 0;color:var(--muted);font-size:12px}
        .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:0 0 14px}
        .card{border:1px solid var(--line);border-radius:8px;padding:10px 12px;background:#fff}
        .card .label{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.4px}
        .card .value{font-size:18px;font-weight:800;margin-top:4px}
        .meta{margin-bottom:10px;font-size:12px;color:var(--muted)}
        table{width:100%;border-collapse:collapse}
        th{background:#edf5f1;text-align:left;padding:8px 7px;font-size:11px;color:#3b5b4b;border-bottom:1px solid var(--line)}
        td{padding:8px 7px;border-top:1px solid var(--line);font-size:12px;vertical-align:top}
        .num{text-align:right}
        .sku{font-weight:700;color:var(--g)}
        .badge{padding:3px 7px;border-radius:12px;font-size:10px;font-weight:700;display:inline-block}
        .ok{background:#dff4e8;color:#146b41}
        .warn{background:#fff1cc;color:#8a5b00}
        .zero{background:#fce8e8;color:#a43333}
        .processed{margin-top:18px;padding-top:8px;border-top:1px solid var(--line);text-align:center;font-size:11px;color:var(--muted)}
        .no-print{text-align:right;margin-bottom:10px}
        @page{size:A4 portrait;margin:10mm}
        @media print{.no-print{display:none!important}.wrap{padding:0;max-width:none}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="no-print">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        <a href="{{ route('productstock.index', array_filter(['warehouse_id' => $warehouseId ?? null])) }}" style="margin-left:8px;">{{ __('Back') }}</a>
    </div>

    <header class="head">
        <div>
            <div class="brand">UTHARA<small>ERP SaaS</small></div>
            <h1>{{ __('Inventory Map') }}</h1>
            <p>{{ __('Consolidated stock by warehouse, product and location.') }}</p>
        </div>
        <div style="text-align:right;font-size:12px;">
            <div><strong>{{ \App\Models\Utility::getValByName('company_name') ?: config('app.name') }}</strong></div>
            <div>{{ !empty($warehouseName) ? (__('Warehouse') . ': ' . $warehouseName) : __('All Warehouses') }}</div>
            <div>{{ __('Printed at') }}: {{ now()->format('Y-m-d H:i') }}</div>
        </div>
    </header>

    @php
        $articleCount = isset($kpis['articles']) ? $kpis['articles'] : count($productServices ?? []);
        $stockValue = $kpis['stock_value'] ?? 0;
        $toRestock = $kpis['below_min'] ?? 0;
        $outOfStock = $kpis['out_of_stock'] ?? 0;
        $priceUser = Auth::user();
        $fmt = function ($n) use ($priceUser) {
            return $priceUser && method_exists($priceUser, 'priceFormat')
                ? $priceUser->priceFormat($n)
                : number_format((float)$n, 2, ',', '.');
        };
    @endphp

    <section class="cards">
        <div class="card"><div class="label">{{ __('Articles') }}</div><div class="value">{{ number_format($articleCount, 0, ',', '.') }}</div></div>
        <div class="card"><div class="label">{{ __('Stock value') }}</div><div class="value">{{ $fmt($stockValue) }}</div></div>
        <div class="card"><div class="label">{{ __('To restock') }}</div><div class="value" style="color:#a96e00">{{ $toRestock }}</div></div>
        <div class="card"><div class="label">{{ __('Out of stock') }}</div><div class="value" style="color:var(--red)">{{ $outOfStock }}</div></div>
    </section>

    <table>
        <thead>
            <tr>
                <th>{{ __('Code') }}</th>
                <th>{{ __('Product / Description') }}</th>
                <th>{{ __('Warehouse') }}</th>
                <th>{{ __('Location') }}</th>
                <th class="num">{{ __('Stock') }}</th>
                <th class="num">{{ __('Minimum') }}</th>
                <th class="num">{{ __('Value') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($rows ?? [] as $row)
            <tr>
                <td class="sku">{{ $row['sku'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['warehouse'] }}</td>
                <td>{{ $row['location'] }}</td>
                <td class="num">{{ $row['stock'] }}</td>
                <td class="num">{{ $row['minimum'] }}</td>
                <td class="num">{{ $row['value_fmt'] }}</td>
                <td><span class="badge {{ $row['badge_class'] }}">{{ $row['status'] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center;">{{ __('No products found.') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="processed">{{ __('pos.document_processed_by_computer') }}</div>
</div>
<script>
window.addEventListener('load', function () {
    if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
        window.print();
    }
});
</script>
</body>
</html>
