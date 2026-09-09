@php
    $settings_data = \App\Models\Utility::settingsById($bill->created_by);
    $subTotal = 0;
    $discountTotal = 0;
    $taxTotal = 0;
    if(isset($bill->itemData) && count($bill->itemData) > 0) {
        foreach($bill->itemData as $taxItem) {
            $lineGross = (float)$taxItem->price * (float)$taxItem->quantity;
            $subTotal += $lineGross;
            $discountTotal += (float)$taxItem->discount;
            if(!empty($taxItem->itemTax)) {
                foreach($taxItem->itemTax as $lineTax) {
                    $taxTotal += (float)($lineTax['tax_price'] ?? 0);
                }
            }
        }
    } else {
        $subTotal = $bill->getSubTotal();
        $discountTotal = $bill->getTotalDiscount();
        $taxTotal = $bill->getTotalTax();
    }
    $subTotalAfterDiscount = $subTotal - $discountTotal;
    $grandTotal = $subTotalAfterDiscount + $taxTotal;
    $debitNoteAmount = method_exists($bill, 'billTotalDebitNote') ? $bill->billTotalDebitNote() : 0;
    $paidAmount = method_exists($bill, 'getTotal') && method_exists($bill, 'getDue') ? max(0, ($bill->getTotal() - $bill->getDue()) - $debitNoteAmount) : 0;
    $dueAmount = method_exists($bill, 'getDue') ? $bill->getDue() : max(0, $grandTotal - $paidAmount);
    $overallDiscountPercent = $subTotal > 0 ? ($discountTotal * 100) / $subTotal : 0;
    $amountInWords = \App\Models\Utility::amountInWords(round($dueAmount, 2));
    $companyBankAccounts = \App\Models\BankAccount::where('created_by', $bill->created_by)
        ->orderBy('id', 'asc')
        ->limit(2)
        ->get();
    $vatBreakdown = [];
    if(isset($bill->itemData) && count($bill->itemData) > 0) {
        foreach($bill->itemData as $taxItem) {
            $lineGross = (float)$taxItem->price * (float)$taxItem->quantity;
            $lineNetAfterDiscount = $lineGross - (float)$taxItem->discount;
            if(!empty($taxItem->itemTax)) {
                foreach($taxItem->itemTax as $lineTax) {
                    $rate = (float)($lineTax['rate'] ?? 0);
                    $taxAmount = (float)($lineTax['tax_price'] ?? 0);
                    $taxName = (string)($lineTax['name'] ?? ($rate . '%'));
                    $rateKey = (string)$rate;
                    if(!isset($vatBreakdown[$rateKey])) {
                        $vatBreakdown[$rateKey] = [
                            'name' => $taxName,
                            'rate' => $rate,
                            'net' => 0.0,
                            'tax' => 0.0,
                        ];
                    }
                    $estimatedNet = $rate > 0 ? ($taxAmount * 100) / $rate : 0;
                    if($estimatedNet <= 0) {
                        $estimatedNet = $lineNetAfterDiscount;
                    }
                    $vatBreakdown[$rateKey]['net'] += $estimatedNet;
                    $vatBreakdown[$rateKey]['tax'] += $taxAmount;
                }
            }
        }
    }
    ksort($vatBreakdown, SORT_NUMERIC);
    $billDate = $bill->bill_date ?? $bill->issue_date ?? null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="{{$settings_data['SITE_RTL'] == 'on' ? 'rtl' : ''}}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet">
    <style type="text/css">
        :root {
            --theme-color: {{ $color }};
            --dark: #101114;
            --light-bg: #efefef;
            --line: #d5d5d5;
            --text: #1a1a1a;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Lato', sans-serif; color: var(--text); background: #fff; }
        table { width: 100%; border-collapse: collapse; }
        .invoice-preview-main {
            width: 794px;
            margin: 0 auto;
            background: #fff;
            position: relative;
            overflow: hidden;
            border: 1px solid #dedede;
        }
        .top-bar { height: 45px; background: var(--dark); position: relative; }
        .top-bar:before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 275px;
            height: 45px;
            background: var(--theme-color);
            clip-path: polygon(0 0, 74% 0, 100% 100%, 0 100%);
        }
        .sheet { padding: 18px 24px 14px; }
        .top-grid td { vertical-align: top; }
        .logo-box { width: 200px; }
        .logo-box img { max-width: 180px; max-height: 90px; }
        .address-pill {
            display: inline-block;
            min-width: 330px;
            border: 2px solid #2f2f2f;
            border-radius: 14px;
            padding: 9px 18px;
            font-size: 14px;
            line-height: 1.15;
            font-weight: 900;
            margin-top: 2px;
            text-transform: uppercase;
        }
        .invoice-title { text-align: right; width: 168px; }
        .invoice-title-box {
            background: var(--theme-color);
            color: #fff;
            border-radius: 9px;
            padding: 10px 12px 9px;
            text-align: left;
            width: 162px;
            min-height: 72px;
            font-family: DejaVu Sans, Arial, sans-serif;
        }
        .invoice-title-box .title {
            color: #ffffff !important;
            font-size: 33px;
            line-height: 1;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            margin-bottom: 6px;
        }
        .invoice-title-box .inv-no {
            color: #ffffff !important;
            font-size: 16px;
            line-height: 1.2;
            font-weight: 700;
            white-space: nowrap;
        }
        .meta-grid { margin-top: 14px; }
        .meta-grid td { vertical-align: top; width: 33.33%; padding-right: 12px; }
        .block-title { font-size: 12px; font-weight: 900; text-transform: uppercase; margin-bottom: 4px; }
        .meta-name { font-size: 20px; line-height: 0.95; font-weight: 900; margin-bottom: 2px; }
        .meta-sub { font-size: 14px; margin-bottom: 15px; }
        .meta-lines td { font-size: 11px; line-height: 1.28; padding: 0; vertical-align: top; }
        .meta-lines .key { font-weight: 900; }
        .invoice-side { width: 100%;margin-top: 25px; }
        .invoice-side td { font-size: 12px; padding: 0 0 2px 0; }
        .invoice-side .k { font-weight: 900; width: 86px; text-align: right; padding-right: 8px; }
        .invoice-side .v { text-align: left; }
        .due-line { margin-top: 5px; text-align: right; margin-bottom: 3px; }
        .due-line .label { font-size: 12px; font-weight: 900; text-transform: uppercase; }
        .due-line .value { font-size: 28px; color: var(--theme-color); font-weight: 900; line-height: 0.9; }
        .items-table { margin-top: 12px; background: #fff; }
        .items-table th {
            background: var(--theme-color);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 7px;
            text-align: center;
            border-right: 1px solid #fff;
            text-transform: uppercase;
        }
        .items-table td {
            font-size: 14px;
            padding: 5px 6px;
            text-align: center;
            border-bottom: 1px solid #e3e3e3;
        }
        .items-table .desc { text-align: center; font-weight: 700; }
        .amount-words {
            margin-bottom: 20px;
            background: #d7d7d7;
            border-radius: 6px;
            padding: 30px 14px;
            font-size: 13px;
            width: 100%;
            text-transform: uppercase;
            font-weight: 600;
        }
        .after-table { margin-top: 25px; }
        .comments-box { vertical-align: top; }
        .comments-title { font-size: 28px; font-weight: 900; text-transform: uppercase; margin-bottom: 4px; }
        .mini-tax { width: 95%; }
        .mini-tax th, .mini-tax td {
            padding: 2px 4px;
            font-size: 9px;
            text-align: center;
            border-bottom: 1px solid #dadada;
        }
        .mini-tax th { color: #214057; font-weight: 900; text-transform: uppercase; }
        .totals-box { width: 34%; vertical-align: top; padding-left: 14px; }
        .discount-chip {
            background: #111;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            padding: 9px 9px;
            border-radius: 3px;
            margin-bottom: 5px;
        }
        .totals-table td { font-size: 12px; padding: 3px 0; }
        .totals-table .v { text-align: right; font-weight: 700; }
        .total-final {
            margin-top: 3px;
            background: var(--theme-color);
            color: #fff;
            font-weight: 900;
            border-radius: 3px;
        }
        .total-final td, .discount-chip td { padding: 7px 7px; font-size: 14px; }
        .bank-area { margin-top: 9px; }
        .bank-area td { vertical-align: top; }
        .bank-accounts-wrap { width: 72%; padding-right: 8px; }
        .bank-accounts-grid { width: 100%; }
        .bank-accounts-grid td { width: 50%; vertical-align: top; padding-right: 12px; }
        .bank-block h4 { font-size: 24px; font-weight: 900; margin-bottom: 4px; }
        .bank-block p { font-size: 10px; line-height: 1.32; text-transform: uppercase; }
        .sign-wrap { text-align: right; width: 28%; }
        .sign-line {
            border-top: 1px solid #cfcfcf;
            width: 155px;
            display: inline-block;
            margin-bottom: 4px;
            margin-top: 70px;
        }
        .sign-label { font-size: 14px; margin-bottom: 2px;font-weight: 600; }
        .qr-box {
            width: 57px;
            height: 57px;
            margin-left: auto;
            background: #fff;
            padding: 2px;
        }
        .bottom-bar {
            height: 54px;
            background: var(--dark);
            color: #fff;
            position: relative;
            padding: 8px 12px;
            font-size: 10px;
            line-height: 1.2;
            font-weight: 700;
        }
        .bottom-bar:before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 270px;
            height: 54px;
            background: var(--theme-color);
            clip-path: polygon(0 0, 74% 0, 100% 100%, 0 100%);
        }
        .bottom-left, .bottom-right { position: relative; z-index: 2; }
        .bottom-left { float: left; max-width: 45%; font-size: 12px;}
        .bottom-right { float: right; text-align: right; max-width: 50%; font-size: 13px;}
        .clearfix { clear: both; }
    </style>
    @if($settings_data['SITE_RTL']=='on')
        <link rel="stylesheet" href="{{ asset('css/bootstrap-rtl.css') }}">
    @endif
</head>
<body>
<div class="invoice-preview-main" id="boxes">
    <div class="top-bar"></div>
    <div class="sheet">
        <table class="top-grid">
            <tr>
                <td class="logo-box">
                    <img src="{{$img}}" alt="logo">
                </td>
                <td>
                    <div class="address-pill">
                        {{$settings['company_address'] ?? ''}} {{$settings['company_city'] ?? ''}}<br>
                        {{$settings['vat_number'] ?? ''}}
                    </div>
                </td>
                <td class="invoice-title">
                    <div class="invoice-title-box">
                        <span class="title">{{ __('Bill') }}</span>
                        <span class="inv-no">{{ __('No.') ?: 'No.' }} {{ Utility::billNumberFormat($settings, $bill->bill_id) }}</span>
                    </div>
                </td>
            </tr>
        </table>

        <table class="meta-grid">
            <tr>
                <td>
                    <div class="block-title">{{__('Bill To')}}:</div>
                    <div class="meta-name">{{ __('Vendor Name') }}:</div>
                    <div class="meta-sub">{{!empty($vendor->billing_name) ? $vendor->billing_name : '-'}}</div>
                    <table class="meta-lines">
                        <tr><td class="key">{{__('Vendor Address')}}:</td><td>{{ trim(($vendor->billing_address ?? '').' '.($vendor->billing_city ?? '').' '.($vendor->billing_state ?? '').' '.($vendor->billing_country ?? '')) }}</td></tr>
                        @if(!empty($vendor->tax_number))
                            <tr><td class="key">{{__('Tax Number')}}:</td><td>{{ $vendor->tax_number }}</td></tr>
                        @endif
                    </table>
                </td>
                <td>
                    <div class="block-title">{{__('Ship To')}}:</div>
                    <div class="meta-name">{{ __('Vendor Name') }}:</div>
                    <div class="meta-sub">{{!empty($vendor->shipping_name) ? $vendor->shipping_name : (!empty($vendor->billing_name) ? $vendor->billing_name : '-')}}</div>
                    <table class="meta-lines">
                        <tr><td class="key">{{__('Vendor Address')}}:</td><td>{{ trim(($vendor->shipping_address ?? '').' '.($vendor->shipping_city ?? '').' '.($vendor->shipping_state ?? '').' '.($vendor->shipping_country ?? '')) }}</td></tr>
                    </table>
                </td>
                <td>
                    <div class="due-line">
                        <div class="label">{{__('Due')}}:</div>
                        <div class="value">{{Utility::priceFormat($settings,$dueAmount)}}</div>
                    </div>
                    <table class="invoice-side">
                        <tr><td class="k">{{__('Number')}}:</td><td class="v">{{ Utility::billNumberFormat($settings, $bill->bill_id) }}</td></tr>
                        <tr><td class="k">{{__('Date')}}:</td><td class="v">{{ $billDate ? Utility::dateFormat($settings, $billDate) : '-' }}</td></tr>
                        @if(!empty($bill->due_date))
                            <tr><td class="k">{{__('Due Date')}}:</td><td class="v">{{Utility::dateFormat($settings,$bill->due_date)}}</td></tr>
                        @endif
                        <tr><td class="k">{{__('Overall Discount %')}}:</td><td class="v">{{number_format($overallDiscountPercent, 2)}}%</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
            <tr>
                <th style="width:13%">{{__('Product SKU')}}</th>
                <th style="width:31%;text-align: left;padding-left: 10px;">{{__('Description Of Items')}}</th>
                <th style="width:8%">{{__('Qty')}}</th>
                <th style="width:12%">{{__('VAT%')}}</th>
                <th style="width:12%">{{__('Unit Price')}}</th>
                <th style="width:10%">{{__('Discount')}}</th>
                <th style="width:15%">{{__('Total Amount')}}</th>
            </tr>
            </thead>
            <tbody>
            @if(isset($bill->itemData) && count($bill->itemData) > 0)
                @foreach($bill->itemData as $item)
                    @php
                        $itemTaxRateText = '-';
                        $itemTaxAmount = 0;
                        if(!empty($item->itemTax) && is_iterable($item->itemTax)) {
                            $taxRates = [];
                            foreach($item->itemTax as $taxes) {
                                $itemTaxAmount += $taxes['tax_price'];
                                $taxRates[] = is_numeric($taxes['rate']) ? $taxes['rate'].'%' : $taxes['rate'];
                            }
                            $itemTaxRateText = implode(', ', $taxRates);
                        }
                        $lineTotal = ($item->price * $item->quantity) - $item->discount + $itemTaxAmount;
                    @endphp
                    <tr>
                        <td>{{ $item->sku ?? $item->product_code ?? $item->item_code ?? $item->id ?? '-' }}</td>
                        <td class="desc" style="text-align: left;padding-left: 10px;">{{$item->name}}</td>
                        <td>{{$item->quantity}}</td>
                        <td>{{$itemTaxRateText}}</td>
                        <td>{{Utility::priceFormat($settings,$item->price)}}</td>
                        <td>{{Utility::priceFormat($settings,$item->discount)}}</td>
                        <td>{{Utility::priceFormat($settings,$lineTotal)}}</td>
                    </tr>
                    @if(!empty($item->description))
                        <tr>
                            <td></td>
                            <td style="text-align: left; font-size: 12px; padding-top: 0;" colspan="5">{{$item->description}}</td>
                        </tr>
                    @endif
                @endforeach
            @endif
            </tbody>
        </table>

        <table class="after-table">
            <tr>
                <td colspan="2" style="vertical-align: top;">
                    <div class="amount-words">
                        {{__('Amount In Words')}}: {{$amountInWords}}
                    </div>
                    <table style="width: 100%;">
                        <tr>
                            <td style="vertical-align: top;">
                                <div class="comments-title">{{__('Comments')}}:</div>
                            </td>
                            <td class="comments-box" colspan="2">
                                <table class="mini-tax">
                                    <thead>
                                        <tr>
                                            <th>{{__('VAT %')}}</th>
                                            <th>{{__('Net Amount')}}</th>
                                            <th>{{__('VAT')}}</th>
                                            <th>{{__('Amount')}}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @if(!empty($vatBreakdown))
                                        @foreach($vatBreakdown as $vatRow)
                                            <tr>
                                                <td>{{number_format($vatRow['rate'], 0)}}%</td>
                                                <td>{{Utility::priceFormat($settings,$vatRow['net'])}}</td>
                                                <td>{{Utility::priceFormat($settings,$vatRow['tax'])}}</td>
                                                <td>{{Utility::priceFormat($settings,$vatRow['net'] + $vatRow['tax'])}}</td>
                                            </tr>
                                        @endforeach
                                    @else
                                        <tr>
                                            <td>-</td>
                                            <td>{{Utility::priceFormat($settings,$subTotalAfterDiscount)}}</td>
                                            <td>{{Utility::priceFormat($settings,0)}}</td>
                                            <td>{{Utility::priceFormat($settings,$subTotalAfterDiscount)}}</td>
                                        </tr>
                                    @endif
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="totals-box">
                    <table class="discount-chip">
                        <tr><td>{{__('Total Discount')}}:</td><td style="text-align:right;">{{Utility::priceFormat($settings,$discountTotal)}}</td></tr>
                    </table>
                    <table class="totals-table">
                        <tr><td>{{__('Sub Total')}}:</td><td class="v">{{Utility::priceFormat($settings,$subTotal)}}</td></tr>
                        <tr><td>{{__('Discount')}}:</td><td class="v">{{Utility::priceFormat($settings,$discountTotal)}}</td></tr>
                        <tr><td>{{__('Subtotal After Discount')}}:</td><td class="v">{{Utility::priceFormat($settings,$subTotalAfterDiscount)}}</td></tr>
                        <tr><td>{{__('Net Amount')}}:</td><td class="v">{{Utility::priceFormat($settings,$subTotalAfterDiscount)}}</td></tr>
                        <tr><td>{{__('VAT')}}:</td><td class="v">{{Utility::priceFormat($settings,$taxTotal)}}</td></tr>
                        <tr><td>{{__('Total Amount')}}:</td><td class="v">{{Utility::priceFormat($settings,$grandTotal)}}</td></tr>
                        <tr><td>{{__('Paid')}}:</td><td class="v">{{Utility::priceFormat($settings,$paidAmount)}}</td></tr>
                        <tr><td>{{__('Debit Note')}}:</td><td class="v">{{Utility::priceFormat($settings,$debitNoteAmount)}}</td></tr>
                    </table>
                    <table class="total-final">
                        <tr><td>{{__('Final Total')}}:</td><td style="text-align:right;">{{Utility::priceFormat($settings,$grandTotal)}}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="bank-area">
            <tr>
                <td class="bank-accounts-wrap">
                    <table class="bank-accounts-grid">
                        <tr>
                            @php
                                $firstBank = $companyBankAccounts->get(0);
                                $secondBank = $companyBankAccounts->get(1);
                            @endphp
                            <td class="bank-block">
                                @if(!empty($firstBank))
                                    <h4>{{ $firstBank->holder_name ?? __('Bank Account 1') }}</h4>
                                    <p>
                                        @if(!empty($firstBank->bank_name)){{__('Bank Name')}} : {{ $firstBank->bank_name }}<br>@endif
                                        @if(!empty($firstBank->account_number)){{__('Account Number')}} : {{ $firstBank->account_number }}<br>@endif
                                        @if(!empty($firstBank->bank_address)){{__('Branch Code')}} : {{ $firstBank->bank_address }}<br>@endif
                                        @if(!empty($firstBank->contact_number)){{__('SWIFT Code')}} : {{ $firstBank->contact_number }}@endif
                                    </p>
                                @endif
                            </td>
                            <td class="bank-block">
                                @if(!empty($secondBank))
                                    <h4>{{ $secondBank->holder_name ?? __('Bank Account 2') }}</h4>
                                    <p>
                                        @if(!empty($secondBank->bank_name)){{__('Bank Name')}} : {{ $secondBank->bank_name }}<br>@endif
                                        @if(!empty($secondBank->account_number)){{__('Account Number')}} : {{ $secondBank->account_number }}<br>@endif
                                        @if(!empty($secondBank->bank_address)){{__('Branch Code')}} : {{ $secondBank->bank_address }}<br>@endif
                                        @if(!empty($secondBank->contact_number)){{__('SWIFT Code')}} : {{ $secondBank->contact_number }}@endif
                                    </p>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="sign-wrap">
                    <span class="sign-line"></span>
                    <div class="sign-label">{{__('Authorised Sign')}}</div>
                    @if($settings['bill_qr_display'] == 'on')
                        <div class="qr-box">
                            {!! DNS2D::getBarcodeHTML(route('bill.link.copy',\Crypt::encrypt($bill->bill_id)), "QRCODE",1,1) !!}
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="bottom-bar">
        <div class="bottom-left">
            {{__('Licensed for')}}: {{!empty($settings['company_name']) ? $settings['company_name'] : '-'}}<br>
            {{__('Certificate NR')}}:
        </div>
        <div class="bottom-right">
            {!! $settings['footer_notes'] ?? $settings['company_name'] . ' ' . __('Bill') !!}
            {{ \Carbon\Carbon::now()->format('H:i') }}
        </div>
        <div class="clearfix"></div>
    </div>
</div>
@if(!isset($preview))
    @include('bill.script');
@endif
</body>
</html>
