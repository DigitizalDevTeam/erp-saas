@php
    $title = $title ?? '';
    $value = $value ?? 0;
    $subtitle = $subtitle ?? null;
    $accent = $accent ?? '#6366F1';
    $series = $series ?? [];
    $trendPct = $trendPct ?? null;
    $icon = $icon ?? null;
    $sparkId = 'kpi-spark-' . uniqid();
    $href = $href ?? null;
@endphp

<div class="card shadow-sm border-0 h-100 hotel-kpi-card" style="border-left: 4px solid {{ $accent }} !important;">
    <div class="card-body p-3">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <div class="flex-grow-1" style="min-width:0;">
                <p class="text-muted mb-1 small text-truncate">{{ $title }}</p>
                <h3 class="mb-0" style="color: {{ $accent }};">
                    @if($href)
                        <a href="{{ $href }}" class="text-decoration-none" style="color: inherit;">{{ $value }}</a>
                    @else
                        {{ $value }}
                    @endif
                </h3>
                @if($subtitle)
                    <small class="text-muted d-block">{{ $subtitle }}</small>
                @endif
                @if($trendPct !== null)
                    @php
                        $trendVal = (float) $trendPct;
                        $trendClass = $trendVal > 0.01 ? 'text-success' : ($trendVal < -0.01 ? 'text-danger' : 'text-muted');
                        $trendIcon = $trendVal > 0.01 ? 'ti-trending-up' : ($trendVal < -0.01 ? 'ti-trending-down' : 'ti-minus');
                        $trendSign = $trendVal > 0 ? '+' : '';
                    @endphp
                    <span class="small {{ $trendClass }} d-inline-flex align-items-center mt-1" style="font-weight:500;">
                        <i class="ti {{ $trendIcon }} me-1"></i>{{ $trendSign }}{{ number_format($trendVal, 1) }}% {{ __('vs yesterday') }}
                    </span>
                @endif
            </div>
            @if($icon)
                <div class="d-flex align-items-center justify-content-center rounded" style="width:42px;height:42px;background:{{ $accent }}1a;color:{{ $accent }};flex-shrink:0;">
                    <i class="{{ $icon }}" style="font-size:22px;"></i>
                </div>
            @endif
        </div>
        @if(!empty($series) && is_array($series) || $series instanceof \Illuminate\Support\Collection)
            @php
                $seriesArr = $series instanceof \Illuminate\Support\Collection ? $series->values()->all() : array_values((array) $series);
            @endphp
            <div id="{{ $sparkId }}" class="hotel-kpi-spark mt-1"
                 data-sparkline='@json($seriesArr)'
                 data-sparkline-color="{{ $accent }}"
                 style="height:42px;"></div>
        @endif
    </div>
</div>

@once
    @push('script-page')
        <script>
            (function () {
                function bootHotelSparklines() {
                    if (typeof ApexCharts === 'undefined') return;
                    document.querySelectorAll('.hotel-kpi-spark[data-sparkline]').forEach(function (el) {
                        if (el.dataset.bootDone === '1') return;
                        var raw = el.getAttribute('data-sparkline');
                        var color = el.getAttribute('data-sparkline-color') || '#6366F1';
                        var data;
                        try { data = JSON.parse(raw); } catch (e) { data = []; }
                        if (!data || !data.length) return;
                        try {
                            new ApexCharts(el, {
                                chart: { type: 'area', height: 42, sparkline: { enabled: true } },
                                stroke: { curve: 'smooth', width: 2 },
                                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.0, stops: [0, 100] } },
                                series: [{ data: data }],
                                colors: [color],
                                tooltip: { fixed: { enabled: false }, x: { show: false }, marker: { show: false } }
                            }).render();
                            el.dataset.bootDone = '1';
                        } catch (err) { /* ignore */ }
                    });
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', bootHotelSparklines);
                } else {
                    bootHotelSparklines();
                }
            })();
        </script>
    @endpush
@endonce
