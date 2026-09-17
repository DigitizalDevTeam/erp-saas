@php
    $pageW = (int) ($layout->page_width ?: 794);
    $pageH = (int) ($layout->page_height ?: 1123);
    $elements = collect($layout->elements ?: [])->filter(fn ($e) => ($e['visible'] ?? true) !== false);
    $color = $payload['color'] ?? '#f9b034';
    $settings = $payload['settings'] ?? [];
    $baseTemplate = (string) ($layout->base_template ?? '');
    $isMoz = \App\Services\DocumentLayoutCatalog::isMozambiqueTheme($baseTemplate);
    $themeClass = $isMoz ? 'dl-theme-mozambique' : 'dl-theme-default';
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

        /* Mozambique Layout theme (template11 / Standard) */
        .dl-theme-mozambique .dl-company-box {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 8px 10px;
            background: #fafafa;
        }
        .dl-theme-mozambique .dl-doc-badge {
            background: {{ $color }};
            color: #fff;
            border-radius: 6px;
            padding: 10px 12px;
            text-align: center;
        }
        .dl-theme-mozambique .dl-doc-badge h4 {
            color: #fff !important;
            font-size: 18px;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 0 0 6px;
        }
        .dl-theme-mozambique .dl-doc-badge .dl-doc-num {
            font-weight: 700;
            font-size: 13px;
        }
        .dl-theme-mozambique .dl-party-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .dl-theme-mozambique .dl-party-col h4 {
            text-transform: uppercase;
            font-size: 12px;
            margin-bottom: 6px;
        }
        .dl-theme-mozambique .dl-due-box {
            text-align: right;
        }
        .dl-theme-mozambique .dl-due-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }
        .dl-theme-mozambique .dl-due-amount {
            font-size: 22px;
            font-weight: 800;
            color: {{ $color }};
            margin: 4px 0 8px;
        }
        .dl-theme-mozambique .dl-el th {
            background: {{ $color }};
            color: #fff;
            text-transform: uppercase;
            font-size: 10px;
        }
        .dl-theme-mozambique .dl-el table tr:nth-child(even) td { background: #fafafa; }

        @media print {
            body { background: #fff; }
            .dl-page { margin: 0; border: none; box-shadow: none; }
            .no-print { display: none !important; }
            .dl-processed-by, .dl-theme-mozambique .dl-doc-badge, .dl-theme-mozambique .dl-el th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
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

<div class="dl-page {{ $themeClass }}">
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
                    'isMoz' => $isMoz,
                ])
            </div>
        @endif
    @endforeach

    <div class="dl-processed-by">{{ __('pos.document_processed_by_computer') }}</div>
</div>
</body>
</html>
