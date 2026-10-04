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
    $posFmt = fn ($v) => \Auth::user()->posNumberFormat($v);
@endphp
<div class="shift-a4-page">
    <div class="shift-a4-center">
        <div class="shift-a4-company">{{ $companyName }}</div>
        @if(!empty($vatNumber))<div class="shift-a4-small">{{ __('VAT NR') }}: {{ $vatNumber }}</div>@endif
        @if(!empty($companyAddress))<div class="shift-a4-small">{{ $companyAddress }}</div>@endif
        @if(!empty($companyTelephone) || !empty($companyEmail))<div class="shift-a4-small">@if(!empty($companyTelephone))Tel: {{ $companyTelephone }}@endif @if(!empty($companyEmail)) · {{ $companyEmail }}@endif</div>@endif
    </div>

    <div class="shift-a4-title">{{ __('Shift Report') }} #{{ $pos_shift->id }}</div>
    <div class="shift-a4-subtitle">{{ $pos_shift->branch ? $pos_shift->branch->name : '-' }} · {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</div>

    <hr>

    <table class="shift-a4-meta">
        <tr>
            <td class="shift-a4-key">{{ __('Branch') }}:</td><td>{{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</td>
            <td class="shift-a4-key">{{ __('User') }}:</td><td>{{ $pos_shift->user ? $pos_shift->user->name : '-' }}</td>
        </tr>
        <tr>
            <td class="shift-a4-key">{{ __('Opened At') }}:</td><td>{{ $pos_shift->opened_at ? $pos_shift->opened_at->format('Y-m-d H:i') : '-' }}</td>
            <td class="shift-a4-key">{{ __('Status') }}:</td><td>{{ $pos_shift->status === 'open' ? __('Open') : __('Closed') }}</td>
        </tr>
        <tr>
            <td class="shift-a4-key">{{ __('Opening Balance') }}:</td><td>{{ $fmt($pos_shift->opening_balance ?? 0) }}</td>
            <td class="shift-a4-key">{{ __('Printed') }}:</td><td>{{ date('d/m/Y H:i:s') }}</td>
        </tr>
        @if($pos_shift->closed_at)
        <tr>
            <td class="shift-a4-key">{{ __('Closed At') }}:</td><td>{{ $pos_shift->closed_at->format('Y-m-d H:i') }}</td>
            <td class="shift-a4-key">{{ __('Closing Balance') }}:</td><td>{{ $fmt($pos_shift->closing_balance ?? 0) }}</td>
        </tr>
        @endif
        <tr>
            <td class="shift-a4-key"><strong>{{ __('Sales Total') }}:</strong></td><td><strong>{{ $fmt($salesTotal) }}</strong></td>
            <td class="shift-a4-key">{{ __('Transactions') }}:</td><td>{{ $salesCount ?? ($sales->count() ?? 0) }}</td>
        </tr>
    </table>

    <h2 class="shift-a4-section">{{ __('Cash reconciliation') }}</h2>
    <table class="shift-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <tbody>
            <tr><td>{{ __('Opening balance') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($pos_shift->opening_balance ?? 0) }}</td></tr>
            <tr><td>{{ __('Cash in (POS sales)') }}@if(!empty($balanceCash) && $balanceCash > 0)<span> + {{ __('balance invoice payments') }}</span>@endif</td><td class="shift-a4-num" colspan="3">{{ $fmt($cashTotal ?? 0) }}</td></tr>
            @if(!empty($cashInsTotal) && $cashInsTotal > 0)
                <tr><td>{{ __('Cash in (top-ups)') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($cashInsTotal) }}</td></tr>
            @endif
            @if(!empty($balanceCash) && $balanceCash > 0)
                <tr><td>{{ __('Of which balance / invoice payments (cash)') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($balanceCash) }}</td></tr>
            @endif
            <tr><td>{{ __('Cash drops (spent / out)') }}</td><td class="shift-a4-num" colspan="3">-{{ $fmt($cashDropsTotal ?? 0) }}</td></tr>
            @if(!empty($cashOutExpensesTotal) && $cashOutExpensesTotal > 0)
                <tr><td>{{ __('Cash out (expenses)') }}</td><td class="shift-a4-num" colspan="3">-{{ $fmt($cashOutExpensesTotal) }}</td></tr>
            @endif
        </tbody>
        <tfoot>
            <tr><td>{{ __('Expected cash in drawer') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($expectedCash ?? 0) }}</td></tr>
            @if($pos_shift->closed_at)
                <tr><td>{{ __('Closing balance (counted)') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($pos_shift->closing_balance ?? 0) }}</td></tr>
                <tr><td>{{ __('Difference (over / short)') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($pos_shift->difference ?? 0) }}</td></tr>
            @endif
        </tfoot>
    </table>

    <h2 class="shift-a4-section">{{ __('Payment Summary') }}</h2>
    <table class="shift-a4-data">
        <colgroup><col style="width:40%"><col style="width:20%"><col style="width:20%"><col style="width:20%"></colgroup>
        <tbody>
            <tr><td>{{ __('pos.payment_cash') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($cashTotal) }}</td></tr>
            <tr><td>{{ __('Card / M-Pesa / Bank transfer') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($cardTotal) }}</td></tr>
            <tr><td>{{ __('pos.payment_credit') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($creditTotal) }}</td></tr>
        </tbody>
        <tfoot><tr><td>{{ __('Total') }}</td><td class="shift-a4-num" colspan="3">{{ $fmt($cashTotal + $cardTotal + $creditTotal) }}</td></tr></tfoot>
    </table>

    <h2 class="shift-a4-section">{{ __('Cash drops (detail)') }}</h2>
    <p>{{ __('Total spent / withdrawn') }}: <strong>{{ $fmt($cashDropsTotal ?? 0) }}</strong></p>
    <table class="shift-a4-data">
        <thead><tr><th>{{ __('Date') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th>{{ __('Reason') }}</th><th>{{ __('By') }}</th></tr></thead>
        <tbody>
        @forelse($pos_shift->cashDrops ?? [] as $drop)
            <tr>
                <td>{{ $drop->created_at ? $drop->created_at->format('Y-m-d H:i') : '-' }}</td>
                <td class="shift-a4-num">{{ $fmt($drop->amount) }}</td>
                <td>{{ $drop->reason ?: '—' }}</td>
                <td>{{ $drop->user ? $drop->user->name : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="shift-a4-center-cell">{{ __('No cash drops recorded for this shift.') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    @if(isset($voidedLines) && $voidedLines->isNotEmpty())
    <h2 class="shift-a4-section">{{ __('pos.void_summary') }} ({{ $voidedLines->count() }})</h2>
    <table class="shift-a4-data">
        <thead><tr><th>{{ __('pos.pos_id') }}</th><th>{{ __('Product') }}</th><th class="shift-a4-num">{{ __('Qty') }}</th><th class="shift-a4-num">{{ __('Amount') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Voided at') }}</th><th>{{ __('By') }}</th></tr></thead>
        <tbody>
        @foreach($voidedLines as $line)
            <tr>
                <td>{{ $line->pos ? $posFmt($line->pos->pos_id) : '-' }}</td>
                <td>{{ $line->product ? $line->product->name : '-' }}</td>
                <td class="shift-a4-num">{{ $line->quantity }}</td>
                <td class="shift-a4-num">{{ $fmt($line->price * $line->quantity) }}</td>
                <td>{{ $line->void_reason ?: '—' }}</td>
                <td>{{ $line->voided_at ? $line->voided_at->format('Y-m-d H:i') : '-' }}</td>
                <td>{{ $line->voidedByUser ? $line->voidedByUser->name : '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <h2 class="shift-a4-section">{{ __('Products sold (Qty Summary)') }}</h2>
    <table class="shift-a4-data">
        <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th>{{ __('Table No') }}</th>
                <th>{{ __('Waiter/User Name') }}</th>
                <th class="shift-a4-num">{{ __('Qty') }}</th>
                <th class="shift-a4-num">{{ __('Price incl. tax') }}</th>
                <th class="shift-a4-num">{{ __('Amount excl. tax') }}</th>
                <th class="shift-a4-num">{{ __('VAT') }}</th>
                <th class="shift-a4-num">{{ __('Amount incl. tax') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse(($productQtySummary ?? collect()) as $row)
            <tr>
                <td>{{ $row->product ? $row->product->name : __('Unknown product') }}</td>
                <td>{{ $row->table_name ?: '—' }}</td>
                <td>{{ $row->waiter_name ?: '—' }}</td>
                <td class="shift-a4-num">{{ (float) $row->qty_total }}</td>
                <td class="shift-a4-num">{{ $row->inclusive_unit === null ? '—' : $fmt((float) $row->inclusive_unit) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $row->exclusive_total) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $row->tax_total) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $row->inclusive_total) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="shift-a4-center-cell">{{ __('No sold products for this shift.') }}</td></tr>
        @endforelse
        </tbody>
        @if(($productQtySummary ?? collect())->isNotEmpty())
        <tfoot>
            <tr>
                <td colspan="3">{{ __('Total') }}</td>
                <td class="shift-a4-num">{{ (float) $productQtySummary->sum('qty_total') }}</td>
                <td class="shift-a4-num"></td>
                <td class="shift-a4-num">{{ $fmt((float) $productQtySummary->sum('exclusive_total')) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $productQtySummary->sum('tax_total')) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $productQtySummary->sum('inclusive_total')) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    @foreach([
        ['title' => __('Sales'), 'rows' => $sales, 'empty' => __('No paid transactions for this shift.')],
        ['title' => __('Internal Consumption'), 'rows' => $internalConsumption ?? collect(), 'empty' => __('No internal consumption for this shift.')],
    ] as $section)
    <h2 class="shift-a4-section">{{ $section['title'] }} ({{ $section['rows']->count() }} {{ __('transactions') }})</h2>
    <table class="shift-a4-data">
        <thead>
            <tr>
                <th>{{ __('pos.pos_id') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Document Type') }}</th>
                <th>{{ __('Payment Method') }}</th>
                <th class="shift-a4-num">{{ __('Sub Total') }}</th>
                <th class="shift-a4-num">{{ __('VAT') }}</th>
                <th class="shift-a4-num">{{ __('Total incl. tax') }}</th>
                <th class="shift-a4-num">{{ __('pos.total') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($section['rows'] as $pos)
            <tr>
                <td>{{ $posFmt($pos->pos_id) }}</td>
                <td>{{ $pos->pos_date ?? '-' }}</td>
                <td>{{ $pos->document_type_label }}</td>
                <td>{{ $pos->payment_method_label !== '' ? $pos->payment_method_label : '—' }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $pos->exclusive_total) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $pos->tax_total) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $pos->inclusive_total) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $pos->posPayments->sum('amount')) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="shift-a4-center-cell">{{ $section['empty'] }}</td></tr>
        @endforelse
        </tbody>
        @if($section['rows']->isNotEmpty())
        <tfoot>
            <tr>
                <td colspan="4">{{ __('Total') }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $section['rows']->sum('exclusive_total')) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $section['rows']->sum('tax_total')) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $section['rows']->sum('inclusive_total')) }}</td>
                <td class="shift-a4-num">{{ $fmt((float) $section['rows']->sum(fn ($pos) => (float) $pos->posPayments->sum('amount'))) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
    @endforeach

    <table class="shift-a4-sign">
        <tr>
            <td><div class="shift-a4-sign-line">{{ __('Cashier') }} — {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</div></td>
            <td><div class="shift-a4-sign-line">{{ __('Manager Signature') }}</div></td>
            <td><div class="shift-a4-sign-line">{{ __('Date / Time') }}</div></td>
        </tr>
    </table>

    <div class="shift-a4-foot">{{ $companyName }} · {{ __('Shift Report') }} #{{ $pos_shift->id }}</div>
</div>
