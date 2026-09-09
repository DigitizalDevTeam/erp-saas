@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Row') }}</th>
                <th>{{ __('Column') }}</th>
                <th class="num">{{ __('System quantity') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
                <tr>
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->sku ?? '—' }}</td>
                    <td>{{ $p->row_no ?? '—' }}</td>
                    <td>{{ $p->shelf_no ?? '—' }}</td>
                    <td class="num">{{ number_format((float) ($p->quantity ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">{{ __('No products found.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
