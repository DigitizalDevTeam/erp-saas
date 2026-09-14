<div class="writeoff-doc">
    <h2>{{ __('Warehouse Write-off Document') }}</h2>
    <div class="muted">{{ __('For damaged / deteriorated / expired / missing goods') }}</div>

    <div class="box">
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Write-off #') }}</div>
                <div class="value">{{ $writeOffNo }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Date') }}</div>
                <div class="value">{{ \Auth::user()->dateFormat($record->date) }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Warehouse') }}</div>
                <div class="value">{{ $record->warehouse->name ?? '-' }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Reason') }}</div>
                <div class="value">{{ $record->reason ?? '-' }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Delivery Note Required') }}</div>
                <div class="value">{{ (int) ($record->delivery_note_required ?? 0) === 1 ? __('Yes') : __('No') }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Destination / Delivery Reference') }}</div>
                <div class="value">{{ $record->delivery_destination ?? '-' }}</div>
            </div>
        </div>

        <table class="items" aria-label="{{ __('Products') }}">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Product') }}</th>
                    <th class="num">{{ __('Quantity') }}</th>
                    <th class="num">{{ __('VAT %') }}</th>
                    <th class="num">{{ __('Unit Cost (without VAT)') }}</th>
                    <th class="num">{{ __('Unit Cost (with VAT)') }}</th>
                    <th class="num">{{ __('Cost of Goods (without VAT)') }}</th>
                    <th class="num">{{ __('Cost of Goods (with VAT)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $i => $line)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $line->product->name ?? '-' }}</td>
                        <td class="num">{{ $line->quantity }}</td>
                        <td class="num">{{ number_format((float) ($line->tax_rate ?? 0), 2) }}%</td>
                        <td class="num">{{ \Auth::user()->priceFormat($line->unit_cost ?? 0) }}</td>
                        <td class="num">{{ \Auth::user()->priceFormat($line->unit_cost_with_vat ?? $line->unit_cost ?? 0) }}</td>
                        <td class="num">{{ \Auth::user()->priceFormat($line->cost_of_goods ?? 0) }}</td>
                        <td class="num">{{ \Auth::user()->priceFormat($line->cost_of_goods_with_vat ?? $line->cost_of_goods ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" style="text-align:right;">{{ __('Total Cost of Goods') }}</th>
                    <th class="num">{{ \Auth::user()->priceFormat($totalCost ?? $lines->sum(fn ($l) => (float) ($l->cost_of_goods ?? 0))) }}</th>
                    <th class="num">{{ \Auth::user()->priceFormat($totalCostWithVat ?? $lines->sum(fn ($l) => (float) ($l->cost_of_goods_with_vat ?? $l->cost_of_goods ?? 0))) }}</th>
                </tr>
            </tfoot>
        </table>

        <div class="row" style="margin-top:12px;">
            <div class="col">
                <div class="label">{{ __('Accounting note') }}</div>
                <div class="value muted">{{ __('Cost without VAT is used for accounting (breakage / inventory loss). Cost with VAT uses each product tax rate for reference.') }}</div>
            </div>
        </div>
    </div>

    <div class="sign-wrap">
        <div class="sign">
            <div class="line">{{ __('Issued By') }}: {{ $record->issuer_name ?? '-' }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Authorized By') }}: {{ $record->authorized_by ?? '-' }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Witness') }}: {{ $record->witness_name ?? '-' }}</div>
        </div>
    </div>
</div>
