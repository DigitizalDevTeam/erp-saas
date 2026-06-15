<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Financial report') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
<h2>{{ __('Hotel financial report') }}</h2>
<p>{{ $from->toDateString() }} — {{ $to->toDateString() }}</p>
<p>
    <strong>{{ __('Total revenue') }}:</strong> {{ number_format($totalRevenue, 2) }} ·
    <strong>{{ __('Total paid') }}:</strong> {{ number_format($totalPaid, 2) }} ·
    <strong>{{ __('Discounts') }}:</strong> {{ number_format($discountTotal, 2) }}
</p>
@if($methodTotals->isNotEmpty())
    <p><strong>{{ __('Payments by method') }}:</strong>
        @foreach($methodTotals as $m => $t)
            {{ $m }}: {{ number_format($t, 2) }}@if(!$loop->last), @endif
        @endforeach
    </p>
@endif
<table>
    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Revenue') }}</th><th>{{ __('Paid') }}</th></tr></thead>
    <tbody>
    @foreach($folioByDay as $row)
        <tr>
            <td>{{ $row->d }}</td>
            <td>{{ number_format($row->revenue, 2) }}</td>
            <td>{{ number_format($row->paid, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
