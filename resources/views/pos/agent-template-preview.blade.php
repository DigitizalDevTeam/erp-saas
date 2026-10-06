<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pos.agent_invoice_template') }}</title>
    <style>
        body { margin: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #111; }
        .wrap { padding: 16px; display: flex; justify-content: center; }
        .slip { background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.12); padding: 12px 14px 16px; box-sizing: border-box; }
        .paper-slip { width: 80mm; }
        .paper-a5 { width: 148mm; }
        .paper-a4 { width: 190mm; }
        .center { text-align: center; }
        .logo { max-width: 96px; max-height: 48px; display: block; margin: 0 auto 6px; object-fit: contain; }
        .company { font-weight: 700; font-size: 13px; }
        .muted { color: #444; font-size: 11px; }
        .rule { border-top: 1px dashed #111; margin: 8px 0; }
        .solid { border-top: 2px solid #111; margin: 8px 0; }
        .title { font-weight: 800; font-size: 15px; letter-spacing: .3px; text-transform: uppercase; }
        .kv { display: flex; justify-content: space-between; gap: 8px; font-size: 11px; margin: 2px 0; }
        .kv b { font-weight: 700; }
        .item-name { font-weight: 700; font-size: 12px; }
        .item-tax { font-size: 10px; color: #444; }
        .amounts { text-align: right; font-size: 11px; }
        .row4, .row4c { display: flex; gap: 6px; font-size: 11px; align-items: baseline; }
        .row4 span:first-child, .row4c span:first-child { flex: 1; }
        .row4 span, .row4c span { white-space: nowrap; }
        .head { font-weight: 700; font-size: 10px; letter-spacing: .2px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { padding: 2px 0; vertical-align: top; }
        th { font-size: 10px; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        .num { text-align: right; }
        .qr { text-align: center; margin-top: 8px; }
        .qr svg, .qr img { width: 92px; height: 92px; }
        .footer { text-align: center; font-size: 10px; margin-top: 8px; }
        .sig { border: 1px solid #111; height: 36px; margin-top: 4px; }
        .mono { font-family: Consolas, "Courier New", monospace; }
    </style>
</head>
<body>
@php
    $company = $settings['company_name'] ?? 'HOYA HOYA';
    $nuit = $settings['vat_number'] ?? $settings['registration_number'] ?? '402078588';
    $phone = $settings['company_telephone'] ?? '';
    $email = $settings['company_email'] ?? '';
    $currency = $settings['site_currency_symbol'] ?? $settings['site_currency'] ?? 'MT';
@endphp
<div class="wrap">
    <div class="slip paper-{{ $paper }} {{ $template === 'elegant' ? 'mono' : '' }}">
        @if(!empty($logoUrl))
            <img class="logo" src="{{ $logoUrl }}" alt="Logo" onerror="this.style.display='none'">
        @endif
        <div class="center company">{{ $company }}</div>
        <div class="center muted">{{ __('NUIT') }}: {{ $nuit }}</div>
        @if($phone !== '' || $email !== '')
            <div class="center muted">{{ $phone }}@if($phone !== '' && $email !== '') · @endif{{ $email }}</div>
        @endif

        @if($template === 'compact')
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Date') }}</span><span>2026-10-01</span></div>
            <div class="kv"><span>{{ __('Invoice No') }}</span><span>#PDV00020</span></div>
            <div class="kv"><span>{{ __('Operator') }}</span><span>{{ $company }}</span></div>
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Customer') }}</span><b>{{ __('Walk-in Customer') }}</b></div>
            <div class="kv"><span>{{ __('NUIT') }}</span><span>—</span></div>
            <div class="kv"><span>{{ __('Currency') }}</span><span>{{ $currency }}</span></div>
            <div class="rule"></div>
            <table>
                <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th>{{ __('Unit (Incl. VAT)') }}</th>
                    <th>{{ __('Qty') }}</th>
                    <th>{{ __('Total') }}</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td><div class="item-name">Testing product</div><div class="item-tax">{{ __('VAT') }} 16%</div></td>
                    <td class="num">199,99</td>
                    <td class="num">2</td>
                    <td class="num">399,99</td>
                </tr>
                </tbody>
            </table>
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Sub Total') }}</span><span>399,99 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('Discount') }}</span><span>0,00 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('VAT') }}</span><span>55,17 {{ $currency }}</span></div>
            <div class="solid"></div>
            <div class="kv"><b>{{ __('Grand Total') }}</b><b>399,99 {{ $currency }}</b></div>
            <div class="kv"><span>{{ __('Paid') }}</span><span>399,99 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('Troco') }}</span><span>0,00 {{ $currency }}</span></div>
        @elseif($template === 'classic')
            <div class="rule"></div>
            <div class="center title">{{ __('Cash Sale') }}</div>
            <div class="center"><b>{{ __('No.') }} #PDV00020</b></div>
            <div class="center muted">{{ __('Original') }} · {{ __('Issued at') }} 2026-10-01 10:12:59</div>
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Customer') }}:</span><b>{{ __('Walk-in Customer') }}</b></div>
            <div class="kv"><span>{{ __('NUIT') }}:</span><span>—</span></div>
            <div class="kv"><span>{{ __('Address') }}:</span><span>—</span></div>
            <div class="kv"><span>{{ __('Currency') }}:</span><span>{{ $currency }}</span></div>
            <div class="kv"><span>{{ __('pos.table') }}:</span><span>01</span></div>
            <div class="rule"></div>
            <div class="row4 head"><span>{{ __('Description') }}</span><span>{{ __('Qty') }}</span><span>{{ __('Unit (Incl. VAT)') }}</span><span>{{ __('Total') }}</span></div>
            <div class="row4"><span class="item-name">Testing product</span><span>2</span><span>199,99</span><span>399,99</span></div>
            <div class="item-tax">{{ __('VAT') }} 16%</div>
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Sub Total') }}</span><span>399,99 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('Discount') }}</span><span>0,00 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('VAT') }}</span><span>55,17 {{ $currency }}</span></div>
            <div class="rule"></div>
            <div class="kv"><b>{{ __('TOTAL') }}</b><b>399,99 {{ $currency }}</b></div>
            <div class="kv"><span>{{ __('Paid') }}</span><span>399,99 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('Troco') }}</span><span>0,00 {{ $currency }}</span></div>
        @else
            <div class="solid"></div>
            <div class="center title">{{ __('Cash Sale') }}</div>
            <div class="center"><b>{{ __('No.') }} #PDV00020</b></div>
            <div class="center muted">{{ __('Original') }} · {{ __('Issued at') }} 2026-10-01 10:12:59</div>
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Customer') }}:</span><b>{{ __('Walk-in Customer') }}</b></div>
            <div class="kv"><span>{{ __('Currency') }}:</span><span>{{ $currency }}</span></div>
            <div class="rule"></div>
            <div class="item-name">Testing product</div>
            <div class="item-tax">{{ __('VAT') }} 16%</div>
            <div class="amounts">2 &nbsp; 199,99 &nbsp; 399,99</div>
            <div class="rule"></div>
            <div class="kv"><span>{{ __('Sub Total') }}</span><span>399,99 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('Discount') }}</span><span>0,00 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('VAT') }}</span><span>55,17 {{ $currency }}</span></div>
            <div class="solid"></div>
            <div class="kv"><b>{{ __('TOTAL') }}</b><b>399,99 {{ $currency }}</b></div>
            <div class="kv"><span>{{ __('Paid') }}</span><span>399,99 {{ $currency }}</span></div>
            <div class="kv"><span>{{ __('Troco') }}</span><span>0,00 {{ $currency }}</span></div>
        @endif

        <div class="rule"></div>
        <div class="kv"><span>{{ __('Payment Method') }}</span><span>{{ __('Cash') }}</span></div>
        @if($showQr && $qrHtml !== '')
            <div class="qr">{!! $qrHtml !!}</div>
        @endif
        <div class="footer">
            {{ __('pos.thank_you') }}<br>
            {{ __('pos.document_processed_by_computer') }}
        </div>
    </div>
</div>
</body>
</html>
