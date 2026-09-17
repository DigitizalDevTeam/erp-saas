@php
    $items = $payload['items'] ?? [];
    $settings = $settings ?? ($payload['settings'] ?? []);
    $isMoz = !empty($isMoz);
@endphp
<table>
    <thead>
    <tr>
        @if($isMoz)
            <th>{{ __('Product SKU') }}</th>
            <th>{{ __('Description of Items') }}</th>
            <th class="text-end">{{ __('Qty') }}</th>
            <th class="text-end">{{ __('VAT%') }}</th>
            <th class="text-end">{{ __('Unit Price') }}</th>
            <th class="text-end">{{ __('Discount') }}</th>
            <th class="text-end">{{ __('Total Amount') }}</th>
        @else
            <th>#</th>
            <th>{{ __('Item') }}</th>
            <th class="text-end">{{ __('Qty') }}</th>
            <th class="text-end">{{ __('Price') }}</th>
            <th class="text-end">{{ __('Amount') }}</th>
        @endif
    </tr>
    </thead>
    <tbody>
    @forelse($items as $i => $item)
        @php
            $name = $item->name ?? ($item->product->name ?? ($item->product_name ?? '—'));
            $qty = (float) ($item->quantity ?? 0);
            $price = (float) ($item->price ?? 0);
            $discount = (float) ($item->discount ?? 0);
            $amount = $qty * $price - $discount;
            $sku = $item->sku ?? ($item->product->sku ?? ('ITEM-' . ($i + 1)));
            $vat = $item->tax ?? ($item->taxRate ?? 0);
        @endphp
        <tr>
            @if($isMoz)
                <td>{{ $sku }}</td>
                <td>{{ $name }}@if(!empty($item->description))<div class="dl-muted">{{ $item->description }}</div>@endif</td>
                <td class="text-end">{{ $qty }}</td>
                <td class="text-end">{{ is_numeric($vat) ? $vat : '—' }}</td>
                <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $price) }}</td>
                <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $discount) }}</td>
                <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $amount) }}</td>
            @else
                <td>{{ $i + 1 }}</td>
                <td>{{ $name }}</td>
                <td class="text-end">{{ $qty }}</td>
                <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $price) }}</td>
                <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $amount) }}</td>
            @endif
        </tr>
    @empty
        <tr><td colspan="{{ $isMoz ? 7 : 5 }}" class="dl-muted">{{ __('No items') }}</td></tr>
    @endforelse
    </tbody>
</table>
