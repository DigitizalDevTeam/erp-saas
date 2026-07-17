@php
    $periodOptions = \App\Services\TrialBalanceReportService::accountingPeriodOptions();
    $selectedPeriod = $filter['accountingPeriod'] ?? null;
    $fiscalYear = $filter['fiscalYear'] ?? (int) date('Y', strtotime($filter['startDateRange'] ?? date('Y-01-01')));
    $periodLabel = $selectedPeriod !== null && $selectedPeriod !== ''
        ? ($periodOptions[$selectedPeriod] ?? $selectedPeriod)
        : $periodOptions[''];
@endphp
<div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('fiscal_year', __('Fiscal year'), ['class' => 'form-label']) }}
        {{ Form::number('fiscal_year', old('fiscal_year', $fiscalYear), [
            'class' => 'form-control',
            'min' => 2000,
            'max' => 2100,
        ]) }}
    </div>
</div>
<div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('accounting_period', __('Accounting period'), ['class' => 'form-label']) }}
        {{ Form::select('accounting_period', $periodOptions, old('accounting_period', $selectedPeriod), [
            'class' => 'form-control',
        ]) }}
        <small class="text-muted">{{ __('Use 13–15 for year-end closing. Operational view excludes those periods.') }}</small>
    </div>
</div>
<div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
        {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'startDate form-control']) }}
    </div>
</div>
<div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
        {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'endDate form-control']) }}
    </div>
</div>
