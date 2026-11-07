<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h1 { font-size: 16px; }
        p { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px; text-align: left; }
        th { background: #eee; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>
    <table>
        <thead>
        <tr>
            @foreach ($headings as $heading)
                <th>{{ $heading }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                @foreach ($row as $cell)
                    <td>{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
    @if (! empty($totals))
        <p>
            @foreach ($totals as $label => $value)
                <strong>{{ $label }}:</strong> {{ $value }}&nbsp;&nbsp;
            @endforeach
        </p>
    @endif
</body>
</html>
