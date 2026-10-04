<div class="card mt-3">
    <div class="card-header"><h6 class="mb-0">{{ __('Minimum Stock Alert') }}</h6></div>
    <div class="card-body table-border-style">
        <p class="text-muted mb-3">
            {{ __('Products with stock equal to or less than their Low stock alert at value.') }}
            {{ __('Default threshold') }}: <strong>{{ number_format((float) ($minimumStockThreshold ?? 10), 2) }}</strong>
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Warehouse') }}</th>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('SKU') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th class="text-end">{{ __('Warehouse Stock') }}</th>
                        <th class="text-end">{{ __('Low stock alert at') }}</th>
                        <th class="text-end">{{ __('Shortage') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(($minimumStockRows ?? collect()) as $r)
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
                            <td class="text-end">{{ number_format($stockQty, 2) }}</td>
                            <td class="text-end">{{ number_format($threshold, 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($shortage, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">{{ __('No products at or below the minimum stock alert.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
