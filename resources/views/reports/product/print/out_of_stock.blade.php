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
                <th class="num">{{ __('Sale Price') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
                <tr>
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->sku ?: '—' }}</td>
                    <td>{{ $p->category->name ?? '—' }}</td>
                    <td>{{ ucfirst($p->type) }}</td>
                    <td class="num">{{ $p->quantity }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($p->sale_price ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ __('No out-of-stock products found.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
