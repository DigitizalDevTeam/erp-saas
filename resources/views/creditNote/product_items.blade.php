<table class="table table-sm table-bordered">
    <thead>
        <tr>
            <th>{{ __('Item') }}</th>
            <th>{{ __('Price') }}</th>
            <th>{{ __('Quantity') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($products as $product)
            <tr>
                <td>{{ $product->name }}</td>
                <td>{{ auth()->user()->priceFormat($product->sale_price) }}</td>
                <td>
                    <input type="number"
                           name="items[{{ $product->id }}]"
                           min="0"
                           value="0"
                           step="1"
                           class="form-control form-control-sm returnQty"
                           data-price="{{ $product->sale_price }}"
                           style="width:80px">
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="3" class="text-muted">{{ __('No products found.') }}</td>
            </tr>
        @endforelse
    </tbody>
</table>
