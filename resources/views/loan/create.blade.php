{{ Form::open(['url' => 'loan', 'method' => 'post', 'class' => 'needs-validation', 'novalidate', 'id' => 'loan-create-form']) }}
{{ Form::hidden('employee_id', $employee->id, []) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group col-md-12 mb-3">
            {{ Form::label('schedule_mode', __('Loan program'), ['class' => 'form-label']) }}<x-required></x-required>
            <select name="schedule_mode" id="schedule_mode" class="form-control" required>
                <option value="{{ \App\Models\Loan::SCHEDULE_INSTALLMENT }}">{{ __('Term loan (principal + monthly EMI, reducing balance)') }}</option>
                <option value="{{ \App\Models\Loan::SCHEDULE_LEGACY }}">{{ __('Legacy — fixed amount or % of salary every month') }}</option>
            </select>
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('title', __('Title'), ['class' => 'form-label']) }}<x-required></x-required>
            {{ Form::text('title', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Enter Title')]) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('loan_option', __('Loan Options'), ['class' => 'form-label']) }}<x-required></x-required>
            {{ Form::select('loan_option', $loan_options, null, ['class' => 'form-control select', 'required' => 'required']) }}
            <div class="text-xs mt-1">
                {{ __('Create loan option here.') }} <a href="{{ route('loanoption.index') }}"><b>{{ __('Create loan option') }}</b></a>
            </div>
        </div>

        <div id="legacy-loan-fields" class="row px-0" style="display:none;">
            <div class="form-group col-md-6">
                {{ Form::label('type', __('Type'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::select('type', $loan, null, ['class' => 'form-control select amount_type', 'id' => 'legacy_type']) }}
            </div>
            <div class="form-group col-md-6">
                {{ Form::label('amount', __('Amount / Percent'), ['class' => 'form-label amount_label']) }}<x-required></x-required>
                {{ Form::number('amount', null, ['class' => 'form-control', 'step' => '0.01', 'id' => 'legacy_amount', 'placeholder' => __('Enter Amount')]) }}
            </div>
        </div>

        <div id="installment-loan-fields" class="row px-0">
            <div class="form-group col-md-6">
                {{ Form::label('principal_amount', __('Principal amount'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::number('principal_amount', null, ['class' => 'form-control', 'step' => '0.01', 'id' => 'principal_amount', 'placeholder' => __('Total disbursed')]) }}
            </div>
            <div class="form-group col-md-6">
                {{ Form::label('annual_interest_rate', __('Annual interest rate (%)'), ['class' => 'form-label']) }}
                {{ Form::number('annual_interest_rate', 0, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0', 'id' => 'annual_interest_rate', 'placeholder' => __('0 = interest-free')]) }}
                <small class="text-muted">{{ __('Reducing balance; use 0 for simple equal principal installments.') }}</small>
            </div>
            <div class="form-group col-md-6">
                {{ Form::label('term_months', __('Term (months)'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::number('term_months', null, ['class' => 'form-control', 'min' => '1', 'id' => 'term_months']) }}
            </div>
            <div class="form-group col-md-6">
                {{ Form::label('first_deduction_month', __('First payroll deduction month'), ['class' => 'form-label']) }}<x-required></x-required>
                <input type="month" name="first_deduction_month" id="first_deduction_month" class="form-control" value="{{ date('Y-m') }}" required>
            </div>
            <div class="form-group col-md-12">
                <div class="alert alert-info mb-0 py-2">
                    <strong>{{ __('Estimated EMI') }}:</strong>
                    <span id="emi_preview">—</span>
                </div>
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('reason', __('Reason'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::textarea('reason', null, ['class' => 'form-control', 'required' => 'required', 'rows' => 3, 'placeholder' => __('Enter Reason')]) }}
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Create') }}" class="btn btn-primary">
</div>
{{ Form::close() }}

<script>
(function () {
    function toggleSchedule() {
        var mode = document.getElementById('schedule_mode').value;
        var leg = document.getElementById('legacy-loan-fields');
        var ins = document.getElementById('installment-loan-fields');
        var installment = mode === '{{ \App\Models\Loan::SCHEDULE_INSTALLMENT }}';
        leg.style.display = installment ? 'none' : 'flex';
        ins.style.display = installment ? 'flex' : 'none';
        document.getElementById('legacy_amount').required = !installment;
        document.getElementById('legacy_type').required = !installment;
        document.getElementById('principal_amount').required = installment;
        document.getElementById('term_months').required = installment;
        document.getElementById('first_deduction_month').required = installment;
        updateEmiPreview();
    }
    function updateEmiPreview() {
        var mode = document.getElementById('schedule_mode').value;
        var span = document.getElementById('emi_preview');
        if (mode !== '{{ \App\Models\Loan::SCHEDULE_INSTALLMENT }}') {
            span.textContent = '—';
            return;
        }
        var P = parseFloat(document.getElementById('principal_amount').value || '0');
        var n = parseInt(document.getElementById('term_months').value || '0', 10);
        var annual = parseFloat(document.getElementById('annual_interest_rate').value || '0');
        if (P <= 0 || n < 1) {
            span.textContent = '—';
            return;
        }
        var r = (annual / 100) / 12;
        var emi;
        if (r <= 1e-9) {
            emi = P / n;
        } else {
            var pow = Math.pow(1 + r, n);
            emi = P * r * pow / (pow - 1);
        }
        span.textContent = emi.toFixed(2);
    }
    document.getElementById('schedule_mode').addEventListener('change', toggleSchedule);
    ['principal_amount', 'term_months', 'annual_interest_rate'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', updateEmiPreview);
    });
    toggleSchedule();
})();
</script>
