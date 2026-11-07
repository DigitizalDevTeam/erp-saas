@extends('layouts.admin')
@section('page-title')
    {{ __('Return Unspent Money') }} {{ $advance->operation->reference ?? '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.index') }}">{{ __('Advances') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.show', $advance) }}">{{ $advance->operation->reference ?? __('Draft') }}</a></li>
    <li class="breadcrumb-item">{{ __('Return Unspent') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-3">
                        <dt class="col-sm-4">{{ __('Recipient') }}</dt>
                        <dd class="col-sm-8">{{ $advance->recipientLabel() }}</dd>
                        <dt class="col-sm-4">{{ __('Outstanding') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $advance->amount_outstanding, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Source Account') }}</dt>
                        <dd class="col-sm-8">{{ $advance->sourceAccount ? $advance->sourceAccount->code . ' — ' . $advance->sourceAccount->displayName() : '—' }}</dd>
                    </dl>

                    <form method="POST" action="{{ route('treasury-advances.return-unspent', $advance) }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="amount">{{ __('Amount to Return') }} *</label>
                            <input type="number" step="0.0001" min="0.0001" max="{{ $advance->amount_outstanding }}" name="amount" id="amount" class="form-control" value="{{ old('amount', $advance->amount_outstanding) }}" required>
                            <small class="text-muted">{{ __('Cannot exceed the outstanding amount. Returning the full remainder regularises the advance.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="reason">{{ __('Reason') }}</label>
                            <input type="text" name="reason" id="reason" class="form-control" maxlength="1000" value="{{ old('reason') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="posting_date">{{ __('Posting Date') }}</label>
                            <input type="date" name="posting_date" id="posting_date" class="form-control" value="{{ date('Y-m-d') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="adjustment_authorised_by">{{ __('Closed-Period Adjustment — Authorised By') }}</label>
                            <select name="adjustment_authorised_by" id="adjustment_authorised_by" class="form-control">
                                <option value="">{{ __('Not an adjustment') }}</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Only fill this in when the intended posting date is in a closed period. The authoriser must be a different user who holds the manage treasury advances permission.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="adjustment_reason">{{ __('Adjustment Reason') }}</label>
                            <input type="text" name="adjustment_reason" id="adjustment_reason" class="form-control" maxlength="1000">
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Return Money') }}</button>
                        <a href="{{ route('treasury-advances.show', $advance) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
