<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            margin: 0;
            padding: 20px;
            background: #fff;
        }
        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 16px;
        }
        .toolbar button {
            border: 1px solid #ccc;
            background: #f7f7f7;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 14px;
            font-weight: 700;
        }
        h2 {
            font-size: 14px;
            margin: 18px 0 8px;
            font-weight: 700;
        }
        table.report {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 12px;
            margin-bottom: 12px;
        }
        table.report th,
        table.report td {
            border: 1px solid #333;
            padding: 6px 8px;
            vertical-align: middle;
            word-wrap: break-word;
        }
        table.report th {
            background: #efefef;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
        }
        table.report td.num,
        table.report th.num {
            text-align: right;
        }
        .empty {
            text-align: center;
            color: #666;
            padding: 18px;
        }
        @media print {
            body { padding: 8mm; }
            .toolbar { display: none !important; }
            @page { size: A4 landscape; margin: 8mm; }
            table.report { font-size: 11px; }
            table.report th {
                background: #efefef !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        <button type="button" onclick="window.close()">{{ __('Close') }}</button>
    </div>

    <h1>{{ $title }}</h1>

    @yield('content')

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 250);
        });
    </script>
</body>
</html>
