@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Type') }}</th>
                <th class="num">{{ __('Current Stock') }}</th>
                <th class="num">{{ __('Low stock alert at') }}</th>
                <th class="num">{{ __('Shortage') }}</th>
                <th class="num">{{ __('Sale Price') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
                @php
                    $threshold = $p->low_stock_threshold !== null ? (float) $p->low_stock_threshold : (float) $globalThreshold;
                    $shortage = max(0, $threshold - (float) $p->quantity);
                @endphp
                <tr>
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->sku ?: '—' }}</td>
                    <td>{{ $p->category->name ?? '—' }}</td>
                    <td>{{ ucfirst($p->type) }}</td>
                    <td class="num">{{ $p->quantity }}</td>
                    <td class="num">{{ $threshold }}</td>
                    <td class="num">{{ $shortage }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($p->sale_price ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">{{ __('No products at or below the minimum stock alert.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
