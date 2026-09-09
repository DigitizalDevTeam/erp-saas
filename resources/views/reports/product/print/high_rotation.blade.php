@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Rank') }}</th>
                <th>{{ __('ID') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="num">{{ __('Qty sold') }}</th>
                <th class="num">{{ __('Movements') }}</th>
                <th class="num">{{ __('Est. sale value') }}</th>
                <th class="num">{{ __('Current stock') }}</th>
                <th class="num">{{ __('Rotation') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r->product_id }}</td>
                    <td>{{ $r->sku ?: '—' }}</td>
                    <td>{{ $r->description ?: '—' }}</td>
                    <td class="num">{{ number_format((float) $r->qty_sold, 2) }}</td>
                    <td class="num">{{ $r->movements }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->sale_value) }}</td>
                    <td class="num">{{ number_format((float) $r->current_stock, 2) }}</td>
                    <td class="num">{{ number_format((float) $r->rotation, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">{{ __('No sales movements found for the selected period.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
