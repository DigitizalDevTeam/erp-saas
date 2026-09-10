{{-- 80mm thermal slip — layout matched to retail-style thermal receipt --}}
<style>
.mz-talao {
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.4;
    width: 100%;
    max-width: 72mm;
    margin: 0 auto;
    padding: 0;
    box-sizing: border-box;
    text-align: left;
}
.mz-talao *,
.mz-talao *::before,
.mz-talao *::after { box-sizing: border-box; }
.mz-talao .mz-talao-center,
.mz-talao .mz-talao-center .meta-line,
.mz-talao .mz-talao-center .small,
.mz-talao .mz-talao-center .company-name {
    text-align: center !important;
}
.mz-talao .bold { font-weight: 700; }
.mz-talao .small { font-size: 9px; }
.mz-talao img.logo,
.mz-talao img.mz-receipt-logo,
#commonModal .body .mz-talao img.logo,
#commonModal .body .mz-talao img.mz-receipt-logo,
#printarea .mz-talao img.logo,
#printarea .mz-talao img.mz-receipt-logo,
#printarea.modal-body .mz-talao img.logo,
#printarea.modal-body .mz-talao img.mz-receipt-logo {
    max-width: 96px !important;
    max-height: 44px !important;
    width: auto !important;
    height: auto !important;
    margin: 0 auto 6px !important;
    display: block !important;
    object-fit: contain !important;
}
.mz-talao .company-name {
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
    margin: 1mm 0;
    letter-spacing: .2px;
}
.mz-talao .dots,
.mz-talao .dots-heavy {
    display: block;
    width: 100%;
    border: 0;
    border-top: 1px dotted #000;
    margin: 2.5mm 0;
    padding: 0;
    height: 0;
}
.mz-talao .dots-heavy { border-top-width: 2px; }
.mz-talao .meta-line {
    margin: .4mm 0;
    color: #111;
    text-align: center !important;
    word-break: break-word;
}
.mz-talao .item-block {
    margin: 2mm 0;
    padding: 0;
    width: 100%;
    text-align: left;
}
.mz-talao .item-code,
.mz-talao .item-name {
    display: block;
    width: 100%;
    margin: 0;
    padding: 0;
    text-align: left;
}
.mz-talao .item-code { font-weight: 700; }
.mz-talao .item-name { margin: .3mm 0 .5mm; }
.mz-talao .line-table,
.mz-talao .tot-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}
.mz-talao .line-table td,
.mz-talao .tot-table td {
    padding: 0;
    vertical-align: top;
    color: #111;
    font-size: 11px;
}
.mz-talao .line-table td.qty,
.mz-talao .tot-table td.lbl {
    text-align: left;
    width: 58%;
}
.mz-talao .line-table td.amt,
.mz-talao .tot-table td.amt {
    text-align: right;
    width: 42%;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.mz-talao .tot-table { margin: .6mm 0; }
.mz-talao .tot-table.grand td {
    font-weight: 700;
    font-size: 13px;
}
.mz-talao .in-words {
    text-align: center !important;
    margin: 2mm 0;
    font-size: 10px;
    width: 100%;
}
.mz-talao .pay-block { text-align: center !important; margin: 2mm 0; width: 100%; }
.mz-talao .pay-block div { margin: .4mm 0; }
.mz-talao .footer {
    text-align: center !important;
    font-size: 10px;
    margin-top: 2mm;
    width: 100%;
}
.mz-talao .barcode-wrap {
    text-align: center !important;
    margin: 2.5mm auto 1.5mm;
    width: 100%;
}
.mz-talao .barcode-wrap svg {
    display: block !important;
    margin: 0 auto !important;
    max-width: 100%;
    height: 36px;
}
.mz-talao .barcode-caption {
    font-size: 9px;
    margin-top: 1mm;
    word-break: break-all;
    text-align: center !important;
}
.mz-talao .qr-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 2mm auto;
    width: 100%;
}
.mz-talao .view-qrcode-receipt-svg,
.mz-talao .view-qrcode-receipt {
    width: 28mm;
    height: 28mm;
    margin: 0 auto;
}
.mz-talao .view-qrcode-receipt-svg svg {
    display: block !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 auto;
}
.mz-talao .sig-box { border: 1px solid #333; min-height: 28px; margin-top: 2mm; padding: 1mm; width: 100%; }
.mz-talao .left { text-align: left !important; }
</style>

<div class="mz-talao receipt-body">
    {{-- Header --}}
    <div class="mz-talao-center" style="text-align:center !important;">
        <img class="mz-receipt-logo logo" src="{{ $headerLogo }}" alt="Logo"
             style="max-width:96px;max-height:44px;width:auto;height:auto;object-fit:contain;display:block;margin:0 auto 6px;"
             onerror="this.style.display='none'">
        <div class="company-name" style="text-align:center !important;">{{ $companyName }}</div>
        <div class="small" style="text-align:center !important;">{{ __('Address') }}: {{ $companyAddressLine }}</div>
        <div class="small" style="text-align:center !important;">{{ __('NUIT') }} : {{ $companyVat !== '' ? $companyVat : '—' }}</div>
        @if($companyPhone !== '')
            <div class="small" style="text-align:center !important;">{{ __('Phone Number') }}: {{ $companyPhone }}</div>
        @endif
        <div class="small" style="text-align:center !important;">{{ __('Branch') }} : {{ $branchName }}</div>
    </div>

    <hr class="dots">

    {{-- Meta --}}
    <div class="mz-talao-center" style="text-align:center !important;">
        <div class="meta-line" style="text-align:center !important;">{{ __('Created By') }}: {{ $operatorName }}</div>
        @if(!empty($operatorEmail))
            <div class="meta-line" style="text-align:center !important;">{{ __('Email') }}: {{ $operatorEmail }}</div>
        @endif
        <div class="meta-line" style="text-align:center !important;">{{ __('Date') }}: {{ $docDateTime }}</div>
        <div class="meta-line bold" style="text-align:center !important;">{{ $paymentDocumentTitle }} : {{ $docNumber }}</div>
        <div class="meta-line" style="text-align:center !important;">{{ __('Customer') }}: {{ $clientName }}</div>
        <div class="meta-line" style="text-align:center !important;">{{ __('Customer NUIT') }} : {{ $custVat !== '' ? $custVat : '' }}</div>
        @if(!empty($details['table_name']))
            <div class="meta-line" style="text-align:center !important;">{{ __('pos.table') }}: {{ $details['table_name'] }}</div>
        @endif
    </div>

    <hr class="dots">

    {{-- Items --}}
    @if(!$isBalancePayment)
        @foreach($sales['data'] ?? [] as $value)
            @php
                $lineName = $value['name'] ?? '';
                $lineQty = $value['quantity'] ?? '';
                $linePrice = $value['price'] ?? '';
                $lineTotal = $value['subtotal'] ?? '';
            @endphp
            <div class="item-block">
                <div class="item-code">{{ __('Codigo') }} : {{ $lineName }}</div>
                <div class="item-name">{{ $lineName }}</div>
                <table class="line-table" role="presentation">
                    <tr>
                        <td class="qty">{{ $lineQty }} X {{ $linePrice }}</td>
                        <td class="amt">{{ $lineTotal }}</td>
                    </tr>
                </table>
            </div>
        @endforeach
        <hr class="dots">
    @endif

    {{-- Totals --}}
    <table class="tot-table" role="presentation">
        <tr>
            <td class="lbl">{{ __('Total') }}</td>
            <td class="amt">{{ $salesSubTotal }}</td>
        </tr>
        <tr>
            <td class="lbl">{{ __('Tax') }}</td>
            <td class="amt">{{ $ivaTotalFmt }}</td>
        </tr>
    </table>
    <hr class="dots-heavy">
    <table class="tot-table grand" role="presentation">
        <tr>
            <td class="lbl">{{ __('Grand Total') }}</td>
            <td class="amt">{{ $salesTotal }}</td>
        </tr>
    </table>
    <hr class="dots-heavy">

    @if(!empty($amountInWords))
        <div class="in-words">{{ __('In Words') }}: {{ $amountInWords }}</div>
    @endif

    {{-- Payment (paid receipts only) --}}
    @if(!empty($hasPayments))
        <div class="pay-block">
            <div>{{ __('Paid By') }}: {{ $paymentMethodLabel }}</div>
            <div>{{ __('Amount') }}: {{ $paidFmt }}</div>
            @if($showTroco || $changeTotal > 0)
                <div>{{ __('Change') }}: {{ $fmt($changeTotal) }}</div>
            @elseif($showPendente)
                <div>{{ $trocoPendenteLabel }}: {{ $trocoPendenteValue }}</div>
            @endif
        </div>
    @endif

    @if($hasCreditPayment)
        <hr class="dots">
        <div class="left small bold">{{ __('pos.client_signature_for_credit') }}</div>
        <div class="sig-box"></div>
        <div class="small left">{{ __('Name') }}: _______________________</div>
        <table class="tot-table" role="presentation">
            <tr>
                <td class="lbl">{{ __('Pendente') }}:</td>
                <td class="amt bold">{{ $trocoPendenteValue }}</td>
            </tr>
        </table>
    @endif

    <div class="footer">
        <div class="bold">{{ __('Thank You For Shopping With Us. Please Come Again') }}</div>
    </div>

    @if(!empty($barcodeSvg))
        <div class="barcode-wrap">
            {!! $barcodeSvg !!}
            <div class="barcode-caption">{{ $docNumber }}</div>
        </div>
    @endif

    <div class="qr-wrap">
        @if(!empty($qrSvg))
            <div class="view-qrcode-receipt view-qrcode-receipt-svg">{!! $qrSvg !!}</div>
        @elseif(!empty($qrHtml))
            <div class="view-qrcode-receipt">{!! $qrHtml !!}</div>
        @endif
    </div>

    <div class="footer">
        <div>{{ __('Invoice Generated By') }} {{ config('app.name', 'ERP SaaS') }}</div>
        @if(!empty($companyWebsite))
            <div class="small">{{ $companyWebsite }}</div>
        @endif
    </div>
</div>
