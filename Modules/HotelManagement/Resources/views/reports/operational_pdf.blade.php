<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Operational report') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
<h2>{{ __('Hotel operational report') }}</h2>
<p>{{ $from->toDateString() }} — {{ $to->toDateString() }} · {{ __('Rooms') }}: {{ $roomsTotal }} · {{ __('ALOS') }}: {{ $avgStay }}</p>
<table>
    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Occupancy %') }}</th><th>{{ __('Check-ins') }}</th><th>{{ __('Check-outs') }}</th></tr></thead>
    <tbody>
    @foreach($daily as $row)
        <tr>
            <td>{{ $row['date'] }}</td>
            <td>{{ $row['occupancy_pct'] }}</td>
            <td>{{ $row['check_ins'] }}</td>
            <td>{{ $row['check_outs'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
