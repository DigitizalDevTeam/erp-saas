<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
        .doc-header { border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 14px; }
        .doc-header .company { font-size: 18px; font-weight: 700; color: #111827; }
        .doc-header .title { font-size: 14px; font-weight: 700; color: #2563eb; margin-top: 2px; }
        .doc-header .meta { font-size: 10px; color: #6b7280; margin-top: 4px; }
        .summary { width: 100%; margin-bottom: 14px; }
        .summary td { width: 25%; padding: 6px 8px; border: 1px solid #e5e7eb; vertical-align: top; }
        .summary .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .summary .value { font-size: 13px; font-weight: 700; color: #111827; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #2563eb; color: #fff; font-size: 10px; padding: 6px 7px; text-align: left; }
        table.data td { border: 1px solid #e5e7eb; padding: 5px 7px; font-size: 10px; }
        table.data tbody tr:nth-child(even) td { background: #f9fafb; }
        table.data tfoot td { font-weight: 700; background: #eff6ff; border-top: 2px solid #2563eb; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .footer { margin-top: 16px; font-size: 9px; color: #9ca3af; text-align: center; }
        .empty { text-align: center; color: #9ca3af; padding: 18px; }
    </style>
</head>
@php
    $companyTitle = \App\Models\Utility::companyData(\Auth::user()->creatorId(), 'title_text') ?: config('app.name');
    $fmt = function ($value, $type) {
        if ($value === null || $value === '') {
            return in_array($type, ['money','number','percent']) ? '0' : '—';
        }
        return match ($type) {
            'money' => \Auth::user()->priceFormat($value),
            'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%',
            'number' => is_numeric($value) ? number_format((float) $value) : $value,
            default => $value,
        };
    };
@endphp
<body>
    <div class="doc-header">
        <div class="company">{{ $companyTitle }}</div>
        <div class="title">{{ $title }}</div>
        <div class="meta">
            @if(!empty($from) && !empty($to)){{ __('Period') }}: {{ $from->toDateString() }} — {{ $to->toDateString() }} · @endif
            {{ __('Generated') }}: {{ now()->format('Y-m-d H:i') }}
            @if(\Auth::check()) · {{ __('By') }}: {{ \Auth::user()->name }} @endif
        </div>
    </div>

    @if(!empty($summary))
        <table class="summary">
            <tr>
                @foreach($summary as $i => $stat)
                    <td>
                        <div class="label">{{ $stat['label'] }}</div>
                        <div class="value">{{ $stat['value'] }}</div>
                    </td>
                    @if(($i + 1) % 4 === 0 && !$loop->last)</tr><tr>@endif
                @endforeach
            </tr>
        </table>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach($columns as $col)
                    @php $type = $col['type'] ?? 'text'; $align = $col['align'] ?? (in_array($type, ['money','number','percent']) ? 'end' : ''); @endphp
                    <th class="{{ $align ? 'text-'.$align : '' }}">{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($columns as $col)
                        @php $type = $col['type'] ?? 'text'; $align = $col['align'] ?? (in_array($type, ['money','number','percent']) ? 'end' : ''); @endphp
                        <td class="{{ $align ? 'text-'.$align : '' }}">{{ $fmt($row[$col['key']] ?? null, $type) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($columns) }}">{{ $emptyText ?? __('No data found.') }}</td></tr>
            @endforelse
        </tbody>
        @if(!empty($totalsRow))
            <tfoot>
                <tr>
                    @foreach($columns as $col)
                        @php $type = $col['type'] ?? 'text'; $align = $col['align'] ?? (in_array($type, ['money','number','percent']) ? 'end' : ''); @endphp
                        <td class="{{ $align ? 'text-'.$align : '' }}">{{ array_key_exists($col['key'], $totalsRow) ? $fmt($totalsRow[$col['key']], $type) : '' }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="footer">{{ $companyTitle }} · {{ $title }} · {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
