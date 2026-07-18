<h5 class="mb-3">{{ __('VAT (ICMS) settlement') }}</h5>
<p class="text-muted small mb-4">{{ __('Enter amounts to post the standard 4435 settlement entries. Leave zero where not applicable.') }}</p>

{{ Form::open(['route' => 'fiscal-year-closing.vat', 'method' => 'post']) }}
@csrf
<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_fiscal_year', __('Fiscal year (reference)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_fiscal_year', $defaultYear, ['class' => 'form-control', 'required' => true]) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_date', __('Date'), ['class' => 'form-label']) }}
            {{ Form::date('vat_date', date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_accounting_period', __('Accounting period'), ['class' => 'form-label']) }}
            {{ Form::select('vat_accounting_period', [
                13 => __('13 — Year-end adjustments'),
                14 => __('14 — Post-audit'),
                15 => __('15 — Final closing'),
            ], 13, ['class' => 'form-control']) }}
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_inv', __('Deductible — inventories (44321)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_inv', null, ['class' => 'form-control', 'step' => '0.01']) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_tang', __('Deductible — tangible assets (44322)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_tang', null, ['class' => 'form-control', 'step' => '0.01']) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_srv', __('Deductible — other goods/services (44323)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_srv', null, ['class' => 'form-control', 'step' => '0.01']) }}
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_collected', __('Collected (44331 → 4435)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_collected', null, ['class' => 'form-control', 'step' => '0.01']) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_payable', __('To payable (4435 → 4437)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_payable', null, ['class' => 'form-control', 'step' => '0.01']) }}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vat_recover', __('To recover (4438 → 4435)'), ['class' => 'form-label']) }}
            {{ Form::number('vat_recover', null, ['class' => 'form-control', 'step' => '0.01']) }}
        </div>
    </div>
</div>
{{ Form::submit(__('Post VAT settlement'), ['class' => 'btn btn-secondary']) }}
{{ Form::close() }}
