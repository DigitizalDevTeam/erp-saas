@if(($minimumStockRows ?? collect())->isNotEmpty())
<h3>{{ __('MINIMUM STOCK ALERT') }}</h3>
@if(isset($minimumStockWarehouseNames))
    <div class="row"><span>{{ __('Warehouses') }}:</span><span>{{ count($minimumStockWarehouseNames) > 0 ? implode(', ', $minimumStockWarehouseNames) : __('None selected') }}</span></div>
@endif
    @foreach($minimumStockRows as $r)
        @php
            $threshold = $r->low_stock_threshold !== null ? (float) $r->low_stock_threshold : (float) ($minimumStockThreshold ?? 10);
            $stockQty = (float) $r->warehouse_quantity;
            $shortage = max(0, $threshold - $stockQty);
        @endphp
        <div class="b">{{ $r->product_name }}</div>
        <div class="row"><span>{{ $r->warehouse_name }}</span><span>{{ number_format($stockQty, 2) }} / {{ number_format($threshold, 2) }}</span></div>
        @if($shortage > 0)
            <div class="row"><span>{{ __('Shortage') }}:</span><span>{{ number_format($shortage, 2) }}</span></div>
        @endif
    @endforeach
@endif
