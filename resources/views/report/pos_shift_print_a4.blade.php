<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ __('pos.shift_report') }} — {{ $filter['start_date'] }} {{ __('To') }} {{ $filter['end_date'] }}</title>
@include('report.partials.pos_shift_a4_styles')
<style>
    body { margin: 0; }
    .shift-a4-page { padding: 10px 4px; }
    @page { size: A4; margin: 12mm; }
    @media print { .noprint { display: none !important; } }
</style>
</head>
<body>
@include('report.partials.pos_shift_report_a4_content')
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
