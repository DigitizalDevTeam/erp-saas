{{-- UTHARA 80mm thermal talão — from client sample. Uses POS _data.php variables when included from pos.standard.talao_80mm --}}
<style>
.mz-talao-uthara{
    --paper:80mm;--ink:#111;--muted:#555;--line:#222;
    color:var(--ink);font-family:Arial,Helvetica,sans-serif;
    font-size:11px;line-height:1.35;width:100%;max-width:72mm;margin:0 auto;padding:2mm 1.5mm;box-sizing:border-box;
}
.mz-talao-uthara *{box-sizing:border-box}
.mz-talao-uthara .center{text-align:center!important}
.mz-talao-uthara .right{text-align:right}.mz-talao-uthara .bold{font-weight:700}
.mz-talao-uthara .small{font-size:9px}.mz-talao-uthara .muted{color:var(--muted)}
.mz-talao-uthara img.logo,.mz-talao-uthara img.mz-receipt-logo{
    max-width:36mm!important;max-height:14mm!important;width:auto!important;height:auto!important;
    margin:0 auto 2mm!important;display:block!important;object-fit:contain!important;
}
.mz-talao-uthara .logo-fallback{font-size:16px;font-weight:800;letter-spacing:1px;margin-bottom:1.5mm}
.mz-talao-uthara .company-name{font-size:12px;font-weight:700;margin-bottom:.5mm}
.mz-talao-uthara .company-meta{font-size:9px;line-height:1.4;color:#222}
.mz-talao-uthara h1{font-size:14px;margin:2.5mm 0 1mm;text-transform:uppercase;letter-spacing:.4px}
.mz-talao-uthara .document-no{font-weight:700;font-size:11px;margin-bottom:.4mm}
.mz-talao-uthara .rule{border-top:1px dashed var(--line);margin:2.5mm 0}
.mz-talao-uthara .solid{border-top:1.5px solid var(--line);margin:2.5mm 0}
.mz-talao-uthara .meta{width:100%}
.mz-talao-uthara .meta-row{
    display:flex;align-items:flex-start;justify-content:space-between;gap:2mm;
    margin:1mm 0;width:100%;
}
.mz-talao-uthara .meta-row .lbl{
    flex:0 0 auto;max-width:38%;font-size:10px;color:#333;white-space:nowrap;
}
.mz-talao-uthara .meta-row .val{
    flex:1 1 auto;text-align:right;font-size:10px;font-weight:600;
    overflow-wrap:anywhere;word-break:break-word;min-width:0;
}
.mz-talao-uthara .row{
    display:flex;align-items:baseline;justify-content:space-between;gap:2mm;width:100%;margin:.6mm 0;
}
.mz-talao-uthara .row > span:first-child{flex:0 1 auto;min-width:0}
.mz-talao-uthara .row > span:last-child{flex:0 0 auto;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.mz-talao-uthara table{width:100%;border-collapse:collapse;table-layout:fixed;margin:1mm 0}
.mz-talao-uthara th{
    border-bottom:1px solid #000;padding:1.2mm .3mm;text-align:left;font-size:8px;
    text-transform:uppercase;letter-spacing:.2px;
}
.mz-talao-uthara td{padding:1.4mm .3mm;vertical-align:top;font-size:10px;overflow-wrap:anywhere}
.mz-talao-uthara .col-desc{width:auto}
.mz-talao-uthara .qty{width:9mm;text-align:center}
.mz-talao-uthara .price{width:17mm;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.mz-talao-uthara .total{width:18mm;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.mz-talao-uthara .item-detail{display:block;font-size:8px;color:#444;margin-top:.5mm;font-weight:400}
.mz-talao-uthara .totals{width:100%;margin-top:1mm}
.mz-talao-uthara .totals .grand{
    font-size:13px;border-top:1.5px solid #000;border-bottom:1.5px solid #000;
    padding:2mm 0;margin:1.5mm 0;
}
.mz-talao-uthara .tax-table{margin-top:1mm}
.mz-talao-uthara .tax-table th,.mz-talao-uthara .tax-table td{text-align:right;font-size:8px;padding:.8mm .3mm}
.mz-talao-uthara .tax-table th:first-child,.mz-talao-uthara .tax-table td:first-child{text-align:left}
.mz-talao-uthara .qr-wrap{display:flex;align-items:flex-start;justify-content:center;gap:2.5mm;margin:3mm 0 2mm}
.mz-talao-uthara .qr-box{width:22mm;height:22mm;flex:0 0 22mm}
.mz-talao-uthara .qr-box svg{width:100%!important;height:100%!important;display:block}
.mz-talao-uthara .validation{flex:1 1 auto;min-width:0;font-size:8px;line-height:1.35}
.mz-talao-uthara .footer{font-size:8px;text-align:center;line-height:1.4}
.mz-talao-uthara .processed{margin-top:2mm;font-size:8px;text-align:center;color:#333}
.mz-talao-uthara .sig-box{border:1px solid #333;min-height:22px;margin-top:2mm}
.text-capitalize{text-transform:capitalize}

/* Modal / preview: look like thermal paper strip */
#commonModal #printarea.pos-print-layout-slip,
#printarea.pos-print-layout-slip{
    max-width:80mm!important;margin-left:auto!important;margin-right:auto!important;
    background:#fff;padding:0!important;
}
#commonModal .mz-talao-uthara,
#printarea .mz-talao-uthara{
    border:1px dashed #ccc;border-radius:2px;background:#fff;
}
@media print{
    #commonModal .mz-talao-uthara,
    #printarea .mz-talao-uthara{border:none!important}
}
</style>

@php
    $brand = strtoupper(substr((string)($companyName ?? 'UTHARA'), 0, 12));
@endphp
<div class="mz-talao-uthara receipt-body">
    <div class="center">
        @if(!empty($headerLogo))
            <img class="logo mz-receipt-logo" src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'">
        @else
            <div class="logo-fallback">{{ $brand }}</div>
        @endif
        <div class="company-name">{{ $companyName }}</div>
        <div class="company-meta">
            <div>{{ __('NUIT') }}: {{ $companyVat !== '' ? $companyVat : '—' }}</div>
            <div>{{ $companyAddressLine }}</div>
            <div>{{ __('Tel.') }}: {{ $companyPhone !== '' ? $companyPhone : '—' }}@if($companyEmail !== '') · {{ $companyEmail }}@endif</div>
        </div>
    </div>

    <div class="solid"></div>
    <h1 class="center">{{ $paymentDocumentTitle }}</h1>
    <div class="center document-no">{{ __('No.') }} {{ $docNumber }} · {{ $branchName }}</div>
    <div class="center small muted">{{ $copyLabel }} · {{ $docDateTime }}</div>
    <div class="rule"></div>

    <div class="meta">
        <div class="meta-row">
            <span class="lbl">{{ __('Customer') }}:</span>
            <span class="val">{{ $clientName }}</span>
        </div>
        <div class="meta-row">
            <span class="lbl">{{ __('NUIT') }}:</span>
            <span class="val">{{ $custVat !== '' ? $custVat : '—' }}</span>
        </div>
        @if($custAddress !== '')
            <div class="meta-row">
                <span class="lbl">{{ __('Address') }}:</span>
                <span class="val">{{ $custAddress }}</span>
            </div>
        @endif
        <div class="meta-row">
            <span class="lbl">{{ __('Currency') }}:</span>
            <span class="val">{{ $currency }}</span>
        </div>
        @if(!empty($details['table_name']))
            <div class="meta-row">
                <span class="lbl">{{ __('pos.table') }}:</span>
                <span class="val">{{ $details['table_name'] }}</span>
            </div>
        @endif
    </div>
    <div class="rule"></div>

    @if(!$isBalancePayment)
    <table aria-label="{{ __('Items') }}">
        <thead>
            <tr>
                <th class="col-desc">{{ __('Description') }}</th>
                <th class="qty">{{ __('Qty') }}</th>
                <th class="price">{{ __('Price') }}</th>
                <th class="total">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($sales['data'] ?? [] as $value)
            <tr>
                <td class="col-desc">
                    <span class="bold">{{ $value['name'] ?? '' }}</span>
                    @if(!empty($value['tax']))
                    <span class="item-detail">{{ __('VAT') }} {{ $value['tax'] }}</span>
                    @endif
                    @if(!empty($value['description']))
                    <span class="item-detail item-note"><strong>{{ __('Note') }}:</strong> {{ $value['description'] }}</span>
                    @endif
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
        <div class="row"><span>{{ __('Sub Total') }}</span><span>{{ $salesSubTotal }}</span></div>
        <div class="row"><span>{{ __('Discount') }}</span><span>{{ $salesDiscount }}</span></div>
        <div class="row"><span>{{ __('VAT') }} / IVA</span><span>{{ $ivaTotalFmt }}</span></div>
        {{-- $salesTotal is already priceFormat()'d (includes currency symbol) — do not append $currency again --}}
        <div class="row grand bold"><span>{{ __('TOTAL') }}</span><span>{{ $salesTotal }}</span></div>
        @if(!empty($showPaidRow))
        <div class="row"><span>{{ __('Paid') }}</span><span>{{ $paidFmt }}</span></div>
        @endif
        <div class="row"><span>{{ $trocoPendenteLabel }}</span><span>{{ $trocoPendenteValue }}</span></div>
    </section>

    <div class="rule"></div>
    @if(!empty($vatBreakdown) && count($vatBreakdown))
    <table class="tax-table" aria-label="{{ __('Tax summary') }}">
        <thead><tr><th>{{ __('VAT') }}</th><th>{{ __('Net') }}</th><th>{{ __('Tax') }}</th></tr></thead>
        <tbody>
        @foreach($vatBreakdown as $row)
            <tr>
                <td>{{ number_format((float)($row['rate'] ?? 0), 0) }}%</td>
                <td>{{ $fmt($row['net'] ?? 0) }}</td>
                <td>{{ $fmt($row['vat'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="rule"></div>
    @endif

    <div class="meta">
        <div class="meta-row"><span class="lbl">{{ __('Payment') }}:</span><span class="val text-capitalize">{{ $paymentMethodLabel }}</span></div>
        @if(!empty($paymentNotes))
        <div class="meta-row"><span class="lbl">{{ __('Notes') }}:</span><span class="val">{{ $paymentNotes }}</span></div>
        @endif
        <div class="meta-row"><span class="lbl">{{ __('Reference') }}:</span><span class="val">{{ $paymentRefLabel }}</span></div>
        <div class="meta-row"><span class="lbl">{{ __('Operator') }}:</span><span class="val">{{ $operatorName }}</span></div>
        <div class="meta-row"><span class="lbl">{{ __('Branch') }}:</span><span class="val">{{ $branchName }}</span></div>
    </div>

    @if($hasCreditPayment)
        <div class="rule"></div>
        <div class="small bold">{{ __('pos.client_signature_for_credit') }}</div>
        <div class="sig-box"></div>
    @endif

    <div class="qr-wrap">
        <div class="qr-box">
            @if(!empty($qrSvg))
                {!! $qrSvg !!}
            @elseif(!empty($qrHtml))
                {!! $qrHtml !!}
            @endif
        </div>
    </div>

    <div class="rule"></div>
    <div class="footer">
        <div class="bold">{{ __('Thank You For Shopping With Us. Please Come Again') }}</div>
        <div>{{ __('Documento processado pelo ERP SaaS UTHARA') }}</div>
        <div>{{ $companyWebsite ?? 'www.uthara.co.mz' }}</div>
    </div>
    <div class="processed">{{ __('pos.document_processed_by_computer') }}</div>
</div>
