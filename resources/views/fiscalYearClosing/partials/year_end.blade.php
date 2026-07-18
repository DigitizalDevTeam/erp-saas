<h5 class="mb-3">{{ __('Year-end closing (SNC-style)') }}</h5>
<p class="text-muted small mb-4">
    {{ __('Closes classes 61–68 and 71–76 to 81, 69/78 to 82, transfers 81+82 to 83, then loss to 88 or IRPC (32%) and net profit to 88. Requires chart accounts whose codes start with 81, 82, 83 (and 85, 44101, 88 for profits). Account classes use the first two digits of each code.') }}
</p>

{{ Form::open(['route' => 'fiscal-year-closing.run', 'method' => 'post', 'class' => 'needs-validation']) }}
@csrf
<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('fiscal_year', __('Fiscal year'), ['class' => 'form-label']) }}
            {{ Form::number('fiscal_year', $defaultYear, ['class' => 'form-control', 'required' => true, 'min' => 2000, 'max' => 2100]) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('closing_date', __('Closing date'), ['class' => 'form-label']) }}
            {{ Form::date('closing_date', date('Y-12-31', strtotime($defaultYear.'-01-01')), ['class' => 'form-control', 'required' => true]) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('accounting_period', __('Accounting period'), ['class' => 'form-label']) }}
            {{ Form::select('accounting_period', [
                13 => __('13 — Year-end adjustments'),
                14 => __('14 — Post-audit'),
                15 => __('15 — Final closing'),
            ], 15, ['class' => 'form-control']) }}
            <small class="text-muted">{{ __('Use periods 13–15 only. Opening balances belong in Period 0.') }}</small>
        </div>
    </div>
</div>
<div class="form-check mb-3">
    {{ Form::checkbox('allow_duplicate', 1, false, ['class' => 'form-check-input', 'id' => 'allow_duplicate']) }}
    {{ Form::label('allow_duplicate', __('Allow posting even if FY closing references already exist (not recommended)'), ['class' => 'form-check-label']) }}
</div>
{{ Form::submit(__('Run fiscal closing'), ['class' => 'btn btn-primary']) }}
{{ Form::close() }}
