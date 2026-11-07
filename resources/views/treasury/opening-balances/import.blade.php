@extends('layouts.admin')
@section('page-title')
    {{ __('Import Opening Balances') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-opening-balances.index') }}">{{ __('Opening Balances') }}</a></li>
    <li class="breadcrumb-item">{{ __('Import') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Upload a CSV file. Columns: code, amount, cut_off_date, currency, exchange_rate, rate_source, rate_date, includes_liabilities, includes_receivables. Every row becomes a draft under one batch reference; nothing is created when any row fails. A batch reference the company already used is refused.') }}</p>
                    <p class="text-muted">{{ __('Example header: code,amount,cut_off_date,currency,exchange_rate,rate_source,rate_date,includes_liabilities,includes_receivables') }}</p>

                    <form method="POST" action="{{ route('treasury-opening-balances.import.store') }}" enctype="multipart/form-data">
                        @csrf

                        <div class="form-group">
                            <label class="form-label" for="batch_reference">{{ __('Batch Reference') }}</label>
                            <input type="text" name="batch_reference" id="batch_reference" class="form-control" maxlength="100">
                            <small class="text-muted">{{ __('Leave empty to generate one.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="file">{{ __('CSV File') }} *</label>
                            <input type="file" name="file" id="file" class="form-control" accept=".csv,.txt" required>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Import') }}</button>
                        <a href="{{ route('treasury-opening-balances.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
