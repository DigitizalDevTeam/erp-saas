<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Refund Receipt') }} {{ $refund->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
        th { width: 35%; color: #555; font-weight: normal; }
        .amount { font-size: 18px; font-weight: bold; }
        .sign { margin-top: 60px; }
        .sign td { border-bottom: none; padding-top: 30px; }
        .line { border-top: 1px solid #222; width: 220px; display: block; }
    </style>
</head>
<body>
    <h1>{{ __('Refund Receipt') }}</h1>
    <p class="muted">{{ __('Internal receipt') }} — {{ $refund->reference }}</p>

    <table>
        <tr>
            <th>{{ __('Reference') }}</th>
            <td><strong>{{ $refund->reference }}</strong></td>
        </tr>
        <tr>
            <th>{{ __('Date') }}</th>
            <td>{{ $refund->created_at->format('Y-m-d H:i') }}</td>
        </tr>
        <tr>
            <th>{{ __('Customer') }}</th>
            <td>{{ $refund->customer->name ?? '-' }}</td>
        </tr>
        <tr>
            <th>{{ __('Credit note') }}</th>
            <td>{{ $refund->note ? '#' . $refund->note->uid : '-' }}</td>
        </tr>
        <tr>
            <th>{{ __('Invoice') }}</th>
            <td>{{ $refund->invoice ? ($refund->invoice->invoice_id ?? $refund->invoice->id) : '-' }}</td>
        </tr>
        <tr>
            <th>{{ __('Method') }}</th>
            <td>{{ $refund->method === 'transfer' ? __('Bank transfer') : __('Cash') }}</td>
        </tr>
        <tr>
            <th>{{ __('Paid from') }}</th>
            <td>{{ $refund->financialAccount?->code ?? '-' }}@if($refund->pettyCashFund) ({{ __('petty cash') }})@endif</td>
        </tr>
        @if($refund->cashSession)
        <tr>
            <th>{{ __('Till session') }}</th>
            <td>{{ $refund->cashSession->register?->code ?? '' }} — {{ __('opened') }} {{ $refund->cashSession->opened_at }}</td>
        </tr>
        @endif
        <tr>
            <th>{{ __('Amount refunded') }}</th>
            <td class="amount">{{ number_format((float) $refund->amount, 2) }} {{ $refund->currency }}</td>
        </tr>
        @if($refund->transfer_receipt)
        <tr>
            <th>{{ __('Transfer receipt') }}</th>
            <td>{{ $refund->transfer_receipt }}</td>
        </tr>
        @endif
        @if($refund->signed_receipt)
        <tr>
            <th>{{ __('Signed customer receipt') }}</th>
            <td>{{ $refund->signed_receipt }}</td>
        </tr>
        @endif
        <tr>
            <th>{{ __('Treasury operation') }}</th>
            <td>{{ $refund->operation?->reference ?? '-' }}</td>
        </tr>
    </table>

    @if($refund->method === 'cash')
    <table class="sign">
        <tr>
            <td>
                <span class="line"></span><br>
                {{ __('Customer signature') }}
            </td>
            <td>
                <span class="line"></span><br>
                {{ __('Issued by') }}
            </td>
        </tr>
    </table>
    @endif
</body>
</html>
