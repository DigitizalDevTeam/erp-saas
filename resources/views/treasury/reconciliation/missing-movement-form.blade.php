@extends('layouts.admin')
@section('page-title')
    {{ __('Propose missing movement') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bank-reconciliation.index') }}">{{ __('Bank reconciliation') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bank-reconciliation.show', $session) }}">#{{ $session->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Missing movement') }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="mb-1"><strong>{{ __('Statement line') }}:</strong>
                        {{ $line->txn_date ? $line->txn_date->format('Y-m-d') . ' — ' : '' }}{{ $line->description ?? '' }}</p>
                    <p class="mb-3"><strong>{{ __('Amount') }}:</strong> {{ $line->amount !== null ? number_format((float) $line->amount, 2) : '—' }}</p>
                    <p class="text-muted">{{ __('This item is on the bank statement but not in the books — a bank fee, interest, or another charge. Proposing it records it for an approver; nothing posts until it is confirmed.') }}</p>

                    {{ Form::open(['route' => ['treasury-reconciliation.missing-movement-propose', $session, $line], 'method' => 'post']) }}
                    @csrf
                    <div class="form-group mb-3">
                        {{ Form::label('category', __('Category'), ['class' => 'form-label']) }}
                        {{ Form::select('category', array_combine($categories, $categories), old('category', 'fee'), ['class' => 'form-control']) }}
                    </div>
                    <div class="form-group mb-3">
                        {{ Form::label('offset_account_id', __('Offset chart account'), ['class' => 'form-label']) }}
                        {{ Form::select('offset_account_id', ['' => __('— Select —')] + $accounts->mapWithKeys(fn ($a) => [$a->id => $a->code . ' — ' . $a->name])->all(), old('offset_account_id'), ['class' => 'form-control']) }}
                        <small class="text-muted">{{ __('The fee / interest expense (or income) account the journal debits or credits. Money out: Dr this account / Cr bank. Money in: Dr bank / Cr this account.') }}</small>
                    </div>
                    <div class="form-group mb-3">
                        {{ Form::label('amount', __('Amount (blank = the statement line amount)'), ['class' => 'form-label']) }}
                        {{ Form::number('amount', old('amount'), ['class' => 'form-control', 'step' => '0.01']) }}
                    </div>
                    <div class="form-group mb-3">
                        {{ Form::label('exchange_rate', __('Exchange rate to base (only when the account currency is not the base currency)'), ['class' => 'form-label']) }}
                        {{ Form::number('exchange_rate', old('exchange_rate'), ['class' => 'form-control', 'step' => '0.0000000001']) }}
                    </div>
                    <div class="form-group mb-3">
                        {{ Form::label('reason', __('Reason'), ['class' => 'form-label']) }}
                        {{ Form::text('reason', old('reason'), ['class' => 'form-control']) }}
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('Propose') }}</button>
                    <a href="{{ route('bank-reconciliation.show', $session) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
