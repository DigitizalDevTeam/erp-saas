@php
    $periodOptions = \App\Services\TrialBalanceReportService::accountingPeriodOptions();
    $selectedPeriod = $filter['accountingPeriod'] ?? null;
    $periodLabel = $selectedPeriod !== null && $selectedPeriod !== ''
        ? ($periodOptions[$selectedPeriod] ?? $selectedPeriod)
        : null;
@endphp
@if($periodLabel)
    <p class="small text-muted mb-2">
        {{ __('Accounting period') }}: <strong>{{ $periodLabel }}</strong>
        @if(!empty($filter['fiscalYear']))
            · {{ __('Fiscal year') }}: <strong>{{ $filter['fiscalYear'] }}</strong>
        @endif
    </p>
@endif
