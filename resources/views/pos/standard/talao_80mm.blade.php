{{-- Mozambique standard 80mm Talão — structure from UTHARA HTML ref. --}}
<style>
.mz-talao { color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10px; line-height: 1.35; }
.mz-talao .center { text-align: center; }
.mz-talao .right { text-align: right; }
.mz-talao .bold { font-weight: 700; }
.mz-talao .small { font-size: 8px; }
.mz-talao .logo { max-width: 38mm; max-height: 15mm; margin: 0 auto 2mm; display: block; object-fit: contain; }
.mz-talao h1.doc-type { font-size: 13px; margin: 2mm 0 .5mm; text-transform: uppercase; letter-spacing: .3px; color: #111; }
.mz-talao .document-no { font-weight: 700; font-size: 11px; color: #111; }
.mz-talao .rule { border-top: 1px dashed #000; margin: 2mm 0; }
.mz-talao .solid { border-top: 1.5px solid #000; margin: 2mm 0; }
.mz-talao .row { display: flex; justify-content: space-between; gap: 3mm; color: #111; margin: .5mm 0; }
.mz-talao .row span:last-child { text-align: right; }
.mz-talao table.items { width: 100%; border-collapse: collapse; table-layout: fixed; color: #111; }
.mz-talao table.items th { border-bottom: 1px solid #000; padding: 1mm .4mm; text-align: left; font-size: 8px; text-transform: uppercase; color: #111; background: #fff; }
.mz-talao table.items td { padding: 1.1mm .4mm; vertical-align: top; font-size: 9px; overflow-wrap: anywhere; color: #111; }
.mz-talao .qty { width: 10mm; text-align: center; }
.mz-talao .price { width: 18mm; text-align: right; }
.mz-talao .total { width: 19mm; text-align: right; }
.mz-talao .item-detail { display: block; font-size: 7.5px; color: #333; margin-top: .4mm; }
.mz-talao .totals { margin-left: auto; width: 52mm; color: #111; }
.mz-talao .totals .grand { font-size: 13px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 1.5mm 0; margin-top: 1mm; }
.mz-talao .tax-table { width: 100%; border-collapse: collapse; color: #111; }
.mz-talao .tax-table th, .mz-talao .tax-table td { text-align: right; font-size: 8px; padding: .7mm .3mm; color: #111; border-bottom: 1px solid #ddd; }
.mz-talao .tax-table th:first-child, .mz-talao .tax-table td:first-child { text-align: left; }
.mz-talao .qr-wrap { display: flex; align-items: center; justify-content: center; gap: 3mm; margin: 2mm 0; }
.mz-talao .view-qrcode-receipt-svg, .mz-talao .view-qrcode-receipt { width: 24mm; height: 24mm; flex-shrink: 0; }
.mz-talao .view-qrcode-receipt-svg svg { display: block !important; width: 100% !important; height: 100% !important; }
.mz-talao .validation { max-width: 38mm; font-size: 8px; color: #111; }
.mz-talao .footer { font-size: 8px; text-align: center; color: #111; }
.mz-talao .sig-box { border: 1px solid #333; min-height: 28px; margin-top: 2mm; padding: 1mm; }
</style>

<div class="mz-talao receipt-body">
    <div class="center">
        <img class="logo" src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'">
        <div class="bold">{{ $companyName }}</div>
        <div>{{ __('NUIT') }}: {{ $companyVat !== '' ? $companyVat : '—' }}</div>
        <div>{{ $companyAddressLine }}</div>
        <div>
            @if($companyPhone !== '')
                {{ __('Phone') }}: {{ $companyPhone }}
            @endif
            @if($companyPhone !== '' && $companyEmail !== '')
                ·
            @endif
            @if($companyEmail !== '')
                {{ $companyEmail }}
            @endif
        </div>
    </div>

    <div class="solid"></div>
    <h1 class="center doc-type">{{ $paymentDocumentTitle }}</h1>
    <div class="center document-no">{{ __('No.') }} {{ $docNumber }}</div>
    <div class="center small">{{ $copyLabel }} · {{ __('Issued at') }} {{ $docDateTime }}</div>
    <div class="rule"></div>

    <div class="row"><span>{{ __('Customer') }}:</span><span class="bold">{{ $clientName }}</span></div>
    <div class="row"><span>{{ __('NUIT') }}:</span><span>{{ $custVat !== '' ? $custVat : '—' }}</span></div>
    <div class="row"><span>{{ __('Address') }}:</span><span>{{ $custAddress !== '' ? $custAddress : '—' }}</span></div>
    <div class="row"><span>{{ __('Contact') }}:</span><span>{{ $custPhone !== '' ? $custPhone : '—' }}</span></div>
    <div class="row"><span>{{ __('Email') }}:</span><span>{{ $custEmail !== '' ? $custEmail : '—' }}</span></div>
    <div class="row"><span>{{ __('Currency') }}:</span><span>{{ $currency }}</span></div>
    @if(!empty($details['table_name']))
        <div class="row"><span>{{ __('pos.table') }}:</span><span>{{ $details['table_name'] }}</span></div>
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
        <div class="row"><span>{{ __('Sub Total') }}</span><span>{{ $salesSubTotal }}</span></div>
        <div class="row"><span>{{ __('Discount') }}</span><span>{{ $salesDiscount }}</span></div>
        <div class="row"><span>{{ __('VAT') }} / IVA</span><span>{{ $ivaTotalFmt }}</span></div>
        @if(!empty($salesTip) && $salesTip !== '0' && $salesTip !== '0.00')
            <div class="row"><span>{{ __('pos.tip') }}</span><span>{{ $salesTip }}</span></div>
        @endif
        <div class="row grand bold"><span>{{ __('TOTAL') }}</span><span>{{ $salesTotal }} {{ $currency }}</span></div>
        <div class="row"><span>{{ __('Paid') }}</span><span>{{ $paidFmt }}</span></div>
        <div class="row"><span>{{ $trocoPendenteLabel }}</span><span>{{ $trocoPendenteValue }}</span></div>
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
                <td>{{ number_format((float)($row['rate'] ?? 0), 0) }}%</td>
                <td>{{ $fmt($row['net'] ?? 0) }}</td>
                <td>{{ $fmt($row['vat'] ?? 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <div class="rule"></div>
    <div class="row"><span>{{ __('Payment Method') }}:</span><span class="bold text-capitalize">{{ $paymentMethodLabel }}</span></div>
    <div class="row"><span>{{ __('Reference') }}:</span><span>{{ $paymentRefLabel }}</span></div>
    <div class="row"><span>{{ __('Operator') }}:</span><span>{{ $operatorName }}</span></div>
    <div class="row"><span>{{ __('Branch') }}:</span><span>{{ $branchName }}</span></div>
    @if($hasCreditPayment && $dueDate)
        <div class="row"><span>{{ __('Due date') }}:</span><span>{{ $dueDate }}</span></div>
    @endif

    @if($hasCreditPayment)
    <div class="rule"></div>
    <div class="bold small">{{ __('pos.client_signature_for_credit') }}</div>
    <div class="sig-box"></div>
    <div class="small">{{ __('Name') }}: _______________________</div>
    <div class="row"><span>{{ __('Pendente') }}:</span><span class="bold">{{ $trocoPendenteValue }}</span></div>
    @endif

    <div class="qr-wrap">
        @if(!empty($qrSvg))
            <div class="view-qrcode-receipt view-qrcode-receipt-svg">{!! $qrSvg !!}</div>
        @elseif(!empty($qrHtml))
            <div class="view-qrcode-receipt">{!! $qrHtml !!}</div>
        @endif
        <div class="validation">
            <div class="bold">{{ __('Consult and validate the invoice') }}</div>
            @if($receiptLinkUrl)<div class="small" style="overflow-wrap:anywhere;">{{ $receiptLinkUrl }}</div>@endif
            <div>{{ __('Code') }}: <strong>{{ $docNumber }}</strong></div>
        </div>
    </div>

    <div class="rule"></div>
    <div class="footer">
        <div class="bold">{{ __('pos.thank_you') }}</div>
        <div>{{ __('Invoice generated by ERPSaaS') }}</div>
        @if($companyPhone !== '')<div>{{ __('Phone') }}: {{ $companyPhone }}</div>@endif
        <div>
            www.uthara.co.mz
            @if($companyEmail !== '')
                · {{ $companyEmail }}
            @endif
        </div>
        <div class="small">{{ __('pos.document_processed_by_computer') }}</div>
    </div>
</div>
