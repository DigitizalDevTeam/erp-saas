@extends('layouts.admin')
@section('page-title')
    {{ __('Hand Over Cash') }} — {{ __('Session') }} #{{ $session->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.index') }}">{{ __('Cash Registers') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.show', $session) }}">{{ __('Session') }} #{{ $session->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Hand Over') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="mb-3">{{ __('Cash on hand:') }} <strong>{{ number_format((float) $session->expected_balance, 2) }} {{ $session->currency }}</strong> {{ __('(opening float + cash inflows − cash outflows, handovers included). The handover moves the money through a transfer to the central account — the transfer confirmation is the receipt.') }}</p>
                    @if ($accounts->isEmpty())
                        <p class="text-muted">{{ __('A handover needs another active account in the same currency. Create one first.') }}</p>
                        <a href="{{ route('treasury-cash-sessions.show', $session) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    @else
                        <form method="POST" action="{{ route('treasury-cash-sessions.handover', $session) }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Destination Account') }} <span class="text-danger">*</span></label>
                                    <select name="destination_account_id" class="form-control" required>
                                        <option value="">{{ __('Select the central account') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (int) old('destination_account_id') === (int) $account->id ? 'selected' : '' }}>{{ $account->displayName() }} — {{ $account->currency }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                                    <input type="number" step="0.0001" min="0.0001" name="amount" class="form-control" value="{{ old('amount', '') }}" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Reason') }}</label>
                                    <input type="text" name="reason" class="form-control" value="{{ old('reason', '') }}" maxlength="1000">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Posting Date') }}</label>
                                    <input type="date" name="posting_date" class="form-control" value="{{ old('posting_date', date('Y-m-d')) }}">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('Hand Over') }}</button>
                            <a href="{{ route('treasury-cash-sessions.show', $session) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
