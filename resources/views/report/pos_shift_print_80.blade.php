<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('pos.shift_report') }} — {{ $filter['start_date'] }} {{ __('To') }} {{ $filter['end_date'] }}</title>
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
    $startDate = \Carbon\Carbon::parse($filter['start_date']);
    $endDate = \Carbon\Carbon::parse($filter['end_date']);
@endphp
<div class="slip">
    <div class="c b big">{{ $companyName }}</div>
    @if(!empty($vatNumber))<div class="c">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
    @if(!empty($companyAddress))<div class="c">{{ $companyAddress }}</div>@endif
    @if(!empty($companyTelephone))<div class="c">Tel: {{ $companyTelephone }}</div>@endif
    <div class="solid"></div>
    <div class="c b big">{{ __('POS SHIFT REPORT') }}</div>
    <div class="c">{{ $startDate->format('d/m/Y') }} → {{ $endDate->format('d/m/Y') }}</div>
    <div class="line"></div>
    <div class="row"><span>{{ __('Printed') }}:</span><span>{{ date('d/m/Y H:i:s') }}</span></div>
    <div class="row"><span>{{ __('Shifts') }}:</span><span>{{ $summary['shift_count'] ?? count($printShiftRows) }}</span></div>

    <div class="solid"></div>
    <div class="row tot"><span>{{ __('TOTAL SALES') }}</span><span>{{ $fmt($summary['sales_total'] ?? 0) }}</span></div>
    <div class="row"><span>{{ __('pos.payment_cash') }}:</span><span>{{ $fmt($summary['cash_total'] ?? 0) }}</span></div>
    <div class="row"><span>{{ __('Card') }}:</span><span>{{ $fmt($summary['card_total'] ?? 0) }}</span></div>
    <div class="row"><span>{{ __('pos.payment_credit') }}:</span><span>{{ $fmt($summary['credit_total'] ?? 0) }}</span></div>
    <div class="row"><span>{{ __('Cash drops') }}:</span><span>{{ $fmt($summary['cash_drops_total'] ?? 0) }}</span></div>
    <div class="row"><span>{{ __('Difference') }}:</span><span>{{ $fmt($summary['difference_total'] ?? 0) }}</span></div>

    <h3>{{ __('SALES BY PAYMENT METHOD') }}</h3>
    <table>
        <tr><th>{{ __('PAYMENT METHOD') }}</th><th class="r">{{ __('NO.') }}</th><th class="r">{{ __('AMOUNT') }}</th></tr>
        @forelse($aggregates['paymentsByMethod'] ?? [] as $pm)
            <tr><td>{{ $pm['name'] }}</td><td class="r">{{ $pm['count'] }}</td><td class="r">{{ $fmt($pm['amount']) }}</td></tr>
        @empty
            <tr><td colspan="3" class="c">—</td></tr>
        @endforelse
    </table>

    <h3>{{ __('SHIFTS') }}</h3>
    @forelse($printShiftRows as $row)
        @php $shift = $row['shift']; $m = $row['metrics']; @endphp
        <div class="line"></div>
        <div class="row b"><span>#{{ $shift->id }} {{ $shift->branch ? $shift->branch->name : '—' }}</span><span>{{ $shift->status === 'open' ? __('Open') : __('Closed') }}</span></div>
        <div class="row"><span>{{ __('pos.shift_user') }}:</span><span>{{ $shift->user ? $shift->user->name : '—' }}</span></div>
        <div class="row"><span>{{ __('Opened At') }}:</span><span>{{ $shift->opened_at ? $shift->opened_at->format('d/m H:i') : '—' }}</span></div>
        <div class="row"><span>{{ __('Closed At') }}:</span><span>{{ $shift->closed_at ? $shift->closed_at->format('d/m H:i') : '—' }}</span></div>
        <div class="row"><span>{{ __('Sales Total') }}:</span><span>{{ $fmt($m['salesTotal']) }}</span></div>
        <div class="row"><span>{{ __('Cash') }}:</span><span>{{ $fmt($m['cashTotal']) }}</span></div>
        <div class="row"><span>{{ __('Card') }} / {{ __('Credit') }}:</span><span>{{ $fmt($m['cardTotal']) }} / {{ $fmt($m['creditTotal']) }}</span></div>
        <div class="row"><span>{{ __('Expected cash') }}:</span><span>{{ $fmt($m['expectedCash']) }}</span></div>
        <div class="row"><span>{{ __('Difference') }}:</span><span>{{ $shift->status === 'closed' ? $fmt($shift->difference ?? 0) : '—' }}</span></div>
    @empty
        <div class="c">{{ __('No data in this period') }}</div>
    @endforelse

    @if(!empty($aggregates['products']))
    <h3>{{ __('PRODUCTS SOLD') }}</h3>
    <table>
        <tr><th>{{ __('ITEM') }}</th><th class="r">{{ __('Qty') }}</th><th class="r">{{ __('AMOUNT') }}</th></tr>
        @foreach($aggregates['products'] as $p)
            <tr><td>{{ $p['name'] }}</td><td class="r">{{ number_format($p['quantity'], 2) }}</td><td class="r">{{ $fmt($p['amount']) }}</td></tr>
        @endforeach
    </table>
    @endif

    @if(($aggregates['voidedCount'] ?? 0) > 0)
    <div class="line"></div>
    <div class="row"><span>{{ __('pos.void_summary') }}:</span><span>{{ $aggregates['voidedCount'] }}</span></div>
    @endif

    @include('report.partials.pos_shift_min_stock_alert_80')

    <div class="solid"></div>
    <div class="c" style="margin-top:16px;">________________________</div>
    <div class="c">{{ __('Manager Signature') }}</div>
    <div class="c" style="margin-top:10px;">*** {{ __('END OF REPORT') }} ***</div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
