{{-- POS Layout 3: UTHARA 80mm thermal talão --}}
@php include resource_path('views/pos/receipts/_prepare.php'); @endphp
@include('pos.receipts._bootstrap')

<div class="pt-0 pb-3 {{ $standalone ? 'bg-white rounded p-3' : '' }} pos-module position-relative pos-print-layout-{{ $printLayout }}" id="printarea" {{ $standalone ? 'style="box-shadow:0 1px 3px rgba(0,0,0,.1);"' : '' }}>
<style id="pos-receipt-layout3-styles">
/* Do not use Bootstrap class names (.row) — they break label/value alignment in the modal. */
.mz-l3 {
    --mz-ink: {{ $posAccentColor === '#ffffff' || $posAccentColor === '#fff' ? '#000000' : $posAccentColor }};
    --mz-line: {{ $posAccentColor === '#ffffff' || $posAccentColor === '#fff' ? '#000000' : $posAccentColor }};
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10px;
    line-height: 1.3;
    width: 100%;
    max-width: 72mm;
    margin: 0 auto;
    padding: 2mm 0;
    box-sizing: border-box;
}
.mz-l3 *, .mz-l3 *::before, .mz-l3 *::after { box-sizing: border-box; }
.mz-l3 .center,
.mz-l3 .mz-center,
.mz-l3 .mz-l3-header,
.mz-l3 .mz-l3-header *,
.mz-l3 .mz-l3-doc,
.mz-l3 .mz-l3-doc *,
#commonModal .body .mz-l3 .mz-center,
#commonModal .body .mz-l3 .mz-l3-header,
#commonModal .body .mz-l3 .mz-l3-header div,
#commonModal .body .mz-l3 .mz-l3-header h1,
#commonModal .body .mz-l3 .mz-l3-doc,
#commonModal .body .mz-l3 .mz-l3-doc div,
#commonModal .body .mz-l3 .mz-l3-doc h1,
#printarea .mz-l3 .mz-center,
#printarea .mz-l3 .mz-l3-header,
#printarea .mz-l3 .mz-l3-header div,
#printarea .mz-l3 .mz-l3-header h1,
#printarea .mz-l3 .mz-l3-doc,
#printarea .mz-l3 .mz-l3-doc div,
#printarea .mz-l3 .mz-l3-doc h1 {
    text-align: center !important;
}
.mz-l3 .right { text-align: right; }
.mz-l3 .bold { font-weight: 700; }
.mz-l3 .small { font-size: 8px; }
.mz-l3 .muted { color: #444; }
.mz-l3 img.mz-receipt-logo,
.mz-l3 img.logo,
#commonModal .body .mz-l3 img.mz-receipt-logo,
#commonModal .body .mz-l3 img.logo,
#printarea .mz-l3 img.mz-receipt-logo,
#printarea.modal-body .mz-l3 img.mz-receipt-logo,
#printarea.modal-body .mz-l3 img.logo {
    max-width: 96px !important;
    max-height: 48px !important;
    width: auto !important;
    height: auto !important;
    margin: 0 auto 6px !important;
    display: block !important;
    object-fit: contain !important;
}
.mz-l3 .logo-fallback { font-size: 16px; font-weight: 800; letter-spacing: 1px; margin-bottom: 4px; text-align: center !important; }
.mz-l3 h1.doc-type,
.mz-l3 .mz-l3-doc h1 {
    font-size: 14px;
    margin: 6px 0 2px;
    text-transform: uppercase;
    letter-spacing: .3px;
    color: #111;
    font-weight: 700;
    line-height: 1.2;
    text-align: center !important;
    width: 100%;
}
.mz-l3 .document-no { font-weight: 700; font-size: 11px; text-align: center !important; width: 100%; }
.mz-l3 .rule { border-top: 1px dashed var(--mz-line); margin: 6px 0; }
.mz-l3 .solid { border-top: 1.5px solid var(--mz-line); margin: 6px 0; }
.mz-l3 .mz-kv {
    display: flex !important;
    flex-wrap: nowrap !important;
    justify-content: space-between !important;
    align-items: flex-start;
    gap: 8px;
    margin: 2px 0;
    color: #111;
    width: 100%;
}
.mz-l3 .mz-kv > * {
    width: auto !important;
    max-width: none !important;
    flex: 0 1 auto !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-top: 0 !important;
}
.mz-l3 .mz-kv > span:first-child { flex: 0 1 auto; text-align: left; }
.mz-l3 .mz-kv > span:last-child {
    flex: 1 1 auto;
    text-align: right !important;
    word-break: break-word;
}
.mz-l3 table.items {
    width: 100% !important;
    border-collapse: collapse;
    table-layout: fixed;
    color: #111;
    margin: 0;
}
.mz-l3 table.items th {
    border-bottom: 1px solid var(--mz-line);
    padding: 3px 2px;
    text-align: left;
    font-size: 8px;
    text-transform: uppercase;
    font-weight: 700;
    background: transparent !important;
    color: #111 !important;
}
.mz-l3 table.items td {
    padding: 3px 2px;
    vertical-align: top;
    font-size: 9px;
    overflow-wrap: anywhere;
    border: 0 !important;
    color: #111;
}
.mz-l3 table.items th.qty,
.mz-l3 table.items td.qty { width: 10mm; text-align: center !important; }
.mz-l3 table.items th.price,
.mz-l3 table.items td.price { width: 18mm; text-align: right !important; white-space: nowrap; }
.mz-l3 table.items th.total,
.mz-l3 table.items td.total { width: 19mm; text-align: right !important; white-space: nowrap; }
.mz-l3 .item-detail { display: block; font-size: 7.5px; color: #333; margin-top: 1px; }
.mz-l3 .totals {
    margin-left: auto;
    width: 50mm;
    max-width: 100%;
    color: #111;
}
.mz-l3 .totals .mz-kv.grand {
    font-size: 14px;
    font-weight: 700;
    border-top: 1.5px solid var(--mz-line);
    border-bottom: 1.5px solid var(--mz-line);
    padding: 4px 0;
    margin-top: 4px;
}
.mz-l3 .tax-table { width: 100%; border-collapse: collapse; color: #111; }
.mz-l3 .tax-table th,
.mz-l3 .tax-table td { text-align: right; font-size: 8px; padding: 2px 1px; background: transparent !important; color: #111 !important; border: 0 !important; }
.mz-l3 .tax-table th:first-child,
.mz-l3 .tax-table td:first-child { text-align: left; }
.mz-l3 .qr-wrap { display: flex; align-items: center; justify-content: flex-start; gap: 8px; margin: 6px 0; }
.mz-l3 .view-qrcode-receipt-svg,
.mz-l3 .view-qrcode-receipt { width: 24mm; height: 24mm; flex-shrink: 0; }
.mz-l3 .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; }
.mz-l3 .validation { max-width: 38mm; font-size: 8px; color: #111; }
.mz-l3 .footer { font-size: 8px; text-align: center !important; color: #111; }
.mz-l3 .sig-box { border: 1px solid #333; min-height: 28px; margin-top: 6px; }
#commonModal .mz-l3 .footer,
#printarea .mz-l3 .footer,
.mz-l3 .footer,
.mz-l3 .footer * { text-align: center !important; }
#printarea.pos-print-layout-slip,
#printarea.pos-print-layout-slip .mz-l3 {
    max-width: 80mm;
    margin-left: auto;
    margin-right: auto;
}
</style>
    <div class="receipt-copy-wrapper receipt-copy">
        @if($segundaVia)
            <div class="segunda-via-watermark" aria-hidden="true">SEGUNDA VIA</div>
        @endif

        <div class="mz-l3">
            {{-- Header: match UTHARA HTML — each line centered --}}
            <div class="mz-l3-header mz-center" style="text-align:center !important;width:100%;">
                <img class="mz-receipt-logo logo" src="{{ $headerLogo }}" alt="Logo"
                     style="max-width:96px;max-height:48px;width:auto;height:auto;object-fit:contain;display:block;margin:0 auto 6px;"
                     onerror="this.style.display='none'; var fb=this.nextElementSibling; if(fb) fb.style.display='block';">
                <div class="logo-fallback mz-center" style="display:none;text-align:center !important;">{{ $companyName }}</div>
                <div class="bold mz-center" style="text-align:center !important;">{{ $companyName }}</div>
                <div class="mz-center" style="text-align:center !important;">{{ __('NUIT') }}: {{ $companyVat !== '' ? $companyVat : '—' }}</div>
                <div class="mz-center" style="text-align:center !important;">{{ $companyAddressLine !== '' ? $companyAddressLine : '—' }}</div>
                <div class="mz-center" style="text-align:center !important;">
                    @if($companyPhone !== ''){{ __('Phone') }}: {{ $companyPhone }}@endif
                    @if($companyPhone !== '' && $companyEmail !== '') · @endif
                    @if($companyEmail !== ''){{ $companyEmail }}@endif
                </div>
            </div>

            <div class="solid"></div>
            <div class="mz-l3-doc mz-center" style="text-align:center !important;width:100%;">
                <h1 class="doc-type mz-center" style="text-align:center !important;margin:6px 0 2px;">{{ $paymentDocumentTitle }}</h1>
                <div class="document-no mz-center" style="text-align:center !important;">{{ __('No.') }} {{ $docNumber }}</div>
                <div class="small mz-center" style="text-align:center !important;">{{ $copyLabel }} · {{ __('Issued at') }} {{ $docDateTime }}</div>
            </div>
            <div class="rule"></div>

            <div class="mz-kv"><span>{{ __('Customer') }}:</span><span class="bold">{{ $clientName }}</span></div>
            <div class="mz-kv"><span>{{ __('NUIT') }}:</span><span>{{ $custVat !== '' ? $custVat : '—' }}</span></div>
            <div class="mz-kv"><span>{{ __('Address') }}:</span><span>{{ $custAddress !== '' ? $custAddress : '—' }}</span></div>
            <div class="mz-kv"><span>{{ __('Currency') }}:</span><span>{{ $currency }}</span></div>
            @if(!empty($details['table_name']))
                <div class="mz-kv"><span>{{ __('pos.table') }}:</span><span>{{ $details['table_name'] }}</span></div>
            @endif
            <div class="rule"></div>

            @if(!$isBalancePayment)
            <table class="items" aria-label="{{ __('Items') }}">
                <thead>
                <tr>
                    <th>{{ __('Description') }}</th>
                    <th class="qty">{{ __('Qty') }}</th>
                    <th class="price">{{ __('Price') }}</th>
                    <th class="total">{{ __('Total') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($sales['data'] ?? [] as $value)
                    <tr>
                        <td>
                            {{ $value['name'] ?? '' }}
                            <span class="item-detail">
                                @if(!empty($value['tax'])){{ __('VAT') }} {{ $value['tax'] }}@endif
                                @if(!empty($value['description'])) · {{ $value['description'] }}@endif
                            </span>
                        </td>
                        <td class="qty">{{ $value['quantity'] ?? '' }}</td>
                        <td class="price">{{ $value['price'] ?? '' }}</td>
                        <td class="total">{{ $value['subtotal'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="rule"></div>
            @endif

            <section class="totals">
                <div class="mz-kv"><span>{{ __('Sub Total') }}</span><span>{{ $salesSubTotal }}</span></div>
                <div class="mz-kv"><span>{{ __('Discount') }}</span><span>{{ $salesDiscount }}</span></div>
                <div class="mz-kv"><span>{{ __('VAT') }} / IVA</span><span>{{ $ivaTotalFmt }}</span></div>
                <div class="mz-kv grand bold"><span>{{ __('TOTAL') }}</span><span>{{ $salesTotal }} {{ $currency }}</span></div>
                <div class="mz-kv"><span>{{ __('Paid') }}</span><span>{{ $paidFmt }}</span></div>
                <div class="mz-kv"><span>{{ $trocoPendenteLabel }}</span><span>{{ $trocoPendenteValue }}</span></div>
            </section>

            @if(count($vatBreakdown) > 0)
            <div class="rule"></div>
            <table class="tax-table" aria-label="{{ __('Tax summary') }}">
                <thead>
                <tr>
                    <th>{{ __('VAT') }}</th>
                    <th>{{ __('Net Amount') }}</th>
                    <th>{{ __('Tax') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($vatBreakdown as $row)
                    <tr>
                        <td>{{ number_format((float) ($row['rate'] ?? 0), 0) }}%</td>
                        <td>{{ $fmtMoney($row['net'] ?? 0) }}</td>
                        <td>{{ $fmtMoney($row['vat'] ?? 0) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif

            <div class="rule"></div>
            <div class="mz-kv"><span>{{ __('Payment Method') }}:</span><span class="bold text-capitalize">{{ $paymentMethodLabel }}</span></div>
            <div class="mz-kv"><span>{{ __('Reference') }}:</span><span>{{ $paymentRefLabel }}</span></div>
            <div class="mz-kv"><span>{{ __('Operator') }}:</span><span>{{ $operatorName }}</span></div>
            <div class="mz-kv"><span>{{ __('Branch') }}:</span><span>{{ $branchName }}</span></div>

            @if($hasCreditPayment)
                <div class="rule"></div>
                <div class="bold small">{{ __('pos.client_signature_for_credit') }}</div>
                <div class="sig-box"></div>
                <div class="mz-kv"><span>{{ __('Pendente') }}:</span><span class="bold">{{ $trocoPendenteValue }}</span></div>
            @endif

            @if($posQrDisplay && (!empty($qrSvg) || !empty($qrHtml)))
            <div class="qr-wrap">
                @if(!empty($qrSvg))
                    <div class="view-qrcode-receipt view-qrcode-receipt-svg">{!! $qrSvg !!}</div>
                @else
                    <div class="view-qrcode-receipt">{!! $qrHtml !!}</div>
                @endif
            </div>
            @endif

            <div class="rule"></div>
            <div class="footer">
                <div class="bold">{{ __('pos.thank_you') }}</div>
                <div>{{ __('Invoice generated by ERPSaaS') }}</div>
                <div>
                    {{ $companyWebsite }}
                    @if($companyEmail !== '') · {{ $companyEmail }}@endif
                </div>
                <div class="small">{{ __('pos.document_processed_by_computer') }}</div>
            </div>
        </div>
    </div>
</div>

@include('pos.receipts._print_footer')
