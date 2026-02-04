<table class="table table-sm table-bordered">
    <thead>
        <tr>
            <th>{{ __('Item') }}</th>
            <th>{{ __('Price') }}</th>
            <th>{{ __('Quantity') }}</th>
            <th>{{ __('Return Quantity') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($bill->items as $item)
            <tr>
                <td>{{ $item->product->name ?? $item->name }}</td>
                <td>{{ auth()->user()->priceFormat($item->price) }}</td>
                <td>{{ $item->quantity }}</td>
                <td>
                    <input type="number" name="items[{{ $item->product->id }}]" min="0" value="0" max="{{ $item->quantity }}" step="1" class="form-control form-control-sm returnQty" data-price="{{ $item->price }}" style="width:80px">
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
