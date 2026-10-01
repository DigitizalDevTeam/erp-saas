{{-- Compact Layout 1: thermal / receipt style (ASAROOJ-like). Existing printview untouched. --}}
@php include resource_path('views/pos/receipts/_prepare.php'); @endphp
@include('pos.receipts._bootstrap')

<div class="pt-0 pb-3 {{ $standalone ? 'bg-white rounded p-3' : 'modal-body' }} pos-module position-relative pos-print-layout-{{ $printLayout }}" id="printarea" {{ $standalone ? 'style="box-shadow:0 1px 3px rgba(0,0,0,.1);"' : '' }}>
    <div class="receipt-copy-wrapper receipt-copy">
        @if($segundaVia)
            <div class="segunda-via-watermark" aria-hidden="true">SEGUNDA VIA</div>
        @endif

        <div class="pos-print-header pos-compact-supplier text-center">
            <div class="logo-box">
                <img src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'">
            </div>
            <div style="font-weight:700;font-size:1.05rem;color:#111;">{{ $settings['company_name'] ?? '' }}</div>
            @if($companyVat !== '')
                <div style="color:#111;">{{ $settings['tax_type'] ?? __('VAT') }}: {{ $companyVat }}</div>
            @endif
            @if($companyAddressLine !== '')
                <div style="color:#111;">{{ $companyAddressLine }}</div>
            @endif
            @if($companyPhone !== '' || $companyEmail !== '')
                <div style="color:#111;">
                    @if($companyPhone !== ''){{ $companyPhone }}@endif
                    @if($companyPhone !== '' && $companyEmail !== '') | @endif
                    @if($companyEmail !== ''){{ $companyEmail }}@endif
                </div>
            @endif
            <div class="mt-1" style="font-weight:700;color:#111;">
                {{ $printSource === 'restaurant' ? __('Restaurant') : __('Retail') }} - {{ $paymentDocumentTitle }}
            </div>
        </div>

        <div class="header-sep"></div>

        <div class="pos-compact-section">
            <div class="pos-compact-kv"><span class="k">{{ __('Date') }}:</span><span class="v">{{ $details['date'] ?? date('Y-m-d') }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('Invoice No') }}:</span><span class="v">{{ $details['pos_id'] ?? '-' }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('Operator') }}:</span><span class="v">{{ $operatorName }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ $segundaVia ? __('Copy') : __('Original') }}</span><span class="v">{{ $details['branch']['name'] ?? ($details['warehouse']['name'] ?? '-') }}</span></div>
            @if(!empty($details['table_name']))
                <div class="pos-compact-kv"><span class="k">{{ __('pos.table') }}:</span><span class="v">{{ $details['table_name'] }}</span></div>
            @endif
        </div>

        <div class="header-sep"></div>

        <div class="pos-compact-customer pos-compact-section">
            <div class="pos-compact-kv"><span class="k">{{ __('Customer') }}:</span><span class="v">{{ $clientName }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('NUIT') }}:</span><span class="v">{{ $custVat !== '' ? $custVat : '—' }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('Address') }}:</span><span class="v">{{ $custAddress !== '' ? $custAddress : '—' }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('Contact') }}:</span><span class="v">{{ $custPhone !== '' ? $custPhone : '—' }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('Email') }}:</span><span class="v">{{ $custEmail !== '' ? $custEmail : '—' }}</span></div>
            <div class="pos-compact-kv"><span class="k">{{ __('Currency') }}:</span><span class="v">{{ $currency }}</span></div>
        </div>

        <div class="header-sep"></div>

        @if(!$isBalancePayment)
            <table class="pos-compact-table">
                <thead>
                <tr>
                    <th style="width:40%;text-align:left;">{{ __('Product') }}</th>
                    <th class="num" style="width:22%;">{{ __('Price') }}</th>
                    <th class="num" style="width:16%;">{{ __('Qty') }}</th>
                    <th class="num" style="width:22%;">{{ __('Total') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($sales['data'] ?? [] as $value)
                    <tr>
                        <td>
                            <strong>{{ $value['name'] ?? '' }}</strong>
                            @if(!empty($value['tax']))<div class="small">{{ __('VAT') }} {{ $value['tax'] }}</div>@endif
                            @if(!empty($value['description']))<div class="small item-note"><strong>{{ __('Note') }}:</strong> {{ $value['description'] }}</div>@endif
                        </td>
                        <td class="num">{{ $value['price'] ?? '' }}</td>
                        <td class="num">{{ $value['quantity'] ?? '' }}</td>
                        <td class="num">{{ $value['subtotal'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="pos-compact-section mt-2">
                <div class="pos-compact-kv"><span class="k">{{ __('Sub Total') }}</span><span class="v">{{ $sales['sub_total'] ?? ($sales['total'] ?? '') }}</span></div>
                <div class="pos-compact-kv"><span class="k">{{ __('Discount') }}</span><span class="v">{{ $sales['discount'] ?? '0' }}</span></div>
                @if(isset($sales['tip']) && $sales['tip'] !== '' && $sales['tip'] != '0' && $sales['tip'] != '0.00')
                    <div class="pos-compact-kv"><span class="k">{{ __('pos.tip') }}</span><span class="v">{{ $sales['tip'] }}</span></div>
                @endif
                <div class="pos-compact-kv"><span class="k"><b>{{ __('Grand Total') }}</b></span><span class="v"><b>{{ $sales['total'] ?? '' }}</b></span></div>
                @if(!empty($showPaidRow))
                <div class="pos-compact-kv"><span class="k">{{ __('Paid') }}</span><span class="v">{{ $paidFmt }}</span></div>
                @endif
                <div class="pos-compact-kv"><span class="k">{{ $trocoPendenteLabel }}</span><span class="v">{{ $trocoPendenteValue }}</span></div>
            </div>
        @endif

        @if(!empty($posPayments) && $posPayments->isNotEmpty())
            <div class="pos-compact-section">
                <div class="header-sep"></div>
                @foreach($posPayments as $pmt)
                    <div class="pos-compact-kv"><span class="k">{{ __('Payment Method') }}</span><span class="v">{{ \App\Models\PosPayment::labelForMethod($pmt->payment_method ?? '') }}</span></div>
                    <div class="pos-compact-kv"><span class="k">{{ __('Amount') }}</span><span class="v">{{ $fmtMoney($pmt->amount ?? 0) }}</span></div>
                @endforeach
                @if(!empty($paymentNotes))
                    <div class="pos-compact-kv"><span class="k">{{ __('Notes') }}</span><span class="v">{{ $paymentNotes }}</span></div>
                @endif
                @if($isBalancePayment && !empty($balancePaymentExtra))
                    <div class="pos-compact-kv"><span class="k">{{ __('Remaining balance') }}</span><span class="v">{{ $balancePaymentExtra['new_balance_formatted'] ?? '0' }}</span></div>
                @endif
            </div>
        @endif

        @if($hasCreditPayment)
            <div class="pos-compact-section" style="border:1px solid #333;padding:6px;margin-top:8px;">
                <div class="bold small">{{ __('pos.client_signature_for_credit') }}</div>
                <div style="min-height:28px;border-bottom:1px solid #333;margin:6px 0;"></div>
                <div class="pos-compact-kv"><span class="k">{{ __('Due Amount') }}</span><span class="v">{{ $trocoPendenteValue }}</span></div>
            </div>
        @endif

        @if(count($vatBreakdown) > 0)
            <div class="vat-summary-wrap mt-2">
                <table class="pos-compact-table">
                    <thead>
                    <tr>
                        <th>{{ __('VAT') }} %</th>
                        <th class="num">{{ __('Net Amount') }}</th>
                        <th class="num">{{ __('Tax') }}</th>
                        <th class="num">{{ __('Amount') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($vatBreakdown as $row)
                        <tr>
                            <td>{{ number_format($row['rate'], 0) }}</td>
                            <td class="num">{{ $fmtMoney($row['net']) }}</td>
                            <td class="num">{{ $fmtMoney($row['vat']) }}</td>
                            <td class="num">{{ $fmtMoney($row['amount']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(!empty($qrSvg) || !empty($qrHtml))
            <div class="text-center mt-2">
                <div class="view-qrcode-receipt-svg d-inline-block" style="width:40mm;height:40mm;">
                    @if(!empty($qrSvg)){!! $qrSvg !!}@else{!! $qrHtml !!}@endif
                </div>
            </div>
        @endif

        <div class="text-center mt-2 small" style="color:#333;">
            <div>{{ __('pos.thank_you') }}</div>
            <div>{{ __('Invoice generated by ERPSaaS') }}</div>
            @if($companyPhone !== '')<div>{{ __('Phone') }}: {{ $companyPhone }}</div>@endif
            <div>{{ $companyWebsite }}</div>
        </div>
    </div>
</div>

@include('pos.receipts._print_footer')
