@php
    $periodOptions = \App\Services\TrialBalanceReportService::accountingPeriodRangeOptions();
    $from = $filter['accountingPeriodFrom'] ?? null;
    $to = $filter['accountingPeriodTo'] ?? null;
    $periodLabel = null;
    if ($from !== null && $to !== null) {
        if ((int) $from === (int) $to) {
            $periodLabel = $periodOptions[$from] ?? $from;
        } else {
            $fromLabel = $periodOptions[$from] ?? $from;
            $toLabel = $periodOptions[$to] ?? $to;
            $periodLabel = $fromLabel . ' → ' . $toLabel;
        }
    }
@endphp
@if($periodLabel)
    <p class="small text-muted mb-2">
        {{ __('Accounting period') }}: <strong>{{ $periodLabel }}</strong>
        @if(!empty($filter['fiscalYear']))
            · {{ __('Fiscal year') }}: <strong>{{ $filter['fiscalYear'] }}</strong>
        @endif
    </p>
@endif
