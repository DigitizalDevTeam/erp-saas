{{-- UTHARA Mozambique Factura A4/A5 — from client sample HTML --}}
@php
    $paperSize = $paperSize ?? 'a4';
    $isA4 = $paperSize === 'a4';
    $pageW = $isA4 ? '210mm' : '148mm';
    $pageH = $isA4 ? '297mm' : '210mm';
@endphp
<!DOCTYPE html>
<html lang="pt-MZ">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $docTitle }} {{ $docNumber }}</title>
    <style>
        :root{--green:#087f4f;--dark:#13231c;--soft:#eef7f2;--line:#cfd9d4;--muted:#637069}
        *{box-sizing:border-box}
        html,body{margin:0;background:#e9eceb;color:var(--dark);font-family:Arial,Helvetica,sans-serif}
        .page{width:{{ $pageW }};min-height:{{ $pageH }};margin:12mm auto;background:#fff;padding:{{ $isA4 ? '15mm 16mm 18mm' : '8mm' }};position:relative;box-shadow:0 3px 18px #b9bfbc;font-size:{{ $isA4 ? '10pt' : '8pt' }}}
        .top{border:1px solid var(--line);border-radius:3mm;overflow:hidden}
        .top-main{display:grid;grid-template-columns:{{ $isA4 ? '1.15fr .85fr' : '1fr .72fr' }};align-items:stretch}
        .identity{padding:{{ $isA4 ? '7mm' : '4.5mm' }}}
        .doc{background:var(--dark);color:#fff;padding:{{ $isA4 ? '7mm' : '4.5mm' }};text-align:right;min-height:{{ $isA4 ? '38mm' : '31mm' }}}
        .top-meta{display:grid;grid-template-columns:{{ $isA4 ? 'repeat(4,1fr)' : 'repeat(2,1fr)' }};background:var(--soft);border-top:1px solid var(--line)}
        .meta-cell{padding:{{ $isA4 ? '3mm 4mm' : '2mm 3mm' }};border-right:1px solid var(--line)}
        .meta-cell:last-child{border-right:0}
        .meta-label{font-size:7pt;text-transform:uppercase;color:var(--muted);letter-spacing:.5px}
        .meta-value{font-size:{{ $isA4 ? '9pt' : '8pt' }};font-weight:700;margin-top:.7mm}
        .logo{max-width:58mm;max-height:22mm;object-fit:contain;margin-bottom:3mm;display:block}
        .brand-fallback{font-size:{{ $isA4 ? '25pt' : '19pt' }};font-weight:800;letter-spacing:2px;color:var(--green)}
        .company{line-height:1.5;font-size:{{ $isA4 ? '10pt' : '7.2pt' }}}
        .company strong{font-size:{{ $isA4 ? '11pt' : '9pt' }}}
        .doc h1{margin:0;color:#fff;font-size:{{ $isA4 ? '23pt' : '16pt' }};text-transform:uppercase;letter-spacing:.5px}
        .doc-number{font-size:{{ $isA4 ? '12pt' : '9pt' }};font-weight:700;margin:{{ $isA4 ? '3mm' : '2mm' }} 0;color:#fff}
        .copy{display:inline-block;border:1px solid #7fa895;padding:1.2mm 3mm;border-radius:10mm;font-size:{{ $isA4 ? '8pt' : '6.5pt' }};font-weight:700;color:#fff}
        .status-unpaid{color:#9b2c2c;font-weight:800}
        .info-grid{display:grid;grid-template-columns:{{ $isA4 ? '1.15fr .85fr' : '1fr 1fr' }};gap:{{ $isA4 ? '8mm' : '4mm' }};margin:{{ $isA4 ? '8mm' : '4mm' }} 0}
        .card{border:1px solid var(--line);border-radius:2mm;padding:{{ $isA4 ? '5mm' : '3mm' }};min-height:{{ $isA4 ? '34mm' : '29mm' }}}
        .card-title{font-size:{{ $isA4 ? '8pt' : '7pt' }};color:var(--green);text-transform:uppercase;font-weight:700;letter-spacing:.7px;margin-bottom:3mm}
        .line{display:flex;justify-content:space-between;gap:5mm;margin:1.2mm 0}
        .line span:first-child{color:var(--muted)}
        .line span:last-child{text-align:right;font-weight:600;max-width:70%;overflow-wrap:anywhere}
        table{width:100%;border-collapse:collapse}
        .items thead{background:var(--green);color:#fff}
        .items th{padding:{{ $isA4 ? '3mm 2mm' : '2mm 1mm' }};text-align:left;font-size:{{ $isA4 ? '8pt' : '6.5pt' }};text-transform:uppercase;color:#fff}
        .items td{padding:{{ $isA4 ? '3mm 2mm' : '2mm 1mm' }};border-bottom:1px solid var(--line);vertical-align:top;font-size:{{ $isA4 ? '10pt' : '7pt' }}}
        .items tbody tr:nth-child(even){background:#fafcfb}
        .items .num{text-align:right}.items .center{text-align:center}.items .description{width:59%}
        .product-name{font-weight:700}.detail{display:block;color:#435149;font-size:{{ $isA4 ? '8.5pt' : '6.8pt' }};line-height:1.45;margin-top:1.5mm;overflow-wrap:anywhere}
        .summary{display:grid;grid-template-columns:{{ $isA4 ? '1fr 70mm' : '1fr 48mm' }};gap:{{ $isA4 ? '12mm' : '5mm' }};margin-top:{{ $isA4 ? '7mm' : '4mm' }}}
        .tax-box{border:1px solid var(--line);border-radius:2mm;overflow:hidden}
        .tax-box h2{font-size:{{ $isA4 ? '9pt' : '7pt' }};margin:0;padding:3mm;background:var(--soft);color:var(--green);text-transform:uppercase}
        .tax-box th,.tax-box td{padding:2.2mm 3mm;border-bottom:1px solid var(--line);font-size:{{ $isA4 ? '8.5pt' : '6.5pt' }};text-align:right}
        .tax-box th:first-child,.tax-box td:first-child{text-align:left}
        .totals{font-size:{{ $isA4 ? '10pt' : '7.5pt' }}}
        .totals .line{padding:1.4mm 0;margin:0}
        .grand{background:var(--green);color:#fff!important;padding:3.5mm!important;margin-top:2mm!important;font-size:{{ $isA4 ? '15pt' : '10pt' }};font-weight:800}
        .grand span{color:#fff!important}
        .payment{margin-top:7mm;padding:4mm 5mm;background:var(--soft);border-left:3px solid var(--green);display:grid;grid-template-columns:{{ $isA4 ? 'repeat(3,1fr)' : '1fr 1fr' }};gap:5mm}
        .label{font-size:7.5pt;color:var(--muted);text-transform:uppercase;margin-bottom:1mm}
        .value{font-weight:700}
        .banks{margin-top:5mm;border:1px solid var(--line);border-radius:2mm;padding:3mm 4mm}
        .banks h3{margin:0 0 2mm;font-size:8pt;color:var(--green);text-transform:uppercase}
        .banks .bank-row{font-size:8pt;margin:1mm 0;display:flex;justify-content:space-between;gap:4mm}
        .bottom{margin-top:8mm;border-top:1px solid var(--line);padding-top:5mm;display:grid;grid-template-columns:{{ $isA4 ? '28mm 1fr 50mm' : '20mm 1fr 36mm' }};gap:6mm;align-items:center}
        .qr-box{width:{{ $isA4 ? '26mm' : '19mm' }};height:{{ $isA4 ? '26mm' : '19mm' }}}
        .qr-box svg{width:100%!important;height:100%!important;display:block}
        .qr-placeholder{width:100%;height:100%;border:2px solid #111;display:grid;place-items:center;font-size:7pt;font-weight:700}
        .validation{font-size:{{ $isA4 ? '8pt' : '6.5pt' }};line-height:1.45}
        .validation strong{color:var(--green)}
        .powered{text-align:right;font-size:{{ $isA4 ? '7.5pt' : '6pt' }};color:var(--muted)}
        .powered strong{display:block;color:var(--green);font-size:{{ $isA4 ? '9pt' : '7pt' }}}
        .notes{font-size:8pt;color:var(--muted);margin-top:4mm}
        .processed{margin-top:6mm;text-align:center;font-size:8pt;color:var(--muted);border-top:1px dashed var(--line);padding-top:3mm}
        .no-print{width:{{ $pageW }};margin:8px auto;font:13px Arial;color:#333;display:flex;gap:8px;align-items:center;justify-content:flex-end}
        @page{size:{{ $isA4 ? 'A4' : 'A5' }} portrait;margin:0}
        @media print{
            html,body{background:#fff}
            .page{margin:0;box-shadow:none;width:{{ $pageW }};min-height:{{ $pageH }}}
            .no-print{display:none!important}
            .doc,.items thead,.grand{-webkit-print-color-adjust:exact;print-color-adjust:exact}
        }
    </style>
</head>
<body>
<aside class="no-print">
    <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    <span>{{ $isA4 ? __('A4') : __('A5') }} · {{ __('Scale 100%, no browser headers/footers') }}</span>
</aside>
<main class="page">
    <header class="top">
        <div class="top-main">
            <section class="identity">
                @if(!empty($companyLogo))
                    <img class="logo" src="{{ $companyLogo }}" alt="Logo" onerror="this.style.display='none';var f=this.nextElementSibling;if(f)f.style.display='block'">
                    <div class="brand-fallback" style="display:none">{{ $companyBrand }}</div>
                @else
                    <div class="brand-fallback">{{ $companyBrand }}</div>
                @endif
                <div class="company">
                    <strong>{{ $companyName }}</strong><br>
                    {{ $companyAddress }}<br>
                    {{ __('NUIT') }}: {{ $companyNuit !== '' ? $companyNuit : '—' }}
                    @if($companyPhone !== '') · {{ __('Tel.') }}: {{ $companyPhone }}@endif
                    <br>
                    @if($companyEmail !== ''){{ $companyEmail }} · @endif{{ $companyWebsite }}
                </div>
            </section>
            <section class="doc">
                <h1>{{ $docTitle }}</h1>
                <div class="doc-number">{{ __('No.') }} {{ $docNumber }}</div>
                <div class="copy">{{ $copyLabel }}</div>
            </section>
        </div>
        <div class="top-meta">
            <div class="meta-cell"><div class="meta-label">{{ __('Issue Date') }}</div><div class="meta-value">{{ $docDate }}@if(!empty($docTime)) · {{ $docTime }}@endif</div></div>
            <div class="meta-cell"><div class="meta-label">{{ __('Series') }}</div><div class="meta-value">{{ $docSeries !== '' ? $docSeries : '—' }}</div></div>
            <div class="meta-cell"><div class="meta-label">{{ __('Due date') }}</div><div class="meta-value">{{ $dueDate !== '' ? $dueDate : '—' }}</div></div>
            <div class="meta-cell"><div class="meta-label">{{ __('Currency') }}</div><div class="meta-value">{{ $currency }}</div></div>
        </div>
    </header>

    <section class="info-grid">
        <div class="card">
            <div class="card-title">{{ __('Bill To') }}</div>
            <div class="line"><span>{{ __('Customer') }}</span><span>{{ $partyName }}</span></div>
            <div class="line"><span>{{ __('NUIT') }}</span><span>{{ $partyNuit !== '' ? $partyNuit : '—' }}</span></div>
            <div class="line"><span>{{ __('Address') }}</span><span>{{ $partyAddress !== '' ? $partyAddress : '—' }}</span></div>
            <div class="line"><span>{{ __('Contact') }}</span><span>{{ $partyContact !== '' ? $partyContact : '—' }}</span></div>
        </div>
        <div class="card">
            <div class="card-title">{{ __('Document details') }}</div>
            <div class="line"><span>{{ __('Reference') }}</span><span>{{ $refNumber !== '' ? $refNumber : '—' }}</span></div>
            <div class="line"><span>{{ __('Order / Contract') }}</span><span>{{ $orderNumber !== '' ? $orderNumber : '—' }}</span></div>
            @if($statusLabel !== '')
                <div class="line"><span>{{ __('Status') }}</span><span class="{{ $statusLabel === __('Unpaid') ? 'status-unpaid' : '' }}">{{ $statusLabel }}</span></div>
            @endif
        </div>
    </section>

    <table class="items" aria-label="{{ __('Items') }}">
        <thead>
            <tr>
                <th>#</th>
                <th class="description">{{ __('Product and description') }}</th>
                <th class="center">{{ __('Qty') }}</th>
                <th class="num">{{ __('Unit Price') }}</th>
                <th class="num">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($lines as $line)
            <tr>
                <td>{{ $line['index'] }}</td>
                <td>
                    <span class="product-name">{{ $line['name'] }}</span>
                    @if(!empty($line['description']))
                        <span class="detail">{{ $line['description'] }}</span>
                    @endif
                </td>
                <td class="center">{{ $line['quantity'] }}</td>
                <td class="num">{{ $line['price'] }}</td>
                <td class="num"><strong>{{ $line['total'] }}</strong></td>
            </tr>
        @empty
            <tr><td colspan="5" class="center">—</td></tr>
        @endforelse
        </tbody>
    </table>

    <section class="summary">
        <div>
            <div class="tax-box">
                <h2>{{ __('Tax summary') }}</h2>
                <table>
                    <thead><tr><th>{{ __('Rate') }}</th><th>{{ __('Net Amount') }}</th><th>{{ __('Tax') }}</th></tr></thead>
                    <tbody>
                    @forelse($vatBreakdown as $row)
                        <tr>
                            <td>IVA {{ number_format((float)($row['rate'] ?? 0), 0) }}%</td>
                            <td>{{ ($fmt)($row['net'] ?? 0) }}</td>
                            <td>{{ ($fmt)($row['vat'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">—</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="notes">
                @if(!empty($exemptionReason))
                    <strong>{{ __('Exemption reason') }}:</strong> {{ $exemptionReason }}<br>
                @endif
                @if(!empty($notes))
                    <strong>{{ __('Notes') }}:</strong> {{ $notes }}
                @endif
            </div>
        </div>
        <div class="totals">
            <div class="line"><span>{{ __('Sub Total') }}</span><span>{{ $subTotal }}</span></div>
            <div class="line"><span>{{ __('Discount') }}</span><span>{{ $discountTotal }}</span></div>
            <div class="line"><span>{{ __('VAT') }} / IVA</span><span>{{ $taxTotal }}</span></div>
            <div class="line grand"><span>{{ __('TOTAL') }}</span><span>{{ $grandTotal }}</span></div>
            <div class="line"><span>{{ __('Paid') }}</span><span>{{ $paidTotal }}</span></div>
            <div class="line"><span>{{ __('Pending') }}</span><span>{{ $pendingTotal }}</span></div>
        </div>
    </section>

    <section class="payment">
        <div><div class="label">{{ __('Payment Method') }}</div><div class="value">{{ $paymentMethod }}</div></div>
        <div><div class="label">{{ __('Reference') }}</div><div class="value">{{ $paymentRef }}</div></div>
        <div><div class="label">{{ __('Operator') }}</div><div class="value">{{ $operatorName }}</div></div>
    </section>

    @if(isset($banks) && count($banks))
        <section class="banks">
            <h3>{{ __('Bank details') }}</h3>
            @foreach($banks as $bank)
                <div class="bank-row">
                    <span>{{ $bank->bank_name ?? $bank->holder_name ?? '—' }}</span>
                    <span>
                        {{ $bank->account_number ?? '' }}
                        @if(!empty($bank->bank_address)) · {{ $bank->bank_address }}@endif
                        @if(!empty($bank->contact_number)) · {{ $bank->contact_number }}@endif
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    <footer class="bottom">
        <div class="qr-box">
            @if(!empty($qrSvg))
                {!! $qrSvg !!}
            @elseif(!empty($qrHtml))
                {!! $qrHtml !!}
            @else
                <div class="qr-placeholder"><span>QR</span></div>
            @endif
        </div>
        <div class="powered">
            <strong>ERP SaaS UTHARA</strong>
            {{ $companyWebsite }}<br>
            @if($companyEmail !== ''){{ $companyEmail }}<br>@endif
        </div>
    </footer>

    <div class="processed">{{ __('pos.document_processed_by_computer') }}</div>
</main>
</body>
</html>
