@extends('layouts.admin')
@section('page-title')
    {{ $fund ? __('Edit Petty-Cash Fund') : __('New Petty-Cash Fund') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-petty-cash.index') }}">{{ __('Petty Cash') }}</a></li>
    <li class="breadcrumb-item">{{ $fund ? __('Edit') : __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $fund ? route('treasury-petty-cash.update', $fund) : route('treasury-petty-cash.store') }}">
                        @csrf
                        @if ($fund)
                            @method('PUT')
                        @endif
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Financial Account') }} <span class="text-danger">*</span></label>
                                @if ($fund)
                                    <input type="text" class="form-control" value="{{ $fund->financialAccount ? $fund->financialAccount->displayName() : '—' }}" disabled>
                                    <small class="text-muted">{{ __('The account never changes — create a new fund to move the float.') }}</small>
                                @else
                                    <select name="financial_account_id" class="form-control" required>
                                        <option value="">{{ __('Select an account') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (int) old('financial_account_id') === (int) $account->id ? 'selected' : '' }}>{{ $account->displayName() }} — {{ $account->currency }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Custodian') }} <span class="text-danger">*</span></label>
                                <select name="custodian_user_id" class="form-control" required>
                                    <option value="">{{ __('Select the custodian') }}</option>
                                    @foreach ($custodians as $custodian)
                                        <option value="{{ $custodian->id }}" {{ (int) old('custodian_user_id', $fund->custodian_user_id ?? 0) === (int) $custodian->id ? 'selected' : '' }}>{{ $custodian->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Limit (target float)') }}</label>
                                <input type="number" step="0.0001" min="0" name="limit_amount" class="form-control" value="{{ old('limit_amount', $fund->limit_amount ?? '') }}">
                                <small class="text-muted">{{ __('Empty means no target — nothing is ever due for replenishment.') }}</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Replenishment Frequency') }}</label>
                                <select name="replenishment_frequency" class="form-control">
                                    @foreach (\App\Models\TreasuryPettyCashFund::FREQUENCY_LABELS as $value => $label)
                                        <option value="{{ $value }}" {{ old('replenishment_frequency', $fund->replenishment_frequency ?? 'on_demand') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Branch') }}</label>
                                <input type="number" name="branch_id" class="form-control" value="{{ old('branch_id', $fund->branch_id ?? '') }}">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ $fund ? __('Update Fund') : __('Create Fund') }}</button>
                        <a href="{{ route('treasury-petty-cash.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
