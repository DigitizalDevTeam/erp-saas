<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $receipt->receipt_number ?? '' }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #222; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f2f2f2; }
        .right { text-align: right; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>{{ __('Receipt') }} {{ $receipt->receipt_number ?? '' }}</h1>
    <p class="muted">{{ __('Reference') }}: {{ $receipt->operation->reference ?? '—' }} · {{ __('Confirmed') }}: {{ $receipt->confirmed_at ? $receipt->confirmed_at->format('Y-m-d H:i') : '—' }}</p>

    <table>
        <tr><th>{{ __('Customer') }}</th><td>{{ $receipt->customer->name ?? ($receipt->classification ?? '—') }}</td></tr>
        <tr><th>{{ __('Method') }}</th><td>{{ $receipt->methodLabel() }}</td></tr>
        <tr><th>{{ __('Financial Account') }}</th><td>{{ $receipt->operation->financialAccount ? $receipt->operation->financialAccount->displayName() : '—' }}</td></tr>
        <tr><th>{{ __('Gross Amount') }}</th><td class="right">{{ number_format((float) $receipt->operation->amount_original, 2) }} {{ $receipt->operation->currency }}</td></tr>
        <tr><th>{{ __('Bank Fees') }}</th><td class="right">{{ number_format((float) $receipt->fees, 2) }} {{ $receipt->operation->currency }}</td></tr>
        <tr><th>{{ __('Withholding') }}</th><td class="right">{{ number_format((float) $receipt->withholding, 2) }} {{ $receipt->operation->currency }}</td></tr>
        <tr><th>{{ __('Discount Granted') }}</th><td class="right">{{ number_format((float) $receipt->discount, 2) }} {{ $receipt->operation->currency }}</td></tr>
        <tr><th>{{ __('Net Cash Credited') }}</th><td class="right">{{ number_format((float) $receipt->netCashAmount(), 2) }} {{ $receipt->operation->currency }}</td></tr>
        <tr><th>{{ __('Settlement Value') }}</th><td class="right">{{ number_format((float) $receipt->settlementValue(), 2) }} {{ $receipt->operation->currency }}</td></tr>
        <tr><th>{{ __('Value Date') }}</th><td>{{ $receipt->value_date ? $receipt->value_date->format('Y-m-d') : '—' }}</td></tr>
        @if ($receipt->external_transaction_id)
            <tr><th>{{ __('External Transaction ID') }}</th><td>{{ $receipt->external_transaction_id }}</td></tr>
        @endif
    </table>

    <h3>{{ __('Allocations') }}</h3>
    @if ($receipt->allocations->isEmpty())
        <p class="muted">{{ __('No allocations — the full settlement value is unapplied customer credit.') }}</p>
    @else
        <table>
            <thead>
                <tr><th>{{ __('Invoice') }}</th><th class="right">{{ __('Amount') }}</th><th class="right">{{ __('Base') }}</th><th>{{ __('State') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($receipt->allocations as $allocation)
                    <tr>
                        <td>{{ $allocation->invoice ? 'INV-' . $allocation->invoice->invoice_id : '—' }}</td>
                        <td class="right">{{ number_format((float) $allocation->allocated_amount, 2) }} {{ $allocation->currency }}</td>
                        <td class="right">{{ number_format((float) $allocation->allocated_amount_base, 2) }}</td>
                        <td>{{ ucfirst($allocation->state) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
