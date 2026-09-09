@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Warehouse') }}</th>
                <th>{{ __('Product') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Row') }}</th>
                <th>{{ __('Shelf') }}</th>
                <th class="num">{{ __('Qty') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                <tr>
                    <td>{{ $r->warehouse_name }}</td>
                    <td>{{ $r->product_name }}</td>
                    <td>{{ $r->sku ?? '—' }}</td>
                    <td>{{ $r->row_no ?? '—' }}</td>
                    <td>{{ $r->shelf_no ?? '—' }}</td>
                    <td class="num">{{ number_format((float) $r->quantity, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">{{ __('No warehouse stock rows.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
