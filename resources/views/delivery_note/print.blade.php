<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ __('Delivery Note') }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 20px; }
        .row { display: flex; justify-content: space-between; gap: 20px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px; font-size: 13px; }
        th { background: #f5f5f5; text-align: left; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    @php
        $invoice = $deliveryNote->invoice;
    @endphp
    <div class="wrap">
        <div class="no-print" style="text-align:right; margin-bottom:10px;">
            <button onclick="window.print()">{{ __('Print') }}</button>
        </div>

        <div class="row">
            <div>
                <h2 style="margin:0;">{{ __('Delivery Note') }}</h2>
                <div class="muted">{{ $deliveryNote->delivery_note_no }}</div>
                <div class="muted">{{ __('Issue Date') }}: {{ \Auth::user()->dateFormat($deliveryNote->issue_date) }}</div>
                <div class="muted">{{ __('Issued By') }}: {{ \App\Models\User::find($deliveryNote->created_by)?->name ?? '-' }}</div>
            </div>
            <div style="text-align:right;">
                <div><b>{{ __('Company') }}:</b> {{ $settings['company_name'] ?? '' }}</div>
                <div><b>{{ __('Invoice') }}:</b> {{ \Auth::user()->invoiceNumberFormat($invoice->invoice_id) }}</div>
                @if(!empty($invoice->customer))
                    <div><b>{{ __('Customer') }}:</b> {{ $invoice->customer->name }}</div>
                @endif
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>{{ __('Product') }}</th>
                    <th style="width:140px;">{{ __('Quantity') }}</th>
                    <th>{{ __('Description') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $k => $line)
                    <tr>
                        <td>{{ $k + 1 }}</td>
                        <td>{{ $line->product?->name ?? '-' }}</td>
                        <td>
                            {{ $line->quantity }}
                            @if($line->product?->unit)
                                ({{ $line->product->unit->name }})
                            @endif
                        </td>
                        <td>{{ $line->description ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(!empty($settings['footer_notes']))
            <div style="margin-top:16px;" class="muted">
                {!! $settings['footer_notes'] !!}
            </div>
        @endif
    </div>
</body>
</html>

