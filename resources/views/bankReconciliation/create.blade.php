@extends('layouts.admin')
@section('page-title')
    {{ __('Import bank statement') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Bank reconciliation') }}</li>
    <li class="breadcrumb-item">{{ __('Import') }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Upload statement') }}</h5>
                    <p class="text-muted small mb-0">
                        {{ __('Choose the ERP bank account, a format preset (BCI or Standard Bank), or Custom to map columns yourself. Amounts are matched to Transaction records for that account.') }}
                    </p>
                </div>
                <div class="card-body">
                    {{ Form::open(['route' => 'bank-reconciliation.store', 'method' => 'post', 'files' => true]) }}
                    @csrf
                    <div class="mb-3">
                        {{ Form::label('bank_account_id', __('Bank account'), ['class' => 'form-label']) }}
                        {{ Form::select('bank_account_id', $bankAccountOptions, null, ['class' => 'form-control', 'required' => true]) }}
                    </div>
                    <div class="mb-3">
                        {{ Form::label('preset_key', __('Format preset'), ['class' => 'form-label']) }}
                        {{ Form::select('preset_key', $presets->pluck('label', 'key'), 'bci_mozambique', ['class' => 'form-control', 'id' => 'preset_key']) }}
                    </div>
                    <div class="mb-3">
                        {{ Form::label('statement', __('Excel / CSV file'), ['class' => 'form-label']) }}
                        {{ Form::file('statement', ['class' => 'form-control', 'required' => true, 'accept' => '.xlsx,.xls,.csv,.txt']) }}
                    </div>
                    <div class="mb-3">
                        {{ Form::label('date_tolerance_days', __('Date tolerance (days) for auto-match'), ['class' => 'form-label']) }}
                        {{ Form::number('date_tolerance_days', $defaultTolerance, ['class' => 'form-control', 'min' => 0, 'max' => 30]) }}
                    </div>

                    <div id="custom_mapping" class="border rounded p-3 mb-3 d-none">
                        <h6>{{ __('Custom column mapping (0-based column index)') }}</h6>
                        <p class="small text-muted">{{ __('First row number is 1 for the top row of the file.') }}</p>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                {{ Form::label('first_data_row', __('First data row #'), ['class' => 'form-label']) }}
                                {{ Form::number('first_data_row', 2, ['class' => 'form-control', 'min' => 1]) }}
                            </div>
                            <div class="col-md-6 mb-2">
                                {{ Form::label('amount_mode', __('Amount layout'), ['class' => 'form-label']) }}
                                {{ Form::select('amount_mode', ['signed_column' => __('Single signed amount column'), 'debit_credit' => __('Separate debit & credit columns')], 'signed_column', ['class' => 'form-control', 'id' => 'amount_mode']) }}
                            </div>
                            <div class="col-md-4 mb-2">
                                {{ Form::label('date_col', __('Date column index'), ['class' => 'form-label']) }}
                                {{ Form::number('date_col', 0, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                            <div class="col-md-4 mb-2">
                                {{ Form::label('description_col', __('Description column index'), ['class' => 'form-label']) }}
                                {{ Form::number('description_col', 1, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                            <div class="col-md-4 mb-2" id="wrap_amount_col">
                                {{ Form::label('amount_col', __('Amount column index'), ['class' => 'form-label']) }}
                                {{ Form::number('amount_col', 2, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                            <div class="col-md-4 mb-2 d-none" id="wrap_debit">
                                {{ Form::label('debit_col', __('Debit column index'), ['class' => 'form-label']) }}
                                {{ Form::number('debit_col', 2, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                            <div class="col-md-4 mb-2 d-none" id="wrap_credit">
                                {{ Form::label('credit_col', __('Credit column index'), ['class' => 'form-label']) }}
                                {{ Form::number('credit_col', 3, ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                            <div class="col-md-4 mb-2">
                                {{ Form::label('balance_col', __('Balance column (optional)'), ['class' => 'form-label']) }}
                                {{ Form::number('balance_col', '', ['class' => 'form-control', 'min' => 0]) }}
                            </div>
                        </div>
                    </div>

                    {{ Form::submit(__('Import & match'), ['class' => 'btn btn-primary']) }}
                    <a href="{{ route('bank-reconciliation.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
@push('script-page')
    <script>
        (function() {
            var preset = document.getElementById('preset_key');
            var custom = document.getElementById('custom_mapping');
            var mode = document.getElementById('amount_mode');

            function toggleCustom() {
                if (!preset || !custom) return;
                custom.classList.toggle('d-none', preset.value !== 'custom');
            }

            function toggleDebitCredit() {
                if (!mode) return;
                var dc = mode.value === 'debit_credit';
                document.getElementById('wrap_amount_col').classList.toggle('d-none', dc);
                document.getElementById('wrap_debit').classList.toggle('d-none', !dc);
                document.getElementById('wrap_credit').classList.toggle('d-none', !dc);
            }
            if (preset) preset.addEventListener('change', toggleCustom);
            if (mode) mode.addEventListener('change', toggleDebitCredit);
            toggleCustom();
            toggleDebitCredit();
        })();
    </script>
@endpush
