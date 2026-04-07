@php
    $standalone = $standalone ?? false;
    $settings = $settings ?? Utility::settings();
    // All sales are "Cash Sale" (Venda a Dinheiro); payment method (Credit, Cash, Visa, etc.) is shown in the payment section.
    $hasCreditPayment = !empty($posPayments) && $posPayments->isNotEmpty() && $posPayments->contains(function ($p) { return in_array(strtolower($p->payment_method ?? ''), ['credit']); });
    $paymentDocumentTitle = __('Cash Sale');
    $creditPmt = $hasCreditPayment ? $posPayments->first(function ($p) { return strtolower($p->payment_method ?? '') === 'credit'; }) : null;
    $dueDate = $creditPmt && !empty($creditPmt->credit_terms) ? \Carbon\Carbon::now()->addDays((int)$creditPmt->credit_terms)->format(isset($settings['date_format']) ? $settings['date_format'] : 'Y-m-d') : null;
    $logoPath = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::getValByName('company_logo_dark');
    $pos_logo = \App\Models\Utility::getValByName('pos_logo');
    $headerLogo = (isset($pos_logo) && $pos_logo) ? \App\Models\Utility::get_file('pos_logo/') . $pos_logo : $logoPath . '/' . ($company_logo ?? 'logo-dark.png');
    $docTitle = $printSource === 'restaurant' ? __('Bill') : __('Cash Sale');
    $clientName = isset($details['client_name']) ? $details['client_name'] : (isset($details['customer']['name']) ? $details['customer']['name'] : __('pos.walkin_customer'));
    $cust = $details['customer'] ?? [];
    $vatBreakdown = $vatBreakdown ?? [];
    $bankAccounts = $bankAccounts ?? collect();
    $segundaVia = $segundaVia ?? false;
    $posIdRaw = $posIdRaw ?? null;
    $receiptLinkUrl = $receiptLinkUrl ?? null;
    $printSource = $printSource ?? 'retail';
    $isBalancePayment = $isBalancePayment ?? false;
    $qrCodeContent = $receiptLinkUrl ?: (isset($details['pos_id']) ? (($settings['company_name'] ?? config('app.name')) . ' - ' . $details['pos_id'] . ' - ' . ($details['date'] ?? date('Y-m-d'))) : url('/'));
    $receiptUser = $receiptUser ?? null;
    $priceUser = $receiptUser ?? Auth::user();
    $printLayout = \App\Models\Utility::normalizePosPrintLayout($settings['pos_print_layout'] ?? 'receipt');
    $directPrint = (isset($settings['pos_direct_print']) && $settings['pos_direct_print'] === 'on');
@endphp
<style id="pos-receipt-styles">
    .pos-print-header .header-sep { border-bottom: 1px dotted #333; margin: 10px 0; }
    .pos-print-header .logo-box { max-width: 120px; height: 70px; margin: 0 auto 8px; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff; }
    .pos-print-header .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .pos-print-header .doc-title { font-size: 1.25rem; font-weight: bold; text-align: center; margin: 8px 0; }
    .pos-print-header .doc-row { display: flex; justify-content: space-between; margin-bottom: 6px; }
    .pos-print-header .doc-row .left, .pos-print-header .doc-row .right { flex: 1; }
    .pos-print-header .doc-row .right { text-align: right; }
    .pos-print-header .customer-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
    .pos-print-header .customer-row .cust-type { font-weight: bold; }
    .pos-print-header .customer-row .cust-name { font-weight: bold; color: #333; }
    .pos-print-header .customer-details { font-size: 0.9rem; color: #555; margin-top: 4px; }
    .segunda-via-watermark { position: absolute; left: 0; top: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 10; opacity: 0.12; font-size: 4rem; font-weight: bold; color: #000; transform: rotate(-35deg); white-space: nowrap; }
    .view-qrcode-receipt img { max-width: 70px; height: auto; display: block; }
    .view-qrcode-receipt-svg { display: inline-block; }
    .view-qrcode-receipt-svg { line-height: 0; }
    .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; max-width: 100%; max-height: 100%; }
    .vat-summary-wrap { overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch; }
    .vat-summary-wrap table { min-width: 420px; width: 100%; table-layout: fixed; }
    .vat-summary-wrap th, .vat-summary-wrap td { padding: 6px 8px !important; white-space: nowrap; box-sizing: border-box; }
    .pos-print-layout-slip #printarea { max-width: 80mm; margin-left: auto; margin-right: auto; font-size: 0.875rem; }
    .pos-print-layout-slip .pos-print-header .logo-box { max-width: 80px; height: 45px; }
    .pos-print-layout-slip .pos-print-header, .pos-print-layout-slip .pos-print-header * { font-size: 0.8rem; }
    .pos-print-layout-a5 #printarea { max-width: 148mm; margin-left: auto; margin-right: auto; }
    .pos-print-layout-a4 #printarea { max-width: 210mm; margin-left: auto; margin-right: auto; }
    .pos-print-layout-receipt #printarea { max-width: 210mm; margin-left: auto; margin-right: auto; }
    @media print {
        body * { visibility: hidden; }
        #printarea, #printarea * { visibility: visible; }
        #printarea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
        @page pos-slip { size: 80mm auto; margin: 4mm; }
        @page pos-a5 { size: A5 portrait; margin: 12mm; }
        @page pos-a4 { size: A4 portrait; margin: 12mm; }
        @page pos-receipt { margin: 10mm; }
        #printarea.pos-print-layout-slip {
            page: pos-slip;
            width: 72mm !important;
            max-width: 72mm !important;
            margin: 0 auto !important;
            font-size: 10px !important;
        }
        #printarea.pos-print-layout-a5 {
            page: pos-a5;
            width: 100% !important;
            max-width: 148mm !important;
            margin: 0 auto !important;
        }
        #printarea.pos-print-layout-a4 {
            page: pos-a4;
            width: 100% !important;
            max-width: 210mm !important;
            margin: 0 auto !important;
        }
        #printarea.pos-print-layout-receipt {
            page: pos-receipt;
            width: 100% !important;
            max-width: 210mm !important;
            margin: 0 auto !important;
        }
        .pos-print-layout-slip .pos-print-header .logo-box { max-width: 60px !important; height: 36px !important; }
        .pos-print-layout-slip .pos-print-header, .pos-print-layout-slip .pos-print-header * { font-size: 9px !important; }
        .receipt-copy { page-break-after: always; height: 100%; }
        .receipt-copy:last-child { page-break-after: auto; }
        .segunda-via-watermark { opacity: 0.15; font-size: 3.5rem; }
        .no-print-copies { display: none !important; }
        .vat-summary-wrap { overflow: visible !important; max-width: 100%; page-break-inside: avoid; }
        .vat-summary-wrap table { width: 100% !important; font-size: 0.7rem; }
        .vat-summary-wrap th { white-space: normal !important; word-break: break-word; line-height: 1.2; }
        .vat-summary-wrap th, .vat-summary-wrap td { padding: 4px 6px !important; }
        .view-qrcode-receipt, .view-qrcode-receipt * { visibility: visible !important; }
        .view-qrcode-receipt { display: block !important; overflow: visible !important; min-width: 90px; min-height: 90px; }
        .view-qrcode-receipt img { display: block !important; visibility: visible !important; max-width: 100px !important; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .view-qrcode-receipt svg { display: block !important; visibility: visible !important; }
        .view-qrcode-receipt-svg svg { visibility: visible !important; }
        .view-qrcode-box { overflow: visible !important; }
    }
</style>
@if($standalone)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Receipt') }} - {{ $details['pos_id'] ?? '' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/main.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/style.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/material.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <script src="{{ asset('js/jquery.min.js') }}"></script>
</head>
<body class="pos-print-standalone pos-print-layout-{{ $printLayout }}" style="padding:1rem; background:#f5f5f5;">
@endif
<div class="pt-0 pb-3 {{ $standalone ? 'bg-white rounded p-3' : 'modal-body' }} pos-module position-relative pos-print-layout-{{ $printLayout }}" id="printarea" {{ $standalone ? 'style="box-shadow:0 1px 3px rgba(0,0,0,.1);"' : '' }}>
    <div class="receipt-copy-wrapper receipt-copy">
    @if($segundaVia)
    <div class="segunda-via-watermark" aria-hidden="true">SEGUNDA VIA</div>
    @endif
    <div class="pos-print-header">
        {{-- Company: Logo --}}
        <div class="text-center">
            <div class="logo-box">
                <img src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <span style="display:none; color:#22c55e; font-weight:bold;">LOGO</span>
            </div>
            <div>{{ $settings['company_name'] ?? '' }}</div>
            <div>{{ $settings['company_address'] ?? $settings['mail_from_address'] ?? '' }}</div>
            @if(!empty($settings['company_city']) || !empty($settings['company_state']) || !empty($settings['company_zipcode']))
            <div>{{ $settings['company_city'] ?? '' }}{{ !empty($settings['company_state']) ? ', ' . $settings['company_state'] : '' }}{{ !empty($settings['company_zipcode']) ? ' ' . $settings['company_zipcode'] : '' }}</div>
            @endif
            @if($settings['vat_gst_number_switch'] == 'on' && !empty($settings['vat_number']))
            <div>{{ $settings['tax_type'] ?? 'VAT' }} NR: {{ $settings['vat_number'] ?? '' }}</div>
            @endif
            <div>Phone: {{ $settings['company_telephone'] ?? '' }}</div>
            <div>E-mail: {{ $settings['mail_from_address'] ?? '' }}</div>
        </div>
        @if($isBalancePayment && !empty($balancePaymentExtra))
        <div class="mt-1" style="font-size: 1.3rem;text-align: center;"><b>{{ __('Receipt') }}</b></div>
        @else
        <div class="mt-1" style="font-size: 1.3rem;text-align: center;"><b>{{ $printSource === 'restaurant' ? __('Restaurant') : __('Retail') }} - {{ $paymentDocumentTitle }}</b></div>
        @endif

        <div class="header-sep"></div>

        {{-- Date / Warehouse | Original-Copy / DOC NR --}}
        <div class="doc-row">
            <div class="left">
                <div>Date: {{ $details['date'] ?? date('Y-m-d') }}</div>
                <div class="mt-1">{{ __('pos.branch_name') }}:<br>{{ $details['branch']['name'] ?? '-' }}</div>
            </div>
            <div class="right">
                <div>{{ $segundaVia ? __('Copy') : __('Original') }}</div>
                <div class="mt-1">DOC NR<br><strong>{{ $details['pos_id'] }}</strong></div>
            </div>
        </div>

        <div class="header-sep"></div>

        {{-- Customer: Walk-in Customer | Client Name --}}
        <div class="customer-row">
            {{-- <div class="cust-type">{{ __('pos.walkin_customer') }}</div> --}}
            <div class="cust-name">{{ $clientName }}</div>
        </div>
        @if(!empty($cust['billing_address']) || !empty($cust['billing_phone']) || !empty($cust['email']))
        <div class="customer-details">
            @if(!empty($cust['billing_address']))<div>{{ $cust['billing_address'] }}</div>@endif
            @if($settings['vat_gst_number_switch'] == 'on' && !empty($cust['tax_number']))<div>VAT NR.: {{ $cust['tax_number'] }}</div>@endif
            @if(!empty($cust['billing_phone']))<div>Phone: {{ $cust['billing_phone'] }}</div>@endif
            @if(!empty($cust['email']))<div>E-mail: {{ $cust['email'] }}</div>@endif
        </div>
        @endif
        @if(!empty($details['table_name']))
        <div class="mt-1"><b>{{ __('pos.table') }}:</b> {{ $details['table_name'] }}</div>
        @endif

        <div class="header-sep"></div>
    </div>

    @if($isBalancePayment)
        {{-- Balance payment: only payment block, no items; document is "Receipt" with its own reference --}}
    @else
    {{-- Sales document is always Cash Sale; payment method (Credit, Cash, Visa, etc.) shown in payment section below --}}
    <div class=" text-black text-left fs-5 mt-0 mb-0">{{__('Items')}}</div>
        @foreach ($sales['data'] ?? [] as $key => $value)
            <div class="mt-2">
                <div class="p-0"> <b>{{ $value['name'] }}</b>@if(!empty($value['description']))<br><strong>{{ $value['description'] }}</strong>@endif</div>
                <div class="d-flex product-border">
                    <div>{{ __('pos.quantity_colon') }}</div>
                    <div class="text-end ms-auto">{{ $value['quantity'] }}</div>
                </div>
            </div>
            <div class="d-flex product-border">
                <div>{{ __('pos.price_colon') }}</div>
                <div class="text-end ms-auto">{{ $value['price'] }}</div>
            </div>
            <div class="d-flex product-border">
                <div>{{ __('pos.tax_colon') }}</div>
                <div class="text-end ms-auto"> {{ $value['tax'] }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div>{{ __('pos.tax_amount_colon') }}</div>
                <div class="text-end ms-auto">{{ $value['tax_amount'] }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div>{{ __('pos.sub_total_colon') }}</div>
                <div class="text-end ms-auto"> {{ $value['subtotal'] }}</div>
            </div>
        @endforeach
        {{-- Sub total first, then Discount (before tax/total), then Tip, then Total --}}
        <div class="d-flex product-border mb-2 mt-4">
            <div><b>{{ __('pos.sub_total_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['sub_total'] ?? $sales['total'] }}</div>
        </div>
        <div class="d-flex product-border mb-2">
            <div><b>{{ __('pos.discount_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['discount'] ?? '0' }}</div>
        </div>
        @if(isset($sales['tip']) && $sales['tip'] !== '' && $sales['tip'] != '0' && $sales['tip'] != '0.00')
        <div class="d-flex product-border mb-2">
            <div><b>{{ __('pos.tip') }}:</b></div>
            <div class="text-end ms-auto"> {{ $sales['tip'] }}</div>
        </div>
        @endif
        <div class="d-flex product-border mb-2">
            <div><b>{{ __('pos.total_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['total'] }}</div>
        </div>
    @endif

        @if(!empty($posPayments) && $posPayments->isNotEmpty())
            <div class="product-border mb-2 mt-3 p-2">
                <div class="text-black fw-bold mb-2">{{ __('Payment Method') }}</div>
                @foreach($posPayments as $pmt)
                    <div class="d-flex product-border mb-1">
                        <div>{{ __('Payment Method') }}:</div>
                        <div class="text-end ms-auto text-capitalize">{{ str_replace('_', ' ', $pmt->payment_method ?? '') }}</div>
                    </div>
                    <div class="d-flex product-border mb-1">
                        <div>{{ __('Amount') }}:</div>
                        <div class="text-end ms-auto">{{ $priceUser ? $priceUser->priceFormat($pmt->amount ?? 0) : number_format($pmt->amount ?? 0, 2) }}</div>
                    </div>
                    @if(isset($pmt->change_amount) && $pmt->change_amount > 0)
                        <div class="d-flex product-border mb-1">
                            <div><b>{{ __('Change') }}</b>:</div>
                            <div class="text-end ms-auto"><b>{{ $priceUser ? $priceUser->priceFormat($pmt->change_amount) : number_format($pmt->change_amount, 2) }}</b></div>
                        </div>
                    @endif
                    @if(!empty($pmt->transaction_id))
                        <div class="d-flex product-border mb-1">
                            <div>{{ __('Transaction ID') }}:</div>
                            <div class="text-end ms-auto">{{ $pmt->transaction_id }}</div>
                        </div>
                    @endif
                    @if(!empty($pmt->credit_terms))
                        <div class="d-flex product-border mb-1">
                            <div>{{ __('Credit Days') }}:</div>
                            <div class="text-end ms-auto">{{ $pmt->credit_terms }}</div>
                        </div>
                        <div class="d-flex product-border mb-1">
                            <div>{{ __('Due date') }}:</div>
                            <div class="text-end ms-auto">{{ \Carbon\Carbon::now()->addDays((int)$pmt->credit_terms)->format($settings['date_format'] ?? 'Y-m-d') }}</div>
                        </div>
                    @endif
                    @if(!empty($pmt->notes))
                        <div class="product-border mb-1 p-1 small">
                            <div>{{ __('Notes') }}:</div>
                            <div>{{ $pmt->notes }}</div>
                        </div>
                    @endif
                @endforeach
                @if($isBalancePayment && !empty($balancePaymentExtra))
                    <div class="product-border mb-1 mt-2">
                        <div><b>{{ __('Applied to invoices') }}:</b></div>
                        @if(!empty($balancePaymentExtra['invoices_paid']))
                        <ul class="mb-0 ps-3 small">
                            @foreach($balancePaymentExtra['invoices_paid'] as $inv)
                            <li>{{ $inv['ref_number'] ?? '' }}: {{ $priceUser ? $priceUser->priceFormat($inv['amount'] ?? 0) : number_format($inv['amount'] ?? 0, 2) }}</li>
                            @endforeach
                        </ul>
                        @endif
                    </div>
                    <div class="d-flex product-border mb-1">
                        <div><b>{{ __('Remaining balance') }}:</b></div>
                        <div class="text-end ms-auto">{{ $balancePaymentExtra['new_balance_formatted'] ?? '0' }}</div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Footer: Bank details (only if we have banks) --}}
        @if($bankAccounts->isNotEmpty())
        <div class="pos-print-footer mt-3 p-2 product-border">
            <div class="fw-bold mb-1">{{ __('Bank Details') }}</div>
            @foreach($bankAccounts as $idx => $bank)
            <div class="small mb-1">Bank {{ $idx + 1 }}: {{ $bank->account_number ?? '-' }} @if(!empty($bank->bank_name)) ({{ $bank->bank_name }}) @endif</div>
            @endforeach
        </div>
        @endif

        {{-- VAT summary (incidence) - for MOZ etc. --}}
        @if(count($vatBreakdown) > 0)
        <div class="pos-print-footer mt-2 vat-summary-wrap">
            <table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;">
                <colgroup><col style="width:12%"><col style="width:35%"><col style="width:23%"><col style="width:30%"></colgroup>
                <thead><tr><th>VAT %</th><th>{{ __('Net Amount') }}</th><th>VAT</th><th>{{ __('Amount') }}</th></tr></thead>
                <tbody>
                @foreach($vatBreakdown as $row)
                <tr>
                    <td>{{ number_format($row['rate'], 0) }}</td>
                    <td>{{ $priceUser ? $priceUser->priceFormat($row['net']) : number_format($row['net'], 2) }}</td>
                    <td>{{ $priceUser ? $priceUser->priceFormat($row['vat']) : number_format($row['vat'], 2) }}</td>
                    <td>{{ $priceUser ? $priceUser->priceFormat($row['amount']) : number_format($row['amount'], 2) }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="text-center mt-3 small text-muted">
            <div>{{ __('pos.document_processed_by_computer') }}</div>
            <div class="mt-1">{{ __('pos.thank_you') }}</div>
        </div>

        {{-- Licensing / Certification + Scan-to-view receipt QR (SVG so it prints) --}}
        <div class="pos-print-footer mt-2 d-flex align-items-center gap-3">
            <div class="border rounded p-2 text-center d-flex flex-column align-items-center justify-content-center view-qrcode-box" style="min-width:110px;min-height:110px;line-height:1.2;font-size:0.7rem;overflow:visible;">
                @php
                    $qrSvg = null;
                    $qrHtml = '';
                    try {
                        $qrSvg = \DNS2D::getBarcodeSVG($qrCodeContent, 'QRCODE', 2, 2);
                    } catch (\Throwable $e) {
                        $qrSvg = null;
                    }
                    if (empty($qrSvg)) {
                        try {
                            $qrHtml = \DNS2D::getBarcodeHTML($qrCodeContent, 'QRCODE', 2, 2);
                        } catch (\Throwable $e) {
                            $qrHtml = '';
                        }
                    }
                @endphp
                @if(!empty($qrSvg))
                <div class="view-qrcode-receipt view-qrcode-receipt-svg" style="width:115px;height:115px;flex-shrink:0;">
                    {!! $qrSvg !!}
                </div>
                @elseif(!empty($qrHtml))
                <div class="view-qrcode-receipt" style="width:115px;height:115px;flex-shrink:0;">
                    {!! $qrHtml !!}
                </div>
                @else
                <span class="small text-muted">{{ __('pos.scan_to_view_receipt') }}</span>
                @endif
            </div>
            <div class="small">
                <div>{{ __('pos.licensed_for') }}: {{ $settings['company_name'] ?? '' }}</div>
                <div>{{ __('pos.certified_by') }}:</div>
                <div>{{ __('pos.certificate_nr') }}:</div>
            </div>
        </div>

        <div class="text-end mt-2 small text-muted">
            ERP_SaaS - POS {{ $printSource === 'restaurant' ? __('Restaurant') : __('Retail') }} - {{ $paymentDocumentTitle }}<br>
            {{ date('H:i') }}
        </div>

        @if($hasCreditPayment)
        <div class="pos-print-footer mt-3 p-2 product-border">
            <div class="small fw-bold mb-1">{{ __('pos.client_signature_for_credit') }}</div>
            <div class="small mb-2">{{ __('pos.credit_signature_line') }}</div>
            <div class="border-bottom pb-1 mb-1" style="min-height: 28px;"></div>
            <div class="small text-muted">{{ __('Name') }}:</div>
        </div>
        @endif
    </div>{{-- .receipt-copy --}}
</div>{{-- #printarea --}}

    @if($standalone)
    <div class="mt-3 no-print-copies">
        <button type="button" class="btn-primary" id="print">{{ __('pos.print') }}</button>
    </div>
    @else
    <div class="justify-content-center pt-2 modal-footer no-print-copies d-flex flex-wrap align-items-center gap-2">
        <label class="mb-0 d-flex align-items-center gap-1">
            <span class="small">{{ __('pos.number_of_copies') }}:</span>
            <select id="print-copies" class="form-select form-select-sm" style="width: auto;">
                @for($i = 1; $i <= 10; $i++)
                <option value="{{ $i }}" {{ $i == 1 ? 'selected' : '' }}>{{ $i }}</option>
                @endfor
            </select>
        </label>
        <a href="#" id="print" class="btn btn-primary btn-sm">{{ __('pos.print') }}</a>
        @if(!$segundaVia && $posIdRaw)
        <button id="segunda-via-link" class="btn btn-outline-secondary btn-sm" title="{{ __('pos.segunda_via') }}">{{ __('pos.segunda_via') }}</button>
        @endif
    </div>
    @endif
<script>
var posPrintLayout = '{{ $printLayout }}';
var posDirectPrint = {{ $directPrint ? 'true' : 'false' }};
(function() {
    function doPrint(copies) {
        var printarea = document.getElementById("printarea");
        var receipt = printarea ? printarea.querySelector(".receipt-copy") : null;
        if (!receipt) {
            window.print();
            return;
        }
        copies = Math.max(1, parseInt(copies, 10) || 1);
        var clones = [];
        for (var i = 1; i < copies; i++) {
            var clone = receipt.cloneNode(true);
            var label = clone.querySelector('.doc-row .right div:first-child');
            if (label) {
                label.textContent = (i === 1) ? '{{ __("Copy") }}' : ('{{ __("Copy") }} ' + i);
            }
            printarea.appendChild(clone);
            clones.push(clone);
        }
        if (posDirectPrint) {
            function removeClones() {
                clones.forEach(function(c) { if (c && c.parentNode) c.remove(); });
            }
            setTimeout(function() {
                window.print();
                setTimeout(removeClones, 1500);
            }, 150);
            return;
        }
        var html = printarea.innerHTML;
        clones.forEach(function(c) { c.remove(); });
        var receiptStyles = '';
        var styleEl = document.getElementById('pos-receipt-styles') || document.querySelector('#commonModal .body style') || document.querySelector('#commonModal style');
        if (styleEl && styleEl.innerHTML) receiptStyles = styleEl.innerHTML;
        if (!receiptStyles) {
            receiptStyles = '.pos-print-header .header-sep{border-bottom:1px dotted #333;margin:10px 0}.pos-print-header .logo-box{max-width:120px;height:70px;margin:0 auto 8px;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff}.pos-print-header .logo-box img{max-width:100%;max-height:100%;object-fit:contain}.pos-print-header .doc-row{display:flex;justify-content:space-between;margin-bottom:6px}.pos-print-header .doc-row .left,.pos-print-header .doc-row .right{flex:1}.pos-print-header .doc-row .right{text-align:right}.pos-print-header .customer-row{display:flex;justify-content:space-between}.pos-print-header .customer-row .cust-name{font-weight:bold;color:#333}.product-border{border-bottom:1px solid #ddd;padding:2px 0}.d-flex{display:flex}.text-end{text-align:right}.ms-auto{margin-left:auto}.view-qrcode-receipt img{max-width:70px;height:auto}.vat-summary-wrap table{width:100%;font-size:0.85rem}.vat-summary-wrap th,.vat-summary-wrap td{padding:6px 8px}.receipt-copy-wrapper{position:relative}';
        }
        var printWin = window.open('', '_blank', 'width=800,height=600');
        if (!printWin) {
            $('.row').addClass('d-none');
            $('.toast').addClass('d-none');
            $('.no-print-copies').addClass('d-none');
            window.print();
            $('.row').removeClass('d-none');
            $('.toast').removeClass('d-none');
            $('.no-print-copies').removeClass('d-none');
            return;
        }
        var doc = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>{{ __("Receipt") }}</title>';
        doc += '<link rel="stylesheet" href="{{ asset("assets/css/plugins/main.css") }}">';
        doc += '<link rel="stylesheet" href="{{ asset("assets/css/plugins/style.css") }}">';
        doc += '<link rel="stylesheet" href="{{ asset("assets/css/style.css") }}">';
        doc += '<link rel="stylesheet" href="{{ asset("css/custom.css") }}">';
        var layout = (typeof posPrintLayout !== 'undefined' ? posPrintLayout : 'receipt');
        doc += '<style>';
        doc += 'body{background:#fff;margin:0;padding:15px;font-family:Arial,sans-serif;box-sizing:border-box}';
        doc += '.print-center-wrap{width:100%;display:flex;justify-content:center;align-items:flex-start;min-height:100vh}';
        if (layout === 'slip') {
            doc += '#printarea{width:80mm;max-width:100%;flex-shrink:0}';
        } else if (layout === 'a5') {
            doc += '#printarea{width:100%;max-width:148mm;flex-shrink:0}';
        } else {
            doc += '#printarea{width:100%;max-width:210mm;flex-shrink:0}';
        }
        doc += receiptStyles;
        doc += '</style></head><body class="pos-print-standalone pos-print-layout-' + layout + '"><div class="print-center-wrap"><div id="printarea" class="pt-0 pb-3 pos-module pos-print-layout-' + layout + '">' + html + '</div></div></body></html>';
        printWin.document.write(doc);
        printWin.document.close();
        printWin.focus();
        setTimeout(function() {
            printWin.print();
            try { printWin.onafterprint = function() { printWin.close(); }; } catch (err) {}
            setTimeout(function() { try { printWin.close(); } catch (e) {} }, 1500);
        }, 300);
    }

    $(document).off("click","#print").on("click","#print",function(e){
        e.preventDefault();
        e.stopImmediatePropagation();
        var copies = document.getElementById("print-copies") ? document.getElementById("print-copies").value : 1;
        doPrint(copies);
    });
    $(document).on('click','#segunda-via-link',function(){
        var pos_id = '{{ (int)$posIdRaw }}';
        $('#commonModal .modal-dialog').addClass('modal-sm');
        $.get('{{url("/")}}/printview/pos', {
            pos_id: pos_id,
            source: 'retail',
            modal: 1,
            segunda_via: 1,
            source: '{{ $printSource }}'
        }, function(html){
            $('#commonModal .modal-title').text('POS Receipt');
            $('#commonModal .body').html(html);
            $('#commonModal').modal('show');
        });
    });
})();
</script>
@if($standalone)
</body>
</html>
@endif
