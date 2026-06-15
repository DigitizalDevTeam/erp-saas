<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Immigration report') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
<h2>{{ __('Hotel immigration / guest register') }}</h2>
<p>{{ $from->toDateString() }} — {{ $to->toDateString() }} · {{ __('Total guests') }}: {{ $rows->count() }}</p>
<table>
    <thead>
        <tr>
            <th>{{ __('Reservation') }}</th>
            <th>{{ __('Full name') }}</th>
            <th>{{ __('Nationality') }}</th>
            <th>{{ __('ID type') }}</th>
            <th>{{ __('ID / Passport') }}</th>
            <th>{{ __('Birth date') }}</th>
            <th>{{ __('Check-in') }}</th>
            <th>{{ __('Check-out') }}</th>
            <th>{{ __('Room') }}</th>
            <th>{{ __('Role') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td>{{ $row['reservation_no'] }}</td>
            <td>{{ $row['full_name'] }}</td>
            <td>{{ $row['nationality'] }}</td>
            <td>{{ $row['id_type'] }}</td>
            <td>{{ $row['id_number'] }}</td>
            <td>{{ $row['birth_date'] }}</td>
            <td>{{ $row['check_in_date'] }}</td>
            <td>{{ $row['check_out_date'] }}</td>
            <td>{{ $row['room'] }}</td>
            <td>{{ $row['role'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
