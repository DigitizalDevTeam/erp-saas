<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('Shift Report') }} #{{ $pos_shift->id }}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:"Courier New", monospace; color:#000; }
    .slip { width:72mm; margin:0 auto; padding:8px 2mm; font-size:11.5px; line-height:1.45; }
    .c { text-align:center; } .b { font-weight:700; } .big { font-size:14px; }
    .line { border-top:1px dashed #000; margin:7px 0; }
    .solid { border-top:2px solid #000; margin:7px 0; }
    .row { display:flex; justify-content:space-between; gap:8px; }
    h3 { font-size:12px; text-align:center; letter-spacing:1px; margin:9px 0 5px; text-transform:uppercase; }
    table { width:100%; border-collapse:collapse; font-size:11px; }
    td { padding:1.5px 0; vertical-align:top; }
    td.r, th.r { text-align:right; }
    th { border-bottom:1px solid #000; text-align:left; padding-bottom:2px; font-size:10.5px; }
    .tot { font-size:13px; font-weight:700; }
    @page { size: 80mm auto; margin: 2mm; }
    @media print { .noprint { display:none !important; } }
</style>
</head>
<body>
@php
    $settings = \App\Models\Utility::settingsById(\Auth::user()->creatorId());
    $companyName = $settings['company_name'] ?? config('app.name');
    $vatNumber = $settings['vat_number'] ?? '';
    $companyAddress = trim(implode(', ', array_filter([
        $settings['company_address'] ?? '',
        $settings['company_city'] ?? '',
        $settings['company_state'] ?? '',
        $settings['company_country'] ?? '',
        $settings['company_zipcode'] ?? '',
    ])));
    $companyTelephone = $settings['company_telephone'] ?? '';
    $fmt = fn ($v) => \Auth::user()->priceFormat((float) $v);
    $posFmt = fn ($v) => \Auth::user()->posNumberFormat($v);
@endphp
<div class="slip">
    <div class="c b big">{{ $companyName }}</div>
    @if(!empty($vatNumber))<div class="c">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
    @if(!empty($companyAddress))<div class="c">{{ $companyAddress }}</div>@endif
    @if(!empty($companyTelephone))<div class="c">Tel: {{ $companyTelephone }}</div>@endif
    <div class="solid"></div>
    <div class="c b big">{{ __('SHIFT REPORT') }} #{{ $pos_shift->id }}</div>
    <div class="c">{{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</div>
    <div class="line"></div>
    <div class="row"><span>{{ __('User') }}:</span><span>{{ $pos_shift->user ? $pos_shift->user->name : '-' }}</span></div>
    <div class="row"><span>{{ __('Opened At') }}:</span><span>{{ $pos_shift->opened_at ? $pos_shift->opened_at->format('d/m H:i') : '-' }}</span></div>
    <div class="row"><span>{{ __('Closed At') }}:</span><span>{{ $pos_shift->closed_at ? $pos_shift->closed_at->format('d/m H:i') : '—' }}</span></div>
    <div class="row"><span>{{ __('Status') }}:</span><span>{{ $pos_shift->status === 'open' ? __('Open') : __('Closed') }}</span></div>
    <div class="row"><span>{{ __('Printed') }}:</span><span>{{ date('d/m/Y H:i:s') }}</span></div>

    <div class="solid"></div>
    <div class="row tot"><span>{{ __('TOTAL SALES') }}</span><span>{{ $fmt($salesTotal) }}</span></div>
    <div class="row"><span>{{ __('Transactions') }}:</span><span>{{ $salesCount ?? ($sales->count() ?? 0) }}</span></div>

    <h3>{{ __('CASH RECONCILIATION') }}</h3>
    <div class="row"><span>{{ __('Opening balance') }}</span><span>{{ $fmt($pos_shift->opening_balance ?? 0) }}</span></div>
    <div class="row"><span>{{ __('Cash in (POS sales)') }}</span><span>{{ $fmt($cashTotal ?? 0) }}</span></div>
    @if(!empty($cashInsTotal) && $cashInsTotal > 0)
        <div class="row"><span>{{ __('Cash in (top-ups)') }}</span><span>{{ $fmt($cashInsTotal) }}</span></div>
    @endif
    <div class="row"><span>{{ __('Cash drops (spent / out)') }}</span><span>-{{ $fmt($cashDropsTotal ?? 0) }}</span></div>
    @if(!empty($cashOutExpensesTotal) && $cashOutExpensesTotal > 0)
        <div class="row"><span>{{ __('Cash out (expenses)') }}</span><span>-{{ $fmt($cashOutExpensesTotal) }}</span></div>
    @endif
    <div class="row b"><span>{{ __('Expected cash in drawer') }}</span><span>{{ $fmt($expectedCash ?? 0) }}</span></div>
    @if($pos_shift->closed_at)
        <div class="row"><span>{{ __('Closing balance (counted)') }}</span><span>{{ $fmt($pos_shift->closing_balance ?? 0) }}</span></div>
        <div class="row"><span>{{ __('Difference (over / short)') }}</span><span>{{ $fmt($pos_shift->difference ?? 0) }}</span></div>
    @endif

    <h3>{{ __('PAYMENT SUMMARY') }}</h3>
    <div class="row"><span>{{ __('pos.payment_cash') }}</span><span>{{ $fmt($cashTotal) }}</span></div>
    <div class="row"><span>{{ __('Card / M-Pesa / Bank transfer') }}</span><span>{{ $fmt($cardTotal) }}</span></div>
    <div class="row"><span>{{ __('pos.payment_credit') }}</span><span>{{ $fmt($creditTotal) }}</span></div>
    <div class="row b"><span>{{ __('Total') }}</span><span>{{ $fmt($cashTotal + $cardTotal + $creditTotal) }}</span></div>

    @if(($productQtySummary ?? collect())->isNotEmpty())
    <h3>{{ __('PRODUCTS SOLD') }}</h3>
    <table>
        <tr><th>{{ __('ITEM') }}</th><th class="r">{{ __('Qty') }}</th><th class="r">{{ __('AMOUNT') }}</th></tr>
        @foreach($productQtySummary as $row)
            <tr><td>{{ $row->product ? $row->product->name : __('Unknown product') }}</td><td class="r">{{ (float) $row->qty_total }}</td><td class="r">{{ $fmt((float) $row->inclusive_total) }}</td></tr>
        @endforeach
    </table>
    @endif

    @if($sales->isNotEmpty())
    <h3>{{ __('SALES') }}</h3>
    @foreach($sales as $pos)
        <div class="row"><span>{{ $posFmt($pos->pos_id) }} · {{ $pos->payment_method_label !== '' ? $pos->payment_method_label : '—' }}</span><span>{{ $fmt((float) $pos->posPayments->sum('amount')) }}</span></div>
    @endforeach
    <div class="line"></div>
    <div class="row b"><span>{{ __('Total') }}</span><span>{{ $fmt((float) $sales->sum(fn ($pos) => (float) $pos->posPayments->sum('amount'))) }}</span></div>
    @endif

    @if(isset($voidedLines) && $voidedLines->isNotEmpty())
    <div class="line"></div>
    <div class="row"><span>{{ __('pos.void_summary') }}:</span><span>{{ $voidedLines->count() }}</span></div>
    @endif

    @include('report.partials.pos_shift_min_stock_alert_80')

    <div class="solid"></div>
    <div class="c">{{ __('Cashier') }}: {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</div>
    <div class="c" style="margin-top:16px;">________________________</div>
    <div class="c">{{ __('Manager Signature') }}</div>
    <div class="c" style="margin-top:10px;">*** {{ __('END OF REPORT') }} ***</div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
