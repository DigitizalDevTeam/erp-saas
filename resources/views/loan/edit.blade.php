@php
    $locked = $loan->isInstallment() && $loan->repayments()->exists();
@endphp
{{ Form::model($loan, ['route' => ['loan.update', $loan->id], 'method' => 'PUT', 'class' => 'needs-validation', 'novalidate', 'id' => 'loan-edit-form']) }}
<div class="modal-body">
    <div class="card-body p-0">
        <div class="row mb-2">
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('schedule_display', __('Loan type'), ['class' => 'form-label']) }}
                    <input type="text" class="form-control" readonly
                           value="{{ $loan->isInstallment() ? __('Term loan (installment schedule)') : __('Legacy (fixed or % of salary)') }}">
                </div>
            </div>
            @if($loan->isInstallment())
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('loan_status_display', __('Loan status'), ['class' => 'form-label']) }}
                        <input type="text" class="form-control" readonly value="{{ ucfirst(str_replace('_', ' ', $loan->loan_status ?? '')) }}">
                    </div>
                </div>
            @endif
        </div>

        @if($loan->isInstallment() && $locked)
            <div class="border rounded p-3 mb-3 bg-light">
                <h6 class="mb-3">{{ __('Schedule (read only — repayments started)') }}</h6>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Original principal') }}</label>
                            <input type="text" class="form-control" readonly value="{{ \Auth::user()->priceFormat($loan->principal_amount ?? 0) }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Outstanding principal') }}</label>
                            <input type="text" class="form-control" readonly value="{{ \Auth::user()->priceFormat($loan->outstanding_principal ?? 0) }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Annual interest rate (%)') }}</label>
                            <input type="text" class="form-control" readonly value="{{ $loan->annual_interest_rate }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Monthly EMI') }}</label>
                            <input type="text" class="form-control" readonly value="{{ \Auth::user()->priceFormat($loan->monthly_installment ?? 0) }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Term (months)') }}</label>
                            <input type="text" class="form-control" readonly value="{{ (int) $loan->term_months }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Installments paid') }}</label>
                            <input type="text" class="form-control" readonly value="{{ (int) $loan->installments_paid }} / {{ (int) $loan->term_months }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('First payroll deduction month') }}</label>
                            <input type="text" class="form-control" readonly value="{{ $loan->first_deduction_month ?? '—' }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Expected end date') }}</label>
                            <input type="text" class="form-control" readonly value="{{ $loan->end_date ? \Auth::user()->dateFormat($loan->end_date) : '—' }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">{{ __('Loan start date') }}</label>
                            <input type="text" class="form-control" readonly value="{{ $loan->start_date ? \Auth::user()->dateFormat($loan->start_date) : '—' }}">
                        </div>
                    </div>
                </div>
                <p class="mb-0 small text-muted">{{ __('Only title, loan option, and reason can be changed after repayments are posted.') }}</p>
            </div>
        @elseif($loan->isInstallment() && !$locked)
            <div class="border rounded p-3 mb-3 bg-light">
                <h6 class="mb-3">{{ __('Progress') }}</h6>
                <div class="row small">
                    <div class="col-md-6"><strong>{{ __('Outstanding principal') }}:</strong> {{ \Auth::user()->priceFormat($loan->outstanding_principal ?? 0) }}</div>
                    <div class="col-md-6"><strong>{{ __('Installments paid') }}:</strong> {{ (int) $loan->installments_paid }} / {{ (int) $loan->term_months }}</div>
                </div>
            </div>
        @endif

        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('title', __('Title'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::text('title', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Enter Title')]) }}
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('loan_option', __('Loan Options'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::select('loan_option', $loan_options, null, ['class' => 'form-control select', 'required' => 'required']) }}
                </div>
            </div>

            @if($loan->isInstallment() && !$locked)
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('principal_amount', __('Principal amount'), ['class' => 'form-label']) }}<x-required></x-required>
                        {{ Form::number('principal_amount', $loan->principal_amount, ['class' => 'form-control', 'step' => '0.01', 'required' => 'required', 'id' => 'edit_principal_amount']) }}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('annual_interest_rate', __('Annual interest rate (%)'), ['class' => 'form-label']) }}
                        {{ Form::number('annual_interest_rate', $loan->annual_interest_rate, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0', 'id' => 'edit_annual_interest_rate']) }}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('term_months', __('Term (months)'), ['class' => 'form-label']) }}<x-required></x-required>
                        {{ Form::number('term_months', $loan->term_months, ['class' => 'form-control', 'min' => '1', 'required' => 'required', 'id' => 'edit_term_months']) }}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('first_deduction_month', __('First payroll deduction month'), ['class' => 'form-label']) }}<x-required></x-required>
                        <input type="month" name="first_deduction_month" id="edit_first_deduction_month" class="form-control" value="{{ $loan->first_deduction_month }}" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">{{ __('Expected end date') }}</label>
                        <input type="text" class="form-control bg-white" readonly id="edit_expected_end_display" value="—">
                        <small class="text-muted">{{ __('Last day of month after full term from first deduction month.') }}</small>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="alert alert-info py-2 mb-0">
                        <strong>{{ __('Estimated EMI') }}:</strong> <span id="edit_emi_preview">—</span>
                    </div>
                </div>
            @elseif(!$loan->isInstallment())
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('type', __('Type'), ['class' => 'form-label']) }}<x-required></x-required>
                        {{ Form::select('type', $loans, null, ['class' => 'form-control select amount_type', 'required' => 'required']) }}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('amount', __('Loan Amount'), ['class' => 'form-label']) }}<x-required></x-required>
                        {{ Form::number('amount', null, ['class' => 'form-control', 'required' => 'required', 'step' => '0.01']) }}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('start_date', __('Start date'), ['class' => 'form-label']) }}
                        {{ Form::date('start_date', $loan->start_date ? \Carbon\Carbon::parse($loan->start_date)->format('Y-m-d') : null, ['class' => 'form-control']) }}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        {{ Form::label('end_date', __('End date'), ['class' => 'form-label']) }}
                        {{ Form::date('end_date', $loan->end_date ? \Carbon\Carbon::parse($loan->end_date)->format('Y-m-d') : null, ['class' => 'form-control']) }}
                    </div>
                </div>
            @endif

            <div class="col-md-12">
                <div class="form-group">
                    {{ Form::label('reason', __('Reason'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::textarea('reason', null, ['class' => 'form-control', 'required' => 'required', 'rows' => 3, 'placeholder' => __('Enter Reason')]) }}
                </div>
            </div>
            <div class="col-md-12">
                <div class="form-group">
                    {{ Form::label('chart_account_id', __('Select Account'), ['class' => 'form-label']) }}
                    {{ Form::select('chart_account_id', $chartAccounts, null, ['class' => 'form-control select']) }}
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Update') }}" class="btn btn-primary">
</div>
{{ Form::close() }}

@if($loan->isInstallment() && !$locked)
<script>
(function () {
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function updateExpectedEnd() {
        var monthInput = document.getElementById('edit_first_deduction_month');
        var termEl = document.getElementById('edit_term_months');
        var display = document.getElementById('edit_expected_end_display');
        if (!monthInput || !termEl || !display) return;
        var ym = monthInput.value;
        var n = parseInt(termEl.value || '0', 10);
        if (!ym || n < 1) {
            display.value = '—';
            return;
        }
        var parts = ym.split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10);
        var totalMonths = y * 12 + (m - 1) + n;
        var ey = Math.floor((totalMonths) / 12);
        var em = (totalMonths % 12) + 1;
        var lastDay = new Date(ey, em, 0).getDate();
        display.value = ey + '-' + pad(em) + '-' + pad(lastDay);
    }
    function emi() {
        var P = parseFloat(document.getElementById('edit_principal_amount').value || '0');
        var n = parseInt(document.getElementById('edit_term_months').value || '0', 10);
        var annual = parseFloat(document.getElementById('edit_annual_interest_rate').value || '0');
        var span = document.getElementById('edit_emi_preview');
        if (P <= 0 || n < 1) { span.textContent = '—'; return; }
        var r = (annual / 100) / 12;
        var val = r <= 1e-9 ? P / n : P * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1);
        span.textContent = val.toFixed(2);
        updateExpectedEnd();
    }
    ['edit_principal_amount', 'edit_term_months', 'edit_annual_interest_rate', 'edit_first_deduction_month'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', emi);
        if (el) el.addEventListener('change', emi);
    });
    emi();
})();
</script>
@endif
