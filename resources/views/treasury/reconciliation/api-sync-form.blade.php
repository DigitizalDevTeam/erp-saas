@extends('layouts.admin')
@section('page-title')
    {{ __('API statement sync') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bank-reconciliation.index') }}">{{ __('Bank reconciliation') }}</a></li>
    <li class="breadcrumb-item">{{ __('API sync') }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Import statement rows fetched from a provider API. Upload a JSON file with a "rows" list — each row needs external_id, txn_date and amount (balance is optional) — and an optional "next_cursor". Rows already seen for this account are skipped; the sync cursor advances so a re-run resumes and never re-imports a line.') }}</p>

                    {{ Form::open(['route' => 'treasury-reconciliation.api-sync', 'method' => 'post', 'files' => true]) }}
                    @csrf
                    <div class="form-group mb-3">
                        {{ Form::label('bank_account_id', __('Bank account'), ['class' => 'form-label']) }}
                        {{ Form::select('bank_account_id', ['' => __('— Select —')] + $accounts->mapWithKeys(fn ($a) => [$a->id => $a->bank_name . ' — ' . $a->holder_name . ($a->account_number ? ' (' . $a->account_number . ')' : '')])->all(), old('bank_account_id'), ['class' => 'form-control']) }}
                    </div>
                    <div class="form-group mb-3">
                        {{ Form::label('source_key', __('Source key'), ['class' => 'form-label']) }}
                        {{ Form::text('source_key', old('source_key', 'provider'), ['class' => 'form-control']) }}
                        <small class="text-muted">{{ __('Identifies the provider feed, for example "bim-api". One cursor is kept per bank account + source.') }}</small>
                    </div>
                    <div class="form-group mb-3">
                        {{ Form::label('rows_file', __('Rows file (JSON)'), ['class' => 'form-label']) }}
                        {{ Form::file('rows_file', ['class' => 'form-control']) }}
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('Sync') }}</button>
                    <a href="{{ route('bank-reconciliation.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
