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
    $grandTotal = $subTotal - $discountTotal + (float) ($quotation->totalTaxPrice ?? 0);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Quotation') }} - {{ \App\Models\Utility::quotationNumberFormat($settings, $quotation->quotation_id) }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 12px; font-family: Arial, Helvetica, sans-serif; color: #111; background: #fff; }
        .wrap { max-width: 420px; margin: 0 auto; }
        .center { text-align: center; }
        .logo { max-width: 110px; max-height: 60px; margin-bottom: 6px; }
        .sep { border-bottom: 1px dotted #333; margin: 8px 0; }
        .kv { display: flex; justify-content: space-between; gap: 8px; margin: 2px 0; font-size: 13px; color: #111; }
        .kv .k { font-weight: 700; white-space: nowrap; }
        .kv .v { text-align: right; word-break: break-word; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; color: #111; }
        th { background: #111; color: #fff; padding: 5px; text-align: left; }
        td { padding: 5px; border-bottom: 1px solid #ddd; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        h1 { font-size: 16px; margin: 8px 0; color: #111; }
        @media print { body { padding: 0; } .wrap { max-width: 80mm; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="center">
        @if(!empty($img))
            <img class="logo" src="{{ $img }}" alt="Logo">
        @endif
        <div style="font-weight:700;font-size:15px;">{{ $settings['company_name'] ?? '' }}</div>
        @if($companyVat !== '')
            <div>{{ $settings['tax_type'] ?? 'VAT' }}: {{ $companyVat }}</div>
        @endif
        @if($companyAddressLine !== '')
            <div>{{ $companyAddressLine }}</div>
        @endif
        <div>
            @if($companyPhone !== ''){{ $companyPhone }}@endif
            @if($companyPhone !== '' && $companyEmail !== '') | @endif
            @if($companyEmail !== ''){{ $companyEmail }}@endif
        </div>
        <h1>{{ __('Quotation') }}</h1>
    </div>

    <div class="sep"></div>

    <div class="kv"><span class="k">{{ __('Number') }}:</span><span class="v">{{ \App\Models\Utility::quotationNumberFormat($settings, $quotation->quotation_id) }}</span></div>
    <div class="kv"><span class="k">{{ __('Issue Date') }}:</span><span class="v">{{ \App\Models\Utility::dateFormat($settings, $quotation->quotation_date) }}</span></div>

    <div class="sep"></div>

    <div class="kv"><span class="k">{{ __('Customer') }}:</span><span class="v">{{ $customerName }}</span></div>
    @if($customerVat !== '')
        <div class="kv"><span class="k">{{ __('VAT Number') }}:</span><span class="v">{{ $customerVat }}</span></div>
    @endif
    @if($customerAddress !== '')
        <div class="kv"><span class="k">{{ __('Address') }}:</span><span class="v">{{ $customerAddress }}</span></div>
    @endif
    @if($customerPhone !== '')
        <div class="kv"><span class="k">{{ __('Phone') }}:</span><span class="v">{{ $customerPhone }}</span></div>
    @endif
    @if($customerEmail !== '')
        <div class="kv"><span class="k">{{ __('Email') }}:</span><span class="v">{{ $customerEmail }}</span></div>
    @endif

    <div class="sep"></div>

    <table>
        <thead>
        <tr>
            <th>{{ __('Product') }}</th>
            <th class="num">{{ __('Price') }}</th>
            <th class="num">{{ __('Qty') }}</th>
            <th class="num">{{ __('Total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach(($quotation->itemData ?? []) as $item)
            @php
                $line = ((float) $item->price) * ((float) $item->quantity) - ((float) $item->discount);
            @endphp
            <tr>
                <td>
                    <strong>{{ $item->name }}</strong>
                    @if(!empty($item->description))<div>{{ $item->description }}</div>@endif
                </td>
                <td class="num">{{ \App\Models\Utility::priceFormat($settings, $item->price) }}</td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">{{ \App\Models\Utility::priceFormat($settings, $line) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top:8px;">
        <div class="kv"><span class="k">{{ __('Sub Total') }}</span><span class="v">{{ \App\Models\Utility::priceFormat($settings, $subTotal) }}</span></div>
        <div class="kv"><span class="k">{{ __('Discount') }}</span><span class="v">{{ \App\Models\Utility::priceFormat($settings, $discountTotal) }}</span></div>
        <div class="kv"><span class="k">{{ __('Tax') }}</span><span class="v">{{ \App\Models\Utility::priceFormat($settings, $quotation->totalTaxPrice ?? 0) }}</span></div>
        <div class="kv"><span class="k"><b>{{ __('Grand Total') }}</b></span><span class="v"><b>{{ \App\Models\Utility::priceFormat($settings, $grandTotal) }}</b></span></div>
    </div>
</div>
</body>
</html>
