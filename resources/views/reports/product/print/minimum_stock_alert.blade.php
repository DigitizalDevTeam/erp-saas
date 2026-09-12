@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Warehouse') }}</th>
                <th>{{ __('Product') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Type') }}</th>
                <th class="num">{{ __('Warehouse Stock') }}</th>
                <th class="num">{{ __('Low stock alert at') }}</th>
                <th class="num">{{ __('Shortage') }}</th>
                <th class="num">{{ __('Sale Price') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                @php
                    $threshold = $r->low_stock_threshold !== null ? (float) $r->low_stock_threshold : (float) $globalThreshold;
                    $stockQty = (float) $r->warehouse_quantity;
                    $shortage = max(0, $threshold - $stockQty);
                @endphp
                <tr>
                    <td>{{ $r->warehouse_name }}</td>
                    <td>{{ $r->product_name }}</td>
                    <td>{{ $r->sku ?: '—' }}</td>
                    <td>{{ $r->category_name ?: '—' }}</td>
                    <td>{{ ucfirst($r->type) }}</td>
                    <td class="num">{{ $stockQty }}</td>
                    <td class="num">{{ $threshold }}</td>
                    <td class="num">{{ $shortage }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->sale_price ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">{{ __('No products at or below the minimum stock alert.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
