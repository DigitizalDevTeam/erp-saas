@if(($minimumStockRows ?? collect())->isNotEmpty())
<h2 class="shift-a4-section">{{ __('Minimum Stock Alert') }}</h2>
<p>{{ __('Products with stock equal to or less than their Low stock alert at value.') }} {{ __('Default threshold') }}: <strong>{{ number_format((float) ($minimumStockThreshold ?? 10), 2) }}</strong></p>
<table class="shift-a4-data">
    <thead>
        <tr>
            <th>{{ __('Warehouse') }}</th>
            <th>{{ __('Product') }}</th>
            <th>{{ __('SKU') }}</th>
            <th>{{ __('Category') }}</th>
            <th class="shift-a4-num">{{ __('Warehouse Stock') }}</th>
            <th class="shift-a4-num">{{ __('Low stock alert at') }}</th>
            <th class="shift-a4-num">{{ __('Shortage') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach(($minimumStockRows ?? collect()) as $r)
        @php
            $threshold = $r->low_stock_threshold !== null ? (float) $r->low_stock_threshold : (float) ($minimumStockThreshold ?? 10);
            $stockQty = (float) $r->warehouse_quantity;
            $shortage = max(0, $threshold - $stockQty);
        @endphp
        <tr>
            <td>{{ $r->warehouse_name }}</td>
            <td>{{ $r->product_name }}</td>
            <td>{{ $r->sku ?: '—' }}</td>
            <td>{{ $r->category_name ?: '—' }}</td>
            <td class="shift-a4-num">{{ number_format($stockQty, 2) }}</td>
            <td class="shift-a4-num">{{ number_format($threshold, 2) }}</td>
            <td class="shift-a4-num">{{ number_format($shortage, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
