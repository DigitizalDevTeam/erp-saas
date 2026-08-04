@php
    $periodRangeOptions = ['' => __('—')] + \App\Services\TrialBalanceReportService::accountingPeriodRangeOptions();
    $selectedFrom = $filter['accountingPeriodFrom'] ?? null;
    $selectedTo = $filter['accountingPeriodTo'] ?? null;
    $fiscalYear = $filter['fiscalYear'] ?? (int) date('Y', strtotime($filter['startDateRange'] ?? date('Y-01-01')));
@endphp
<div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('fiscal_year', __('Fiscal year'), ['class' => 'form-label']) }}
        {{ Form::number('fiscal_year', old('fiscal_year', $fiscalYear), [
            'class' => 'form-control',
            'min' => 2000,
            'max' => 2100,
        ]) }}
    </div>
</div>
<div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('accounting_period_from', __('Month from'), ['class' => 'form-label']) }}
        {{ Form::select('accounting_period_from', $periodRangeOptions, old('accounting_period_from', $selectedFrom), [
            'class' => 'form-control',
        ]) }}
    </div>
</div>
<div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 col-12">
    <div class="btn-box">
        {{ Form::label('accounting_period_to', __('Month to'), ['class' => 'form-label']) }}
        {{ Form::select('accounting_period_to', $periodRangeOptions, old('accounting_period_to', $selectedTo), [
            'class' => 'form-control',
        ]) }}
        <small class="text-muted">{{ __('Range 0–15. Blank defaults to full year (0→15), including year-end closing.') }}</small>
    </div>
</div>
