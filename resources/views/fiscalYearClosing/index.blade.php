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
                    <h5>{{ __('Period 0 — Opening balances') }}</h5>
                    <p class="text-muted small mb-0">
                        {{ __('Carry forward prior-year closing balances for balance-sheet accounts (classes 1–5) into period 0 of the new fiscal year. P&L accounts (classes 6–8) are excluded. View results in Trial Balance with period 0 selected.') }}
                    </p>
                </div>
                <div class="card-body">
                    {{ Form::open(['route' => 'fiscal-year-closing.index', 'method' => 'get', 'class' => 'mb-3']) }}
                    <div class="row align-items-end">
                        <div class="col-md-4">
                            <div class="form-group mb-md-0">
                                {{ Form::label('opening_target_year', __('Target fiscal year'), ['class' => 'form-label']) }}
                                {{ Form::number('opening_target_year', $openingTargetYear, ['class' => 'form-control', 'required' => true, 'min' => 2001, 'max' => 2100]) }}
                                <small class="text-muted">{{ __('Balances are taken from the previous calendar year.') }}</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            {{ Form::submit(__('Preview opening balances'), ['class' => 'btn btn-outline-primary']) }}
                        </div>
                    </div>
                    {{ Form::close() }}

                    @if ($openingPreview !== null)
                        <div class="alert alert-{{ $openingPreview['success'] ? 'info' : 'warning' }} mb-3">
                            <p class="mb-1">
                                {{ __('Source year') }}: <strong>{{ $openingPreview['source_year'] }}</strong>
                                · {{ __('Opening date') }}: <strong>{{ $openingPreview['opening_date'] }}</strong>
                                · {{ __('Reference') }}: <strong>{{ $openingPreview['reference'] }}</strong>
                            </p>
                            @if ($openingPreview['already_posted'])
                                <p class="mb-0 text-danger">{{ __('Opening balances for this year are already posted.') }}</p>
                            @endif
                            @foreach ($openingPreview['messages'] as $message)
                                <p class="mb-0">{{ $message }}</p>
                            @endforeach
                        </div>

                        @if ($openingPreview['lines'] !== [])
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Code') }}</th>
                                            <th>{{ __('Account') }}</th>
                                            <th class="text-end">{{ __('Debit') }}</th>
                                            <th class="text-end">{{ __('Credit') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($openingPreview['lines'] as $line)
                                            <tr>
                                                <td>{{ $line['code'] }}</td>
                                                <td>{{ $line['name'] }}</td>
                                                <td class="text-end">{{ $line['debit'] > 0 ? number_format($line['debit'], 2) : '' }}</td>
                                                <td class="text-end">{{ $line['credit'] > 0 ? number_format($line['credit'], 2) : '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-bold">
                                            <td colspan="2">{{ __('Totals') }}</td>
                                            <td class="text-end">{{ number_format($openingPreview['total_debit'], 2) }}</td>
                                            <td class="text-end">{{ number_format($openingPreview['total_credit'], 2) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endif

                        @if ($openingPreview['success'])
                            {{ Form::open(['route' => 'fiscal-year-closing.opening', 'method' => 'post']) }}
                            @csrf
                            {{ Form::hidden('opening_target_year', $openingTargetYear) }}
                            <div class="form-check mb-3">
                                {{ Form::checkbox('allow_duplicate_opening', 1, false, ['class' => 'form-check-input', 'id' => 'allow_duplicate_opening']) }}
                                {{ Form::label('allow_duplicate_opening', __('Allow posting even if period 0 opening reference already exists (not recommended)'), ['class' => 'form-check-label']) }}
                            </div>
                            {{ Form::submit(__('Post opening balances (period 0)'), ['class' => 'btn btn-primary']) }}
                            {{ Form::close() }}
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
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
                                    13 => __('13 — Year-end adjustments'),
                                    14 => __('14 — Post-audit'),
                                    15 => __('15 — Final closing'),
                                ], 15, ['class' => 'form-control']) }}
                                <small class="text-muted">{{ __('Use 13–15 for year-end closing. Post opening balances separately in period 0 above.') }}</small>
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
