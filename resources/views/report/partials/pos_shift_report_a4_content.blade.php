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
    $companyEmail = $settings['company_email'] ?? '';
    $fmt = fn ($v) => \Auth::user()->priceFormat((float) $v);
    $startDate = \Carbon\Carbon::parse($filter['start_date']);
    $endDate = \Carbon\Carbon::parse($filter['end_date']);
    $totalSalesForShare = (float) ($summary['sales_total'] ?? 0);
@endphp
<div class="shift-a4-page">
    <div class="shift-a4-center">
        <div class="shift-a4-company">{{ $companyName }}</div>
        @if(!empty($vatNumber))<div class="shift-a4-small">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
        @if(!empty($companyAddress))<div class="shift-a4-small">{{ $companyAddress }}</div>@endif
        @if(!empty($companyTelephone) || !empty($companyEmail))<div class="shift-a4-small">@if(!empty($companyTelephone))Tel: {{ $companyTelephone }}@endif @if(!empty($companyEmail)) · {{ $companyEmail }}@endif</div>@endif
    </div>

    <div class="shift-a4-title">{{ __('pos.shift_report') }}</div>
    <div class="shift-a4-subtitle">{{ $startDate->format('d/m/Y') }} {{ __('To') }} {{ $endDate->format('d/m/Y') }}</div>

    <hr>

    <table class="shift-a4-meta">
        <tr>
            <td class="shift-a4-key">{{ __('Start Date') }}:</td><td>{{ $startDate->format('l, d M Y') }}</td>
            <td class="shift-a4-key">{{ __('End Date') }}:</td><td>{{ $endDate->format('l, d M Y') }}</td>
        </tr>
        <tr>
            <td class="shift-a4-key">{{ __('Shifts') }}:</td><td>{{ $summary['shift_count'] ?? count($printShiftRows) }}</td>
            <td class="shift-a4-key">{{ __('Printed') }}:</td><td>{{ date('d/m/Y H:i:s') }}</td>
        </tr>
    </table>

    <table class="shift-a4-meta">
        <tr>
            <td class="shift-a4-key"><strong>{{ __('Sales Total') }}:</strong></td><td><strong>{{ $fmt($summary['sales_total'] ?? 0) }}</strong></td>
            <td class="shift-a4-key">{{ __('pos.payment_cash') }}:</td><td>{{ $fmt($summary['cash_total'] ?? 0) }}</td>
        </tr>
        <tr>
            <td class="shift-a4-key">{{ __('Card') }}:</td><td>{{ $fmt($summary['card_total'] ?? 0) }}</td>
            <td class="shift-a4-key">{{ __('pos.payment_credit') }}:</td><td>{{ $fmt($summary['credit_total'] ?? 0) }}</td>
        </tr>
        <tr>
            <td class="shift-a4-key">{{ __('Cash drops') }}:</td><td>{{ $fmt($summary['cash_drops_total'] ?? 0) }}</td>
            <td class="shift-a4-key">{{ __('Difference') }}:</td><td>{{ $fmt($summary['difference_total'] ?? 0) }}</td>
        </tr>
    </table>

    <h2 class="shift-a4-section">{{ __('Shifts') }}</h2>
    <table class="shift-a4-data">
        <colgroup><col style="width:6%"><col style="width:17%"><col style="width:19%"><col style="width:8%"><col style="width:11%"><col style="width:9%"><col style="width:8%"><col style="width:7%"><col style="width:8%"><col style="width:7%"></colgroup>
        <thead>
            <tr>
                <th>{{ __('Shift') }}</th>
                <th>{{ __('Branch') }} / {{ __('pos.shift_user') }}</th>
                <th>{{ __('Opened At') }} / {{ __('Closed At') }}</th>
                <th class="shift-a4-num">{{ __('Opening Balance') }}</th>
                <th class="shift-a4-num">{{ __('Sales Total') }}</th>
                <th class="shift-a4-num">{{ __('Cash') }}</th>
                <th class="shift-a4-num">{{ __('Card') }}</th>
                <th class="shift-a4-num">{{ __('Credit') }}</th>
                <th class="shift-a4-num">{{ __('Expected cash') }}</th>
                <th class="shift-a4-num">{{ __('Difference') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($printShiftRows as $row)
            @php $shift = $row['shift']; $m = $row['metrics']; @endphp
            <tr>
                <td>#{{ $shift->id }}<br>{{ $shift->status === 'open' ? __('Open') : __('Closed') }}</td>
                <td>{{ $shift->branch ? $shift->branch->name : '—' }}<br>{{ $shift->user ? $shift->user->name : '—' }}</td>
                <td>{{ $shift->opened_at ? $shift->opened_at->format('Y-m-d H:i') : '—' }}<br>{{ $shift->closed_at ? $shift->closed_at->format('Y-m-d H:i') : '—' }}</td>
                <td class="shift-a4-num">{{ $fmt($shift->opening_balance ?? 0) }}</td>
                <td class="shift-a4-num">{{ $fmt($m['salesTotal']) }}</td>
                <td class="shift-a4-num">{{ $fmt($m['cashTotal']) }}</td>
                <td class="shift-a4-num">{{ $fmt($m['cardTotal']) }}</td>
                <td class="shift-a4-num">{{ $fmt($m['creditTotal']) }}</td>
                <td class="shift-a4-num">{{ $fmt($m['expectedCash']) }}</td>
                <td class="shift-a4-num">{{ $shift->status === 'closed' ? $fmt($shift->difference ?? 0) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="shift-a4-center-cell">{{ __('No data in this period') }}</td></tr>
        @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">{{ __('Total') }}</td>
                <td class="shift-a4-num">{{ $fmt($summary['sales_total'] ?? 0) }}</td>
                <td class="shift-a4-num">{{ $fmt($summary['cash_total'] ?? 0) }}</td>
                <td class="shift-a4-num">{{ $fmt($summary['card_total'] ?? 0) }}</td>
                <td class="shift-a4-num">{{ $fmt($summary['credit_total'] ?? 0) }}</td>
                <td class="shift-a4-num"></td>
                <td class="shift-a4-num">{{ $fmt($summary['difference_total'] ?? 0) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2 class="shift-a4-section">{{ __('Payment Summary') }}</h2>
    <table class="shift-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('Payment Method') }}</th><th class="shift-a4-num">{{ __('Count') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th class="shift-a4-num">{{ __('Share') }}</th></tr></thead>
        <tbody>
        @forelse($aggregates['paymentsByMethod'] ?? [] as $pm)
            <tr>
                <td>{{ $pm['name'] }}</td>
                <td class="shift-a4-num">{{ $pm['count'] }}</td>
                <td class="shift-a4-num">{{ $fmt($pm['amount']) }}</td>
                <td class="shift-a4-num">{{ $totalSalesForShare > 0 ? number_format($pm['amount'] / $totalSalesForShare * 100, 1) : '0.0' }}%</td>
            </tr>
        @empty
            <tr><td colspan="4" class="shift-a4-center-cell">—</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="shift-a4-num"></td><td class="shift-a4-num">{{ $fmt($summary['sales_total'] ?? 0) }}</td><td class="shift-a4-num">100%</td></tr></tfoot>
    </table>

    @if(!empty($aggregates['products']))
    <h2 class="shift-a4-section">{{ __('Products sold (Qty Summary)') }}</h2>
    <table class="shift-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <thead><tr><th>{{ __('Product') }}</th><th class="shift-a4-num">{{ __('Qty') }}</th><th class="shift-a4-num" colspan="2">{{ __('Amount') }}</th></tr></thead>
        <tbody>
        @foreach($aggregates['products'] as $p)
            <tr><td>{{ $p['name'] }}</td><td class="shift-a4-num">{{ number_format($p['quantity'], 2) }}</td><td class="shift-a4-num" colspan="2">{{ $fmt($p['amount']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif

    @if(($aggregates['voidedCount'] ?? 0) > 0)
    <h2 class="shift-a4-section">{{ __('pos.void_summary') }} ({{ $aggregates['voidedCount'] }})</h2>
    <table class="shift-a4-data">
        <thead><tr><th>{{ __('pos.pos_id') }}</th><th>{{ __('Product') }}</th><th class="shift-a4-num">{{ __('Qty') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Voided at') }}</th><th>{{ __('By') }}</th></tr></thead>
        <tbody>
        @foreach($aggregates['voidedLines'] as $line)
            <tr>
                <td>{{ $line->pos ? \Auth::user()->posNumberFormat($line->pos->pos_id) : '—' }}</td>
                <td>{{ $line->product ? $line->product->name : '—' }}</td>
                <td class="shift-a4-num">{{ $line->quantity }}</td>
                <td class="shift-a4-num">{{ $fmt($line->price * $line->quantity) }}</td>
                <td>{{ $line->void_reason ?: '—' }}</td>
                <td>{{ $line->voided_at ? $line->voided_at->format('Y-m-d H:i') : '—' }}</td>
                <td>{{ $line->voidedByUser ? $line->voidedByUser->name : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <h2 class="shift-a4-section">{{ __('Cash movements') }}</h2>
    <h3 class="shift-a4-section">{{ __('Cash drops (detail)') }}</h3>
    <table class="shift-a4-data">
        <thead><tr><th>{{ __('Shift') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Date') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th>{{ __('Reason') }}</th><th>{{ __('By') }}</th></tr></thead>
        <tbody>
        @forelse($aggregates['cashDrops'] ?? [] as $drop)
            <tr>
                <td>#{{ $drop->shift_id }}</td>
                <td>{{ $drop->shift && $drop->shift->branch ? $drop->shift->branch->name : '—' }}</td>
                <td>{{ $drop->created_at ? $drop->created_at->format('Y-m-d H:i') : '—' }}</td>
                <td class="shift-a4-num">{{ $fmt($drop->amount) }}</td>
                <td>{{ $drop->reason ?: '—' }}</td>
                <td>{{ $drop->user ? $drop->user->name : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="shift-a4-center-cell">{{ __('No cash drops recorded for this shift.') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    <h3 class="shift-a4-section">{{ __('Cash in (top-ups)') }}</h3>
    <table class="shift-a4-data">
        <thead><tr><th>{{ __('Shift') }}</th><th>{{ __('Date') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th>{{ __('Reason') }}</th><th>{{ __('By') }}</th></tr></thead>
        <tbody>
        @forelse($aggregates['cashIns'] ?? [] as $in)
            <tr>
                <td>#{{ $in->shift_id }}</td>
                <td>{{ $in->created_at ? $in->created_at->format('Y-m-d H:i') : '—' }}</td>
                <td class="shift-a4-num">{{ $fmt($in->amount) }}</td>
                <td>{{ $in->reason ?: '—' }}</td>
                <td>{{ $in->user ? $in->user->name : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="shift-a4-center-cell">—</td></tr>
        @endforelse
        </tbody>
    </table>

    <h3 class="shift-a4-section">{{ __('Cash out (expenses)') }}</h3>
    <table class="shift-a4-data">
        <thead><tr><th>{{ __('Shift') }}</th><th>{{ __('Date') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th>{{ __('Description') }}</th></tr></thead>
        <tbody>
        @forelse($aggregates['expenses'] ?? [] as $exp)
            <tr>
                <td>#{{ $exp->pos_shift_id }}</td>
                <td>{{ $exp->date }}</td>
                <td class="shift-a4-num">{{ $fmt($exp->amount) }}</td>
                <td>{{ $exp->description ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="shift-a4-center-cell">—</td></tr>
        @endforelse
        </tbody>
    </table>

    <table class="shift-a4-sign">
        <tr>
            <td><div class="shift-a4-sign-line">{{ __('Cashier') }}</div></td>
            <td><div class="shift-a4-sign-line">{{ __('Manager Signature') }}</div></td>
            <td><div class="shift-a4-sign-line">{{ __('Date / Time') }}</div></td>
        </tr>
    </table>

    <div class="shift-a4-foot">{{ $companyName }} · {{ __('pos.shift_report') }} · {{ $startDate->format('d/m/Y') }} {{ __('To') }} {{ $endDate->format('d/m/Y') }}</div>
</div>
