@extends('layouts.admin')
@section('page-title')
    {{ __('Import bank statement') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Bank reconciliation') }}</li>
    <li class="breadcrumb-item">{{ __('Upload') }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Step 1: Upload file') }}</h5>
                    <p class="text-muted small mb-0">
                        {{ __('Upload any bank Excel or CSV. On the next screen you will map which columns are date, amount, and description — no bank-specific setup is required. Optional shortcuts can fill a suggested mapping.') }}
                    </p>
                </div>
                <div class="card-body">
                    {{ Form::open(['route' => 'bank-reconciliation.store', 'method' => 'post', 'files' => true]) }}
                    @csrf
                    <div class="mb-3">
                        {{ Form::label('bank_account_id', __('ERP bank account'), ['class' => 'form-label']) }}
                        {{ Form::select('bank_account_id', $bankAccountOptions, null, ['class' => 'form-control', 'required' => true]) }}
                    </div>
                    <div class="mb-3">
                        {{ Form::label('statement', __('Excel / CSV file'), ['class' => 'form-label']) }}
                        {{ Form::file('statement', ['class' => 'form-control', 'required' => true, 'accept' => '.xlsx,.xls,.csv,.txt']) }}
                    </div>
                    <div class="mb-3">
                        {{ Form::label('date_tolerance_days', __('Date tolerance (days) for auto-match'), ['class' => 'form-label']) }}
                        {{ Form::number('date_tolerance_days', $defaultTolerance, ['class' => 'form-control', 'min' => 0, 'max' => 30]) }}
                    </div>

                    {{ Form::submit(__('Continue to column mapping'), ['class' => 'btn btn-primary']) }}
                    <a href="{{ route('bank-reconciliation.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
