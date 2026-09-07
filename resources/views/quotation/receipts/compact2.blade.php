@php
    $companyAddressParts = array_filter([
        $settings['company_address'] ?? null,
        $settings['company_city'] ?? null,
        $settings['company_state'] ?? null,
        $settings['company_zipcode'] ?? null,
        $settings['company_country'] ?? null,
    ]);
    $companyAddressLine = implode(', ', $companyAddressParts);
    $companyPhone = $settings['company_telephone'] ?? '';
    $companyEmail = $settings['company_email'] ?? ($settings['mail_from_address'] ?? '');
    $companyVat = $settings['vat_number'] ?? '';
    $customerName = $customer ? ($customer->billing_name ?: ($customer->name ?: __('pos.walkin_customer'))) : __('pos.walkin_customer');
    $customerAddress = $customer ? trim(implode(', ', array_filter([
        $customer->billing_address ?? null,
        $customer->billing_city ?? null,
        $customer->billing_state ?? null,
        $customer->billing_zip ?? null,
        $customer->billing_country ?? null,
    ]))) : '';
    $customerPhone = $customer ? ($customer->billing_phone ?: ($customer->contact ?? '')) : '';
    $customerEmail = $customer ? ($customer->email ?? '') : '';
    $customerVat = $customer ? ($customer->tax_number ?? '') : '';
    $subTotal = 0;
    $discountTotal = 0;
    foreach (($quotation->itemData ?? []) as $taxItem) {
        $subTotal += ((float) $taxItem->price) * ((float) $taxItem->quantity);
        $discountTotal += (float) $taxItem->discount;
    }
    $taxTotal = (float) ($quotation->totalTaxPrice ?? 0);
    $grandTotal = $subTotal - $discountTotal + $taxTotal;
    $theme = $color ?? '#0CAF60';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Quotation') }} - {{ \App\Models\Utility::quotationNumberFormat($settings, $quotation->quotation_id) }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 10px; font-family: Arial, Helvetica, sans-serif; color: #111; background: #fff; }
        .sheet { max-width: 794px; margin: 0 auto; border: 1px solid #ddd; padding: 12px 14px; }
        .top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .logo { max-width: 120px; max-height: 56px; }
        .supplier, .customer, .meta { color: #111; font-size: 12px; line-height: 1.35; }
        .badge { background: {{ $theme }}; color: #fff; padding: 8px 10px; font-weight: 700; min-width: 160px; text-align: center; }
        .cols { display: flex; gap: 10px; margin: 10px 0; }
        .cols > div { flex: 1; border: 1px solid #ddd; padding: 8px; }
        .title { font-weight: 700; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; color: #111; }
        th { background: {{ $theme }}; color: #fff; padding: 5px 4px; text-align: left; }
        td { padding: 4px; border-bottom: 1px solid #ccc; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { max-width: 280px; margin-left: auto; margin-top: 8px; font-size: 12px; }
        .kv { display: flex; justify-content: space-between; gap: 8px; margin: 2px 0; color: #111; }
        @media print { body { padding: 0; } .sheet { border: none; max-width: none; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="top">
        <div class="supplier">
            @if(!empty($img))
                <img class="logo" src="{{ $img }}" alt="Logo"><br>
            @endif
            <div style="font-weight:700;font-size:14px;">{{ $settings['company_name'] ?? '' }}</div>
            @if($companyVat !== '')
                <div>{{ $settings['tax_type'] ?? 'VAT' }} Nº: {{ $companyVat }}</div>
            @endif
            @if($companyAddressLine !== '')
                <div>{{ $companyAddressLine }}</div>
            @endif
            <div>
                @if($companyPhone !== ''){{ __('Phone') }}: {{ $companyPhone }}@endif
                @if($companyPhone !== '' && $companyEmail !== '') · @endif
                @if($companyEmail !== ''){{ __('Email') }}: {{ $companyEmail }}@endif
            </div>
        </div>
        <div class="badge">
            {{ __('Quotation') }}<br>
            {{ \App\Models\Utility::quotationNumberFormat($settings, $quotation->quotation_id) }}
        </div>
    </div>

    <div class="cols">
        <div class="customer">
            <div class="title">{{ __('Bill To') }}</div>
            <div>{{ $customerName }}</div>
            @if($customerVat !== '')<div>{{ __('VAT Number') }}: {{ $customerVat }}</div>@endif
            @if($customerAddress !== '')<div>{{ $customerAddress }}</div>@endif
            @if($customerPhone !== '')<div>{{ __('Phone') }}: {{ $customerPhone }}</div>@endif
            @if($customerEmail !== '')<div>{{ __('Email') }}: {{ $customerEmail }}</div>@endif
        </div>
        <div class="meta">
            <div class="kv"><span>{{ __('Issue Date') }}</span><span>{{ \App\Models\Utility::dateFormat($settings, $quotation->quotation_date) }}</span></div>
            <div class="kv"><span>{{ __('Number') }}</span><span>{{ \App\Models\Utility::quotationNumberFormat($settings, $quotation->quotation_id) }}</span></div>
            <div class="kv"><span>{{ __('Status') }}</span><span>{{ __(ucfirst($quotation->status ?? 'draft')) }}</span></div>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:34%;">{{ __('Description') }}</th>
            <th class="num" style="width:10%;">{{ __('Qty') }}</th>
            <th class="num" style="width:12%;">{{ __('VAT') }}%</th>
            <th class="num" style="width:14%;">{{ __('Unit Price') }}</th>
            <th class="num" style="width:14%;">{{ __('Discount') }}</th>
            <th class="num" style="width:16%;">{{ __('Total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach(($quotation->itemData ?? []) as $item)
            @php
                $lineGross = ((float) $item->price) * ((float) $item->quantity);
                $lineNet = $lineGross - ((float) $item->discount);
                $vatRate = 0;
                if (!empty($item->itemTax)) {
                    foreach ($item->itemTax as $t) {
                        $vatRate += (float) preg_replace('/[^0-9.]/', '', (string) ($t['rate'] ?? 0));
                    }
                }
            @endphp
            <tr>
                <td>
                    {{ $item->name }}
                    @if(!empty($item->description))<div>{{ $item->description }}</div>@endif
                </td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">{{ $vatRate }}</td>
                <td class="num">{{ \App\Models\Utility::priceFormat($settings, $item->price) }}</td>
                <td class="num">{{ \App\Models\Utility::priceFormat($settings, $item->discount) }}</td>
                <td class="num">{{ \App\Models\Utility::priceFormat($settings, $lineNet) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="kv"><span>{{ __('Sub Total') }}</span><span>{{ \App\Models\Utility::priceFormat($settings, $subTotal) }}</span></div>
        <div class="kv"><span>{{ __('Discount') }}</span><span>{{ \App\Models\Utility::priceFormat($settings, $discountTotal) }}</span></div>
        <div class="kv"><span>{{ __('Tax') }} / IVA</span><span>{{ \App\Models\Utility::priceFormat($settings, $taxTotal) }}</span></div>
        <div class="kv"><span><b>{{ __('Grand Total') }}</b></span><span><b>{{ \App\Models\Utility::priceFormat($settings, $grandTotal) }}</b></span></div>
    </div>
</div>
</body>
</html>
