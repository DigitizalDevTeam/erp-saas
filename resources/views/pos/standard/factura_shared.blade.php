{{-- Mozambique standard A5 factura — from UTHARA HTML ref. --}}
<style>
.mz-factura { --green:#087f4f; --dark:#13231c; --soft:#eef7f2; --line:#cfd9d4; --muted:#637069; color:var(--dark); font-family:Arial,Helvetica,sans-serif; font-size:8pt; }
.mz-factura.a4 { font-size:10pt; }
.mz-factura .top { border:1px solid var(--line); border-radius:3mm; overflow:hidden; }
.mz-factura .top-main { display:grid; grid-template-columns:1.15fr .85fr; }
.mz-factura .identity { padding:4.5mm; }
.mz-factura .doc { background:var(--dark); color:#fff; padding:4.5mm; text-align:right; min-height:31mm; }
.mz-factura .doc h1 { margin:0; color:#fff; font-size:16pt; text-transform:uppercase; }
.mz-factura.a4 .doc h1 { font-size:22pt; }
.mz-factura .doc-number { font-size:10pt; font-weight:700; margin:2mm 0; color:#fff; }
.mz-factura .copy { display:inline-block; border:1px solid #7fa895; padding:1mm 2mm; border-radius:10mm; font-size:7pt; font-weight:700; color:#fff; }
.mz-factura img.logo { max-width:120px; max-height:56px; width:auto; height:auto; object-fit:contain; margin-bottom:2mm; display:block; }
.mz-factura .company { line-height:1.45; font-size:7.5pt; color:#111; }
.mz-factura .company strong { font-size:10pt; color:#111; }
.mz-factura .top-meta { display:grid; grid-template-columns:repeat(4,1fr); background:var(--soft); border-top:1px solid var(--line); }
.mz-factura .meta-cell { padding:2mm 3mm; border-right:1px solid var(--line); }
.mz-factura .meta-cell:last-child { border-right:0; }
.mz-factura .meta-label { font-size:6.5pt; text-transform:uppercase; color:var(--muted); }
.mz-factura .meta-value { font-size:8.5pt; font-weight:700; margin-top:.5mm; color:#111; }
.mz-factura .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:4mm; margin:4mm 0; }
.mz-factura .card { border:1px solid var(--line); border-radius:2mm; padding:3mm; min-height:28mm; color:#111; }
.mz-factura .card-title { font-size:7.5pt; color:var(--green); text-transform:uppercase; font-weight:700; margin-bottom:2mm; }
.mz-factura .line { display:flex; justify-content:space-between; gap:4mm; margin:1mm 0; color:#111; }
.mz-factura .line span:first-child { color:var(--muted); }
.mz-factura .line span:last-child { text-align:right; font-weight:600; max-width:68%; overflow-wrap:anywhere; }
.mz-factura table.items { width:100%; border-collapse:collapse; color:#111; }
.mz-factura .items thead { background:var(--green); color:#fff; }
.mz-factura .items th { padding:2mm 1.5mm; text-align:left; font-size:7pt; text-transform:uppercase; color:#fff; }
.mz-factura .items td { padding:2mm 1.5mm; border-bottom:1px solid var(--line); vertical-align:top; font-size:7.5pt; color:#111; }
.mz-factura .items .num { text-align:right; }
.mz-factura .items .center { text-align:center; }
.mz-factura .product-name { font-weight:700; color:#111; }
.mz-factura .detail { display:block; color:#435149; font-size:7pt; margin-top:1mm; }
.mz-factura .summary { display:grid; grid-template-columns:1fr 48mm; gap:5mm; margin-top:4mm; }
.mz-factura.a4 .summary { grid-template-columns:1fr 70mm; gap:10mm; }
.mz-factura .tax-box { border:1px solid var(--line); border-radius:2mm; overflow:hidden; }
.mz-factura .tax-box h2 { font-size:8pt; margin:0; padding:2mm; background:var(--soft); color:var(--green); text-transform:uppercase; }
.mz-factura .tax-box table { width:100%; border-collapse:collapse; }
.mz-factura .tax-box th, .mz-factura .tax-box td { padding:1.5mm; border-bottom:1px solid var(--line); font-size:7pt; text-align:right; color:#111; }
.mz-factura .tax-box th:first-child, .mz-factura .tax-box td:first-child { text-align:left; }
.mz-factura .totals .line { padding:.8mm 0; margin:0; font-size:8pt; }
.mz-factura .grand { background:var(--green); color:#fff !important; padding:2.5mm !important; margin-top:2mm !important; font-size:11pt; font-weight:800; }
.mz-factura .grand span { color:#fff !important; }
.mz-factura .payment { margin-top:4mm; padding:3mm; background:var(--soft); border-left:3px solid var(--green); display:grid; grid-template-columns:1fr 1fr 1fr; gap:3mm; }
.mz-factura .label { font-size:6.5pt; color:var(--muted); text-transform:uppercase; }
.mz-factura .value { font-weight:700; color:#111; font-size:8pt; }
.mz-factura .bottom { margin-top:5mm; padding-top:3mm; border-top:1px solid var(--line); display:grid; grid-template-columns:38mm 1fr 40mm; gap:3mm; align-items:center; }
.mz-factura .view-qrcode-receipt-svg, .mz-factura .view-qrcode-receipt { width:36mm; height:36mm; }
.mz-factura .view-qrcode-receipt-svg svg { display:block !important; width:100% !important; height:100% !important; }
.mz-factura .validation { font-size:7pt; line-height:1.4; color:#111; }
.mz-factura .powered { text-align:right; font-size:7pt; color:var(--muted); }
.mz-factura .powered strong { display:block; color:var(--green); font-size:8.5pt; }
.mz-factura .sig-box { border:1px solid #333; min-height:32px; margin-top:2mm; background:#fff; }
@media print {
  .mz-factura .doc, .mz-factura .items thead, .mz-factura .grand { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

@php $isA4 = ($mzFacturaSize ?? 'a5') === 'a4'; @endphp
<div class="mz-factura {{ $isA4 ? 'a4' : 'a5' }} receipt-body">
    <header class="top">
        <div class="top-main">
            <section class="identity">
                <img class="logo" src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'">
                <div class="company">
                    <strong>{{ $companyName }}</strong><br>
                    {{ $companyAddressLine }}<br>
                    {{ __('NUIT') }}: {{ $companyVat !== '' ? $companyVat : '—' }}
                    @if($companyPhone !== '') · {{ __('Phone') }}: {{ $companyPhone }}@endif
                    <br>
                    @if($companyEmail !== ''){{ $companyEmail }} · @endif{{ $companyWebsite }}
                </div>
            </section>
            <section class="doc">
                <h1>{{ $paymentDocumentTitle }}</h1>
                <div class="doc-number">{{ __('No.') }} {{ $docNumber }}</div>
                <div class="copy">{{ $copyLabel }}</div>
            </section>
        </div>
        <div class="top-meta">
            <div class="meta-cell">
                <div class="meta-label">{{ __('Issue Date') }}</div>
                <div class="meta-value">{{ $docDateTime }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">{{ __('Branch') }}</div>
                <div class="meta-value">{{ $branchName }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">{{ __('Due date') }}</div>
                <div class="meta-value">{{ $dueDate ?: '—' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">{{ __('Currency') }}</div>
                <div class="meta-value">{{ $currency }}</div>
            </div>
        </div>
    </header>

    <section class="info-grid">
        <div class="card">
            <div class="card-title">{{ __('Bill To') }}</div>
            <div class="line"><span>{{ __('Customer') }}</span><span>{{ $clientName }}</span></div>
            <div class="line"><span>{{ __('NUIT') }}</span><span>{{ $custVat !== '' ? $custVat : '—' }}</span></div>
            <div class="line"><span>{{ __('Address') }}</span><span>{{ $custAddress !== '' ? $custAddress : '—' }}</span></div>
            <div class="line"><span>{{ __('Contact') }}</span><span>{{ $custPhone !== '' ? $custPhone : '—' }}</span></div>
            <div class="line"><span>{{ __('Email') }}</span><span>{{ $custEmail !== '' ? $custEmail : '—' }}</span></div>
        </div>
        <div class="card">
            <div class="card-title">{{ __('Document details') }}</div>
            <div class="line"><span>{{ __('Reference') }}</span><span>{{ $docNumber }}</span></div>
            <div class="line"><span>{{ __('Operator') }}</span><span>{{ $operatorName }}</span></div>
            <div class="line"><span>{{ __('Payment Method') }}</span><span class="text-capitalize">{{ $paymentMethodLabel }}</span></div>
            @if(!empty($paymentNotes))
                <div class="line"><span>{{ __('Notes') }}</span><span>{{ $paymentNotes }}</span></div>
            @endif
            @if(!empty($details['table_name']))
                <div class="line"><span>{{ __('pos.table') }}</span><span>{{ $details['table_name'] }}</span></div>
            @endif
        </div>
    </section>

    @if(!$isBalancePayment)
    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('Description') }}</th>
                <th class="center">{{ __('Qty') }}</th>
                <th class="num">{{ __('VAT') }}</th>
                <th class="num">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($sales['data'] ?? [] as $i => $value)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    <span class="product-name">{{ $value['name'] ?? '' }}</span>
                    @if(!empty($value['description']))
                    <span class="detail item-note"><strong>{{ __('Note') }}:</strong> {{ $value['description'] }}</span>
                    @endif
                </td>
                <td class="center">{{ $value['quantity'] ?? '' }}</td>
                <td class="num">{{ \Illuminate\Support\Str::endsWith((string) ($value['tax'] ?? ''), '%') ? ($value['tax'] ?: '0%') : (($value['tax'] ?? '0') . '%') }}</td>
                <td class="num"><strong>{{ $value['subtotal'] ?? '' }}</strong></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <section class="summary">
        <div>
            <div class="tax-box">
                <h2>{{ __('Tax summary') }}</h2>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('VAT') }}</th>
                            <th>{{ __('Net Amount') }}</th>
                            <th>{{ __('Tax') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($vatBreakdown as $row)
                        <tr>
                            <td>{{ number_format((float)($row['rate'] ?? 0), 0) }}%</td>
                            <td>{{ $fmt($row['net'] ?? 0) }}</td>
                            <td>{{ $fmt($row['vat'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">—</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="totals">
            <div class="line"><span>{{ __('Sub Total') }}</span><span>{{ $salesSubTotal }}</span></div>
            <div class="line"><span>{{ __('Discount') }}</span><span>{{ $salesDiscount }}</span></div>
            <div class="line"><span>{{ __('VAT') }} / IVA</span><span>{{ $ivaTotalFmt }}</span></div>
            <div class="line grand"><span>{{ __('TOTAL') }}</span><span>{{ $salesTotal }}</span></div>
            @if(!empty($showPaidRow))
            <div class="line"><span>{{ __('Paid') }}</span><span>{{ $paidFmt }}</span></div>
            @endif
            <div class="line"><span>{{ $trocoPendenteLabel }}</span><span>{{ $trocoPendenteValue }}</span></div>
        </div>
    </section>

    <section class="payment">
        <div>
            <div class="label">{{ __('Payment Method') }}</div>
            <div class="value text-capitalize">{{ $paymentMethodLabel }}</div>
        </div>
        @if(!empty($paymentNotes))
        <div>
            <div class="label">{{ __('Notes') }}</div>
            <div class="value">{{ $paymentNotes }}</div>
        </div>
        @endif
        <div>
            <div class="label">{{ __('Reference') }}</div>
            <div class="value">{{ $paymentRefLabel }}</div>
        </div>
        <div>
            <div class="label">{{ __('Operator') }} / {{ __('Branch') }}</div>
            <div class="value">{{ $operatorName }} / {{ $branchName }}</div>
        </div>
    </section>

    @if(!empty($bankAccounts) && count($bankAccounts))
        <section style="margin-top:4mm;border:1px solid var(--line);border-radius:2mm;padding:3mm;color:#111;">
            <div class="card-title" style="margin-bottom:2mm;">{{ __('Bank details') }}</div>
            @foreach($bankAccounts as $bank)
                <div class="line">
                    <span>{{ $bank->bank_name ?? $bank->holder_name ?? '—' }}</span>
                    <span>
                        {{ $bank->account_number ?? '' }}
                        @if(!empty($bank->bank_address)) · {{ $bank->bank_address }}@endif
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    @if($hasCreditPayment)
    <div style="margin-top:4mm;color:#111;">
        <div class="bold" style="font-size:8pt;">{{ __('pos.client_signature_for_credit') }}</div>
        <div class="sig-box"></div>
        <div class="line"><span>{{ __('Name') }}</span><span>_______________________</span></div>
        <div class="line"><span>{{ __('Due Amount') }}</span><span class="bold">{{ $trocoPendenteValue }}</span></div>
    </div>
    @endif

    <footer class="bottom">
        @if(!empty($qrSvg))
            <div class="view-qrcode-receipt view-qrcode-receipt-svg">{!! $qrSvg !!}</div>
        @elseif(!empty($qrHtml))
            <div class="view-qrcode-receipt">{!! $qrHtml !!}</div>
        @else
            <div></div>
        @endif
        <div class="powered">
            <strong>ERP SaaS UTHARA</strong>
            www.uthara.co.mz<br>
            @if($companyPhone !== ''){{ __('Phone') }}: {{ $companyPhone }}<br>@endif
            @if($companyEmail !== ''){{ $companyEmail }}@endif
        </div>
    </footer>
    <div style="margin-top:4mm;text-align:center;font-size:7.5pt;color:var(--muted);border-top:1px dashed var(--line);padding-top:2mm;">
        {{ __('pos.document_processed_by_computer') }}
    </div>
</div>
