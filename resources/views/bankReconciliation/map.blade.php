@extends('layouts.admin')
@section('page-title')
    {{ __('Map columns') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bank-reconciliation.index') }}">{{ __('Bank reconciliation') }}</a></li>
    <li class="breadcrumb-item">{{ __('Map columns') }} #{{ $session->id }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card mb-3">
                <div class="card-body py-3">
                    <strong>{{ __('File') }}:</strong> {{ $session->original_filename }}<br>
                    <strong>{{ __('Bank account') }}:</strong>
                    {{ $session->bankAccount ? $session->bankAccount->bank_name . ' — ' . $session->bankAccount->holder_name : '-' }}
                </div>
            </div>

            @if ($mappingSource === 'detected' && $mappingSourceLabel)
                <div class="alert alert-info mb-3">
                    {{ __('Columns detected automatically from the file layout (:label). Check them below, then import.', ['label' => $mappingSourceLabel]) }}
                    <a href="{{ route('bank-reconciliation.map', ['bank_reconciliation_session' => $session, 'reset' => 1]) }}">{{ __('Start from scratch') }}</a>
                </div>
            @elseif ($mappingSource === 'saved')
                <div class="alert alert-info mb-3">
                    {{ __('Loaded your saved mapping for this bank account. Check it below, then import.') }}
                    <a href="{{ route('bank-reconciliation.map', ['bank_reconciliation_session' => $session, 'reset' => 1]) }}">{{ __('Start from scratch') }}</a>
                </div>
            @endif

            @if ($suggestionKeys->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">{{ __('Optional: load a suggested mapping') }}</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-2">{{ __('If your file looks like a known layout, try a shortcut. You can still edit every value below.') }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($suggestionKeys as $key)
                                <a href="{{ route('bank-reconciliation.map', ['bank_reconciliation_session' => $session, 'suggest' => $key]) }}"
                                    class="btn btn-sm btn-outline-secondary">{{ config('bank_reconciliation.presets.' . $key . '.label', $key) }}</a>
                            @endforeach
                            <a href="{{ route('bank-reconciliation.map', ['bank_reconciliation_session' => $session, 'reset' => 1]) }}"
                                class="btn btn-sm btn-outline-primary">{{ __('Start from scratch') }}</a>
                        </div>
                    </div>
                </div>
            @endif

            <div class="card mb-3">
                <div class="card-header">
                    <h5>{{ __('Preview (first rows)') }}</h5>
                    <p class="text-muted small mb-0">{{ __('Column indexes are 0-based: first column = 0, second = 1, …') }}</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow: auto;">
                        <table class="table table-sm table-bordered mb-0" style="font-size: 0.8rem;">
                            @foreach ($previewRows as $r => $row)
                                <tr>
                                    @foreach ($row as $c => $cell)
                                        <td class="text-nowrap">{{ $cell !== null && $cell !== '' ? \Illuminate\Support\Str::limit((string) $cell, 40) : '' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Step 2: Column mapping') }}</h5>
                    <p class="text-muted small mb-0">{{ __('Pick each column by its header name. The number in front is the column position (0 = first column).') }}</p>
                </div>
                <div class="card-body">
                    {{ Form::open(['route' => ['bank-reconciliation.apply-mapping', $session], 'method' => 'post']) }}
                    @csrf
                    @if ($suggestedFound && $suggestKey)
                        {{ Form::hidden('source_preset', $suggestKey) }}
                    @endif

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            {{ Form::label('first_data_row', __('First data row (1 = top row of file)') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::number('first_data_row', old('first_data_row', $defaults['first_data_row']), ['class' => 'form-control', 'required' => true, 'min' => 1]) }}
                            <small class="form-text text-muted">{{ __('Row where the real transactions start. Everything above it (account info, blank rows, headers) is skipped.') }}</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            {{ Form::label('amount_mode', __('Amount layout') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::select('amount_mode', ['signed_column' => __('One signed amount column (+ / −)'), 'debit_credit' => __('Separate debit & credit columns')], old('amount_mode', $defaults['amount_mode']), ['class' => 'form-control', 'id' => 'amount_mode']) }}
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            {{ Form::label('date_col', __('Date column') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::select('date_col', $headerOptions, old('date_col', $defaults['date_col']), ['class' => 'form-control', 'required' => true]) }}
                            <small class="form-text text-muted">{{ __('Column with the transaction date.') }}</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            {{ Form::label('description_col', __('Description column') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::select('description_col', $headerOptions, old('description_col', $defaults['description_col']), ['class' => 'form-control', 'required' => true]) }}
                            <small class="form-text text-muted">{{ __('Column with the text that describes each line.') }}</small>
                        </div>
                        <div class="col-md-4 mb-3" id="wrap_amount_col">
                            {{ Form::label('amount_col', __('Amount column') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::select('amount_col', $headerOptions, old('amount_col', $defaults['amount_col']), ['class' => 'form-control']) }}
                            <small class="form-text text-muted">{{ __('Column with the amount. A + sign means money in, a − sign means money out.') }}</small>
                        </div>
                        <div class="col-md-4 mb-3 d-none" id="wrap_debit">
                            {{ Form::label('debit_col', __('Debit column') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::select('debit_col', $headerOptions, old('debit_col', $defaults['debit_col']), ['class' => 'form-control']) }}
                            <small class="form-text text-muted">{{ __('Column with money out.') }}</small>
                        </div>
                        <div class="col-md-4 mb-3 d-none" id="wrap_credit">
                            {{ Form::label('credit_col', __('Credit column') . ' ' . __('(required)'), ['class' => 'form-label']) }}
                            {{ Form::select('credit_col', $headerOptions, old('credit_col', $defaults['credit_col']), ['class' => 'form-control']) }}
                            <small class="form-text text-muted">{{ __('Column with money in.') }}</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            {{ Form::label('balance_col', __('Balance column') . ' ' . __('(optional)'), ['class' => 'form-label']) }}
                            {{ Form::select('balance_col', ['' => __('No balance column')] + $headerOptions, old('balance_col', $defaults['balance_col']), ['class' => 'form-control']) }}
                            <small class="form-text text-muted">{{ __('Optional. Column with the running balance after each line. Used for the statement balance when closing.') }}</small>
                        </div>
                    </div>

                    {{ Form::submit(__('Import & run auto-match'), ['class' => 'btn btn-primary']) }}
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
            var mode = document.getElementById('amount_mode');

            function toggleDebitCredit() {
                if (!mode) return;
                var dc = mode.value === 'debit_credit';
                document.getElementById('wrap_amount_col').classList.toggle('d-none', dc);
                document.getElementById('wrap_debit').classList.toggle('d-none', !dc);
                document.getElementById('wrap_credit').classList.toggle('d-none', !dc);
            }
            if (mode) mode.addEventListener('change', toggleDebitCredit);
            toggleDebitCredit();
        })();
    </script>
@endpush
