{{-- Compact Layout 2: denser Mozambique-style receipt. Existing printview untouched. --}}
@php include resource_path('views/pos/receipts/_prepare.php'); @endphp
@include('pos.receipts._bootstrap')

<div class="pt-0 pb-3 {{ $standalone ? 'bg-white rounded p-3' : 'modal-body' }} pos-module position-relative pos-print-layout-{{ $printLayout }}" id="printarea" {{ $standalone ? 'style="box-shadow:0 1px 3px rgba(0,0,0,.1);"' : '' }}>
    <div class="receipt-copy-wrapper receipt-copy">
        @if($segundaVia)
            <div class="segunda-via-watermark" aria-hidden="true">SEGUNDA VIA</div>
        @endif

        <div class="pos-mz-header">
            <div class="pos-compact-supplier" style="flex:1;">
                <div class="logo-box" style="margin:0 0 4px;">
                    <img src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'">
                </div>
                <div style="font-weight:700;color:#111;">{{ $settings['company_name'] ?? '' }}</div>
                @if($companyVat !== '')
                    <div style="color:#111;font-size:0.85rem;">{{ $settings['tax_type'] ?? __('VAT') }} Nº: {{ $companyVat }}</div>
                @endif
                @if($companyAddressLine !== '')
                    <div style="color:#111;font-size:0.85rem;">{{ $companyAddressLine }}</div>
                @endif
                <div style="color:#111;font-size:0.85rem;">
                    @if($companyPhone !== ''){{ __('Phone') }}: {{ $companyPhone }}@endif
                    @if($companyPhone !== '' && $companyEmail !== '') · @endif
                    @if($companyEmail !== ''){{ __('Email') }}: {{ $companyEmail }}@endif
                </div>
            </div>
            <div class="pos-mz-badge">
                {{ $printSource === 'restaurant' ? __('Bill') : $paymentDocumentTitle }}<br>
                {{ $details['pos_id'] ?? '' }}
            </div>
        </div>

        <div class="pos-mz-bill">
            <div class="pos-compact-customer">
                <div style="font-weight:700;margin-bottom:4px;">{{ __('Bill To') }}</div>
                <div>{{ $clientName }}</div>
                <div>{{ __('NUIT') }}: {{ $custVat !== '' ? $custVat : '—' }}</div>
                <div>{{ __('Address') }}: {{ $custAddress !== '' ? $custAddress : '—' }}</div>
                <div>{{ __('Contact') }}: {{ $custPhone !== '' ? $custPhone : '—' }}</div>
                <div>{{ __('Email') }}: {{ $custEmail !== '' ? $custEmail : '—' }}</div>
                <div>{{ __('Currency') }}: {{ $currency }}</div>
            </div>
            <div>
                <div class="pos-compact-kv"><span class="k">{{ __('Date') }}</span><span class="v">{{ $details['date'] ?? date('Y-m-d') }}</span></div>
                <div class="pos-compact-kv"><span class="k">{{ __('Document') }}</span><span class="v">{{ $details['pos_id'] ?? '-' }}</span></div>
                <div class="pos-compact-kv"><span class="k">{{ __('Operator') }}</span><span class="v">{{ $operatorName }}</span></div>
                <div class="pos-compact-kv"><span class="k">{{ __('Branch') }}</span><span class="v">{{ $details['branch']['name'] ?? ($details['warehouse']['name'] ?? '-') }}</span></div>
                @if(!empty($details['table_name']))
                    <div class="pos-compact-kv"><span class="k">{{ __('pos.table') }}</span><span class="v">{{ $details['table_name'] }}</span></div>
                @endif
                <div class="pos-compact-kv"><span class="k">{{ __('Copy') }}</span><span class="v">{{ $segundaVia ? __('Copy') : __('Original') }}</span></div>
            </div>
        </div>

        @if(!$isBalancePayment)
            <table class="pos-compact-table pos-mz-table">
                <thead>
                <tr>
                    <th style="width:14%;">{{ __('SKU') }}</th>
                    <th style="width:42%;text-align:left;">{{ __('Description') }}</th>
                    <th class="num" style="width:10%;">{{ __('Qty') }}</th>
                    <th class="num" style="width:12%;">{{ __('VAT') }}</th>
                    <th class="num" style="width:22%;">{{ __('Total') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($sales['data'] ?? [] as $value)
                    <tr>
                        <td>{{ $value['sku'] ?? '-' }}</td>
                        <td>
                            {{ $value['name'] ?? '' }}
                            @if(!empty($value['description']))<div class="small item-note"><strong>{{ __('Note') }}:</strong> {{ $value['description'] }}</div>@endif
                        </td>
                        <td class="num">{{ $value['quantity'] ?? '' }}</td>
                        <td class="num">{{ \Illuminate\Support\Str::endsWith((string) ($value['tax'] ?? ''), '%') ? ($value['tax'] ?: '0%') : (($value['tax'] ?? '0') . '%') }}</td>
                        <td class="num">{{ $value['subtotal'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div style="max-width:260px;margin-left:auto;margin-top:8px;">
                <div class="pos-compact-kv"><span class="k">{{ __('Sub Total') }}</span><span class="v">{{ $sales['sub_total'] ?? '' }}</span></div>
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
            <div class="pos-compact-section" style="margin-top:8px;border-top:1px solid #ddd;padding-top:6px;">
                @foreach($posPayments as $pmt)
                    <div class="pos-compact-kv"><span class="k">{{ __('Payment Method') }}</span><span class="v">{{ \App\Models\PosPayment::labelForMethod($pmt->payment_method ?? '') }}</span></div>
                    <div class="pos-compact-kv"><span class="k">{{ __('Amount Paid') }}</span><span class="v">{{ $fmtMoney($pmt->amount ?? 0) }}</span></div>
                @endforeach
                @if(!empty($paymentNotes))
                    <div class="pos-compact-kv"><span class="k">{{ __('Notes') }}</span><span class="v">{{ $paymentNotes }}</span></div>
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
                <table class="pos-compact-table pos-mz-table">
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
