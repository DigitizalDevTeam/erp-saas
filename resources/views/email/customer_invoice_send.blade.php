<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $invoice->invoice ?? '' }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>{{ __('Hello') }} {{ $invoice->name ?? '' }},</p>

    @if (! empty($isReminder))
        <p>
            {{ __('This is a friendly reminder that invoice') }}
            <strong>{{ $invoice->invoice ?? '' }}</strong>
            {{ __('was due on') }} {{ $invoice->due_date }}{{ __(' and is still unpaid. Please arrange payment at your earliest convenience.') }}
        </p>
    @endif

    <p>
        {{ __('Please find attached invoice') }}
        <strong>{{ $invoice->invoice ?? '' }}</strong>{{ __(' for the billing period covered by your contract.') }}
    </p>

    <p>
        {{ __('Issue date') }}: {{ $invoice->issue_date }}<br>
        {{ __('Due date') }}: {{ $invoice->due_date }}<br>
        {{ __('Amount due') }}: {{ number_format((float) $invoice->getTotal(), 2) }}
    </p>

    @if (! empty($invoice->url))
        <p>{{ __('You can also view the invoice here:') }} <a href="{{ $invoice->url }}">{{ $invoice->url }}</a></p>
    @endif

    <p>{{ __('Thank you for your business.') }}</p>
</body>
</html>
