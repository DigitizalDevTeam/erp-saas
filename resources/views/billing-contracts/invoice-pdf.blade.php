<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $number }}</title>
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
    <h1>{{ __('Invoice') }} {{ $number }}</h1>
    <p class="muted">
        {{ __('Customer') }}: {{ $customer->name ?? '—' }}<br>
        {{ __('Issue date') }}: {{ $invoice->issue_date }} · {{ __('Due date') }}: {{ $invoice->due_date }}<br>
        @if ($invoice->ref_number){{ __('Reference') }}: {{ $invoice->ref_number }}<br>@endif
        {{ __('Status') }}: {{ $invoice->status == 1 ? __('Sent') : __('Draft') }}
    </p>

    <table>
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th class="right">{{ __('Qty') }}</th>
                <th class="right">{{ __('Unit price') }}</th>
                <th class="right">{{ __('Discount') }}</th>
                <th class="right">{{ __('Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description ?? ($item->product->name ?? '—') }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ number_format((float) $item->price, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->discount, 2) }}</td>
                    <td class="right">{{ number_format((float) ($item->price * $item->quantity - $item->discount), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table>
        <tr><th>{{ __('Subtotal') }}</th><td class="right">{{ number_format((float) $invoice->getSubTotal(), 2) }}</td></tr>
        <tr><th>{{ __('Tax') }}</th><td class="right">{{ number_format((float) $invoice->getTotalTax(), 2) }}</td></tr>
        <tr><th>{{ __('Total') }}</th><td class="right">{{ number_format((float) $invoice->getTotal(), 2) }}</td></tr>
        <tr><th>{{ __('Balance due') }}</th><td class="right">{{ number_format((float) $invoice->getDue(), 2) }}</td></tr>
    </table>

    <p class="muted"><small>{{ __('Issued automatically from billing contract') }} {{ $invoice->ref_number ?? '' }}.</small></p>
</body>
</html>
