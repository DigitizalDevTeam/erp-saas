@php
    $items = $payload['items'] ?? [];
    $settings = $settings ?? ($payload['settings'] ?? []);
@endphp
<table>
    <thead>
    <tr>
        <th>#</th>
        <th>{{ __('Item') }}</th>
        <th class="text-end">{{ __('Qty') }}</th>
        <th class="text-end">{{ __('Price') }}</th>
        <th class="text-end">{{ __('Amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($items as $i => $item)
        @php
            $name = $item->name ?? ($item->product->name ?? ($item->product_name ?? '—'));
            $qty = (float) ($item->quantity ?? 0);
            $price = (float) ($item->price ?? 0);
            $amount = $qty * $price - (float) ($item->discount ?? 0);
        @endphp
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $name }}</td>
            <td class="text-end">{{ $qty }}</td>
            <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $price) }}</td>
            <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $amount) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="dl-muted">{{ __('No items') }}</td></tr>
    @endforelse
    </tbody>
</table>
