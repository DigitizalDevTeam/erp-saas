@extends('layouts.admin')
@section('page-title')
    {{ __('Fiscal year closing') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Double Entry') }}</li>
    <li class="breadcrumb-item">{{ __('Fiscal year closing') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Year-end closing (SNC-style)') }}</h5>
                    <p class="text-muted small mb-0">
                        {{ __('Closes classes 61–68 and 71–76 to 81, 69/78 to 82, transfers 81+82 to 83, then loss to 88 or IRPC (32%) and net profit to 88. Requires chart accounts whose codes start with 81, 82, 83 (and 85, 44101, 88 for profits). Account classes use the first two digits of each code.') }}
                    </p>
                </div>
                <div class="card-body">
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
                                    0 => __('0 — Opening'),
                                    13 => __('13 — Year-end adjustments'),
                                    14 => __('14 — Post-audit'),
                                    15 => __('15 — Final closing'),
                                ], 15, ['class' => 'form-control']) }}
                                <small class="text-muted">{{ __('Use 13–15 so normal months stay unchanged.') }}</small>
                            </div>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        {{ Form::checkbox('allow_duplicate', 1, false, ['class' => 'form-check-input', 'id' => 'allow_duplicate']) }}
                        {{ Form::label('allow_duplicate', __('Allow posting even if FY closing references already exist (not recommended)'), ['class' => 'form-check-label']) }}
                    </div>
                    {{ Form::submit(__('Run fiscal closing'), ['class' => 'btn btn-primary']) }}
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('VAT (ICMS) settlement') }}</h5>
                    <p class="text-muted small mb-0">{{ __('Enter amounts to post the standard 4435 settlement entries. Leave zero where not applicable.') }}</p>
                </div>
                <div class="card-body">
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
                                    0 => __('0 — Opening'),
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
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Payroll mapping (reference)') }}</h6>
                    <p class="small mb-0">{{ __('Debit 622 / 623; credit 449, 4421, 4622 — post via Journal Entry or HR integration. Accruals/deferrals: use group 49 (not 27).') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
