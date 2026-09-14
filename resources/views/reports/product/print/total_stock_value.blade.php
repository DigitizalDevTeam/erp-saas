@extends('layouts.product_report_print')

@section('content')
    <table class="report">
        <thead>
            <tr>
                <th>{{ __('ID') }}</th>
                <th>{{ __('SKU') }}</th>
                <th>{{ __('Description') }}</th>
                <th>{{ __('Warehouse') }}</th>
                <th class="num">{{ __('QTY') }}</th>
                <th class="num">{{ __('Unit Price') }}</th>
                <th class="num">{{ __('Sale Unit Price') }}</th>
                <th class="num">{{ __('Purchase Value (without VAT)') }}</th>
                <th class="num">{{ __('Purchase Value (with VAT)') }}</th>
                <th class="num">{{ __('Sale Value (without VAT)') }}</th>
                <th class="num">{{ __('Sale Value (with VAT)') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                <tr>
                    <td>{{ $r->product_id }}</td>
                    <td>{{ $r->sku ?: '—' }}</td>
                    <td>{{ $r->description ?: '—' }}</td>
                    <td>{{ $r->warehouse_name ?: '—' }}</td>
                    <td class="num">{{ number_format((float) $r->qty, 2) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->unit_purchase_price ?? 0) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->unit_sale_price ?? 0) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->purchase_ex_vat) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->purchase_inc_vat) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->sale_ex_vat) }}</td>
                    <td class="num">{{ Auth::user()->priceFormat($r->sale_inc_vat) }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="empty">{{ __('No stock found for the selected filters.') }}</td></tr>
            @endforelse
        </tbody>
        @if($rows->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="4" class="num"><strong>{{ __('Total') }}</strong></td>
                    <td class="num"><strong>{{ number_format((float) $totals['qty'], 2) }}</strong></td>
                    <td></td>
                    <td></td>
                    <td class="num"><strong>{{ Auth::user()->priceFormat($totals['purchase_ex_vat']) }}</strong></td>
                    <td class="num"><strong>{{ Auth::user()->priceFormat($totals['purchase_inc_vat']) }}</strong></td>
                    <td class="num"><strong>{{ Auth::user()->priceFormat($totals['sale_ex_vat']) }}</strong></td>
                    <td class="num"><strong>{{ Auth::user()->priceFormat($totals['sale_inc_vat']) }}</strong></td>
                </tr>
            </tfoot>
        @endif
    </table>
@endsection
