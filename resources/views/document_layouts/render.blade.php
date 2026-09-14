@php
    $pageW = (int) ($layout->page_width ?: 794);
    $pageH = (int) ($layout->page_height ?: 1123);
    $elements = collect($layout->elements ?: [])->filter(fn ($e) => !empty($e['visible']));
    $color = $payload['color'] ?? '#6fd943';
    $settings = $payload['settings'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payload['title'] ?? __('Document') }} — {{ $payload['number'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #222; background: #f0f0f0; }
        .dl-page {
            position: relative;
            width: {{ $pageW }}px;
            height: {{ $pageH }}px;
            margin: 16px auto;
            background: #fff;
            border: 1px solid #ddd;
            overflow: hidden;
        }
        .dl-el {
            position: absolute;
            overflow: hidden;
            font-size: 12px;
            line-height: 1.35;
        }
        .dl-el h4 { font-size: 13px; margin-bottom: 4px; color: {{ $color }}; }
        .dl-el table { width: 100%; border-collapse: collapse; }
        .dl-el th, .dl-el td { border-bottom: 1px solid #e5e5e5; padding: 4px 6px; text-align: left; }
        .dl-el th { background: {{ $color }}; color: #fff; font-size: 11px; }
        .dl-el .text-end { text-align: right; }
        .dl-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .dl-muted { color: #666; font-size: 11px; }
        .dl-processed-by {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 6px 12px;
            text-align: center;
            font-size: 10px;
            color: #555;
            border-top: 1px solid #e5e5e5;
            background: #fff;
            z-index: 20;
        }
        @media print {
            body { background: #fff; }
            .dl-page { margin: 0; border: none; box-shadow: none; }
            .no-print { display: none !important; }
            .dl-processed-by { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
@if(!empty($preview))
    <div class="no-print" style="text-align:center;padding:8px;background:#fff3cd;">
        {{ __('Layout preview') }} — {{ $payload['title'] ?? '' }}
        <button type="button" onclick="window.print()" style="margin-left:12px;">{{ __('Print') }}</button>
    </div>
@endif

<div class="dl-page">
    @foreach ($elements as $el)
        @php
            $key = $el['key'] ?? '';
            $style = 'left:' . (int)($el['x'] ?? 0) . 'px;top:' . (int)($el['y'] ?? 0) . 'px;width:' . (int)($el['w'] ?? 100) . 'px;height:' . (int)($el['h'] ?? 40) . 'px;';
            $options = is_array($el['options'] ?? null) ? $el['options'] : [];
        @endphp
        @if (view()->exists('document_layouts.blocks.' . $key))
            <div class="dl-el" style="{{ $style }}">
                @include('document_layouts.blocks.' . $key, [
                    'payload' => $payload,
                    'settings' => $settings,
                    'options' => $options,
                    'color' => $color,
                ])
            </div>
        @endif
    @endforeach

    <div class="dl-processed-by">{{ __('pos.document_processed_by_computer') }}</div>
</div>

@if(empty($preview))
<script>window.addEventListener('load', function () { /* print ready */ });</script>
@endif
</body>
</html>
