@php
    $standalone = $standalone ?? false;
    $settings = $settings ?? Utility::settings();
    $hasCreditPayment = !empty($posPayments) && $posPayments->isNotEmpty() && $posPayments->contains(function ($p) { return in_array(strtolower($p->payment_method ?? ''), ['credit']); });
    $paymentDocumentTitle = $hasCreditPayment ? __('Credit Sale') : __('Cash Sale');
    $creditPmt = $hasCreditPayment ? $posPayments->first(function ($p) { return strtolower($p->payment_method ?? '') === 'credit'; }) : null;
    $amountReceived = $posPayments ? ($posPayments->sum('amount') - ($creditPmt ? (float)$creditPmt->amount : 0)) : 0;
    $balance = $creditPmt ? (float)$creditPmt->amount : 0;
    $dueDate = $creditPmt && !empty($creditPmt->credit_terms) ? \Carbon\Carbon::now()->addDays((int)$creditPmt->credit_terms)->format(isset($settings['date_format']) ? $settings['date_format'] : 'Y-m-d') : '-';
    $logoPath = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::getValByName('company_logo_dark');
    $pos_logo = \App\Models\Utility::getValByName('pos_logo');
    $headerLogo = (isset($pos_logo) && $pos_logo) ? \App\Models\Utility::get_file('pos_logo/') . $pos_logo : $logoPath . '/' . ($company_logo ?? 'logo-dark.png');
    $docTitle = $hasCreditPayment ? __('Credit Receipt') : __('Tax Invoice');
    $clientName = isset($details['client_name']) ? $details['client_name'] : (isset($details['customer']['name']) ? $details['customer']['name'] : __('pos.walkin_customer'));
    $cust = $details['customer'] ?? [];
    $vatBreakdown = $vatBreakdown ?? [];
    $bankAccounts = $bankAccounts ?? collect();
    $segundaVia = $segundaVia ?? false;
    $posIdRaw = $posIdRaw ?? null;
    $receiptLinkUrl = $receiptLinkUrl ?? null;
    $printSource = $printSource ?? 'retail';
@endphp
<style>
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
    .receipt-copy-wrapper { position: relative; }
    .receipt-copy-clone { page-break-before: always; }
    .view-qrcode-receipt img { max-width: 70px; height: auto; display: block; }
    .view-qrcode-receipt-svg { display: inline-block; }
    .view-qrcode-receipt-svg { line-height: 0; }
    .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; max-width: 100%; max-height: 100%; }
    @media print {
        .segunda-via-watermark { opacity: 0.15; font-size: 3.5rem; }
        .no-print-copies { display: none !important; }
        /* Prevent bottom cut-off in print preview and on paper */
        #printarea { padding-bottom: 0.6in !important; }
        .receipt-copy { padding-bottom: 0.2in; }
        /* Ensure QR code prints (visible in print preview and on paper) */
        .view-qrcode-receipt, .view-qrcode-receipt * { visibility: visible !important; }
        .view-qrcode-receipt { display: block !important; overflow: visible !important; min-width: 90px; min-height: 90px; }
        .view-qrcode-receipt img { display: block !important; visibility: visible !important; max-width: 100px !important; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .view-qrcode-receipt svg { display: block !important; visibility: visible !important;}
        .view-qrcode-receipt-svg svg { visibility: visible !important; }
        .view-qrcode-box { overflow: visible !important; }
    }
</style>
@if($standalone)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Receipt') }} - {{ $details['pos_id'] ?? '' }}</title>
    <style>body{font-family:system-ui,sans-serif;}.btn-primary{background:#0d6efd;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:14px;}.btn-primary:hover{background:#0b5ed7;}</style>
</head>
<body style="padding:1rem; background:#f5f5f5;">
@endif
<div class="pt-0 pb-3 {{ $standalone ? 'bg-white rounded p-3' : 'modal-body' }} pos-module position-relative" id="printarea" {{ $standalone ? 'style="box-shadow:0 1px 3px rgba(0,0,0,.1);"' : '' }}>
    @if($segundaVia)
    <div class="segunda-via-watermark" aria-hidden="true">SEGUNDA VIA</div>
    @endif
    <div class="receipt-copy-wrapper receipt-copy">
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

        <div class="header-sep"></div>

        {{-- Date / Warehouse | Original-Copy / DOC NR --}}
        <div class="doc-row">
            <div class="left">
                <div>Date: {{ $details['date'] ?? date('Y-m-d') }}</div>
                <div class="mt-1">{{ __('pos.warehouse_name') }}:<br>{{ $details['warehouse']['name'] ?? '-' }}</div>
            </div>
            <div class="right">
                <div>{{ $segundaVia ? 'SEGUNDA VIA' : 'Original/Copy' }}</div>
                <div class="mt-1">DOC NR<br><strong>{{ $details['pos_id'] }}</strong></div>
            </div>
        </div>

        <div class="header-sep"></div>

        {{-- Customer: Walk-in Customer | Client Name --}}
        <div class="customer-row">
            <div class="cust-type">{{ __('pos.walkin_customer') }}</div>
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

    @if($hasCreditPayment)
        <div class="product-border mt-3 p-3">
            <div class="text-black fw-bold mb-2">{{ __('Credit Receipt') }}</div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Receipt reference') }}:</b></div>
                <div class="text-end ms-auto">{{ $details['pos_id'] }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Amount received') }}:</b></div>
                <div class="text-end ms-auto">{{ Auth::user()->priceFormat($amountReceived) }} <span class="text-muted">({{ __('pos.by_writing') }}: {{ \App\Models\Utility::amountInWords($amountReceived) }})</span></div>
            </div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Balance') }}:</b></div>
                <div class="text-end ms-auto">{{ Auth::user()->priceFormat($balance) }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Due date') }}:</b></div>
                <div class="text-end ms-auto">{{ $dueDate }}</div>
            </div>
        </div>
    @else
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
        <div class="d-flex product-border mb-2 mt-4">
            <div><b>{{ __('pos.discount_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['discount'] }}</div>
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
                <div class="text-black fw-bold mb-2">{{ $paymentDocumentTitle }}</div>
                @foreach($posPayments as $pmt)
                    <div class="d-flex product-border mb-1">
                        <div>{{ __('Payment Method') }}:</div>
                        <div class="text-end ms-auto text-capitalize">{{ str_replace('_', ' ', $pmt->payment_method ?? '') }}</div>
                    </div>
                    <div class="d-flex product-border mb-1">
                        <div>{{ __('Amount') }}:</div>
                        <div class="text-end ms-auto">{{ Auth::user()->priceFormat($pmt->amount ?? 0) }}</div>
                    </div>
                    @if(isset($pmt->change_amount) && $pmt->change_amount > 0)
                        <div class="d-flex product-border mb-1">
                            <div><b>{{ __('Change') }}</b>:</div>
                            <div class="text-end ms-auto"><b>{{ Auth::user()->priceFormat($pmt->change_amount) }}</b></div>
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
                    @endif
                    @if(!empty($pmt->notes))
                        <div class="product-border mb-1 p-1 small">
                            <div>{{ __('Notes') }}:</div>
                            <div>{{ $pmt->notes }}</div>
                        </div>
                    @endif
                @endforeach
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
        <div class="pos-print-footer mt-2">
            <table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;">
                <thead><tr><th>VAT %</th><th>{{ __('Net Amount') }}</th><th>VAT</th><th>{{ __('Amount') }}</th></tr></thead>
                <tbody>
                @foreach($vatBreakdown as $row)
                <tr>
                    <td>{{ number_format($row['rate'], 0) }}</td>
                    <td>{{ Auth::user()->priceFormat($row['net']) }}</td>
                    <td>{{ Auth::user()->priceFormat($row['vat']) }}</td>
                    <td>{{ Auth::user()->priceFormat($row['amount']) }}</td>
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
        <div class="pos-print-footer mt-2 d-flex align-items-start gap-3">
            <div class="border rounded p-2 text-center d-flex flex-column align-items-center justify-content-center view-qrcode-box" style="min-width:110px;min-height:110px;line-height:1.2;font-size:0.7rem;overflow:visible;">
                @if($receiptLinkUrl)
                <div class="view-qrcode-receipt view-qrcode-receipt-svg" style="width:115px;height:130px;flex-shrink:0;">
                    @php
                        $qrSvg = null;
                        try {
                            $qrSvg = \DNS2D::getBarcodeSVG($receiptLinkUrl, 'QRCODE', 2, 2);
                        } catch (\Throwable $e) {
                            $qrSvg = null;
                        }
                    @endphp
                    @if(!empty($qrSvg))
                        {!! $qrSvg !!}
                    @else
                        {!! DNS2D::getBarcodeHTML($receiptLinkUrl, 'QRCODE', 2, 2) !!}
                    @endif
                </div>
                <span class="mt-1">{{ __('pos.scan_to_view_receipt') }}</span>
                @else
                DOC QR code
                @endif
            </div>
            <div class="small">
                <div>Licensed for: {{ $settings['company_name'] ?? '' }}</div>
                <div>Certified by:</div>
                <div>Certificate NR:</div>
            </div>
        </div>

        <div class="text-end mt-2 small text-muted">
            ERP_SaaS - POS {{ $printSource === 'restaurant' ? __('Restaurant') : __('Retail') }}<br>
            {{ date('H:i') }}
        </div>

        @if($hasCreditPayment)
        <div class="pos-print-footer mt-3 p-2 product-border">
            <div class="small mb-2">{{ __('pos.credit_signature_line') }}</div>
            <div class="border-bottom pb-1 mb-1" style="min-height: 28px;"></div>
            <div class="small text-muted">{{ __('Name') }}:</div>
        </div>
        @endif
    </div>{{-- .receipt-copy --}}
</div>{{-- #printarea --}}

    @if($standalone)
    <div class="mt-3 no-print-copies">
        <button type="button" class="btn-primary" onclick="window.print();">{{ __('pos.print') }}</button>
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
        <a href="{{ url('printview/pos?pos_id=' . (int)$posIdRaw . '&segunda_via=1&source=' . $printSource) }}" id="segunda-via-link" target="_blank" class="btn btn-outline-secondary btn-sm" title="{{ __('pos.segunda_via') }}">{{ __('pos.segunda_via') }}</a>
        @endif
    </div>
    @endif
<script>
(function() {
    function doPrint(copies) {
        var printarea = document.getElementById("printarea");
        var receiptCopy = printarea.querySelector(".receipt-copy");
        if (!receiptCopy) { window.print(); return; }
        copies = Math.max(1, parseInt(copies, 10) || 1);
        var clones = [];
        for (var i = 1; i < copies; i++) {
            var clone = receiptCopy.cloneNode(true);
            clone.classList.add("receipt-copy-clone");
            receiptCopy.parentNode.appendChild(clone);
            clones.push(clone);
        }
        $('.row').addClass('d-none');
        $('.toast').addClass('d-none');
        $('.no-print-copies').addClass('d-none');
        window.print();
        clones.forEach(function(el) { el.remove(); });
        $('.row').removeClass('d-none');
        $('.no-print-copies').removeClass('d-none');
        $('.toast').removeClass('d-none');
    }
    $(document).off("click", "#print").on("click", "#print", function(e) {
        e.preventDefault();
        var copies = document.getElementById("print-copies") ? document.getElementById("print-copies").value : 1;
        doPrint(copies);
    });
})();
</script>
@if($standalone)
</body>
</html>
@endif
