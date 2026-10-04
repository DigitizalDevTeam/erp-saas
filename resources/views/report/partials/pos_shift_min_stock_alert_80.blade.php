<h3>{{ __('MINIMUM STOCK ALERT') }}</h3>
@if(($minimumStockRows ?? collect())->isEmpty())
    <div class="c">{{ __('No products at or below the minimum stock alert.') }}</div>
@else
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
