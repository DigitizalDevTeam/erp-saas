@extends('layouts.admin')
@section('page-title')
    {{ $account ? __('Edit Financial Account') : __('Create Financial Account') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-accounts.index') }}">{{ __('Treasury Accounts') }}</a></li>
    <li class="breadcrumb-item">{{ $account ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $account ? route('treasury-accounts.update', $account->id) : route('treasury-accounts.store') }}">
                        @csrf
                        @if ($account)
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="code">{{ __('Code') }} *</label>
                                    <input type="text" name="code" id="code" class="form-control" value="{{ old('code', $account->code ?? '') }}" maxlength="50" required>
                                    <small class="text-muted">{{ __('Unique per company. Never reused.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="type">{{ __('Type') }} *</label>
                                    <select name="type" id="type" class="form-control" required>
                                        @foreach (\App\Models\TreasuryFinancialAccount::TYPE_LABELS as $value => $label)
                                            <option value="{{ $value }}" {{ old('type', $account->type ?? 'bank') === $value ? 'selected' : '' }}>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="currency">{{ __('Currency') }} *</label>
                                    <select name="currency" id="currency" class="form-control" required {{ $account && $account->currency_locked ? 'disabled' : '' }}>
                                        @foreach ($currencies as $currency)
                                            <option value="{{ $currency }}" {{ old('currency', $account->currency ?? $baseCurrency) === $currency ? 'selected' : '' }}>{{ $currency }}</option>
                                        @endforeach
                                    </select>
                                    @if ($account && $account->currency_locked)
                                        <input type="hidden" name="currency" value="{{ $account->currency }}">
                                        <small class="text-muted">{{ __('Currency is locked: the account already has a confirmed movement.') }}</small>
                                    @else
                                        <small class="text-muted">{{ __('Currency locks after the first confirmed movement.') }}</small>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="account_holder">{{ __('Account Holder') }} *</label>
                                    <input type="text" name="account_holder" id="account_holder" class="form-control" value="{{ old('account_holder', $account->bankAccount->holder_name ?? '') }}" required>
                                    <small class="text-muted">{{ __('Changing the holder is recorded in the audit trail.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="institution">{{ __('Institution / Bank') }} *</label>
                                    <input type="text" name="institution" id="institution" class="form-control" value="{{ old('institution', $account->bankAccount->bank_name ?? '') }}" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="account_number">{{ __('Account Number') }} *</label>
                                    <input type="text" name="account_number" id="account_number" class="form-control" value="{{ old('account_number', $account->bankAccount->account_number ?? '') }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="nib_or_iban">{{ __('NIB / IBAN') }}</label>
                                    <input type="text" name="nib_or_iban" id="nib_or_iban" class="form-control" value="{{ old('nib_or_iban', $account->nib_or_iban ?? '') }}" maxlength="50">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="branch_id">{{ __('Branch') }}</label>
                                    <select name="branch_id" id="branch_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" {{ (string) old('branch_id', $account->branch_id ?? '') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="owner_user_id">{{ __('Owner') }}</label>
                                    <select name="owner_user_id" id="owner_user_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($users as $user)
                                            <option value="{{ $user->id }}" {{ (string) old('owner_user_id', $account->owner_user_id ?? '') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }} ({{ $user->email }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="chart_account_id">{{ __('Chart of Account') }}</label>
                                    <select name="chart_account_id" id="chart_account_id" class="form-control">
                                        <option value="0">{{ __('Select Account') }}</option>
                                        @foreach ($chartAccounts as $id => $label)
                                            <option value="{{ $id }}" {{ (string) old('chart_account_id', $account->bankAccount->chart_account_id ?? 0) === (string) $id ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="cash_limit">{{ __('Cash Limit') }}</label>
                                    <input type="number" step="0.0001" min="0" name="cash_limit" id="cash_limit" class="form-control" value="{{ old('cash_limit', $account->cash_limit ?? '') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="minimum_balance">{{ __('Minimum Balance') }}</label>
                                    <input type="number" step="0.0001" name="minimum_balance" id="minimum_balance" class="form-control" value="{{ old('minimum_balance', $account->minimum_balance ?? '') }}">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="negative_balance_policy">{{ __('Negative-Balance Policy') }}</label>
                                    <select name="negative_balance_policy" id="negative_balance_policy" class="form-control">
                                        @foreach (['block' => __('Block'), 'allow' => __('Allow'), 'approval' => __('Approval')] as $value => $label)
                                            <option value="{{ $value }}" {{ old('negative_balance_policy', $account->negative_balance_policy ?? 'block') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('Block is the default. Allow needs a named limit and approver.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="negative_balance_limit">{{ __('Negative-Balance Limit') }}</label>
                                    <input type="number" step="0.0001" min="0" name="negative_balance_limit" id="negative_balance_limit" class="form-control" value="{{ old('negative_balance_limit', $account->negative_balance_limit ?? '') }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="negative_balance_approver_user_id">{{ __('Negative-Balance Approver') }}</label>
                                    <select name="negative_balance_approver_user_id" id="negative_balance_approver_user_id" class="form-control">
                                        <option value="">{{ __('None (company user)') }}</option>
                                        @foreach ($users as $user)
                                            <option value="{{ $user->id }}" {{ (string) old('negative_balance_approver_user_id', $account->negative_balance_approver_user_id ?? '') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }} ({{ $user->email }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="contact_number">{{ __('Contact Number') }}</label>
                                    <input type="text" name="contact_number" id="contact_number" class="form-control" value="{{ old('contact_number', $account->bankAccount->contact_number ?? '') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="bank_address">{{ __('Bank Address') }}</label>
                                    <input type="text" name="bank_address" id="bank_address" class="form-control" value="{{ old('bank_address', $account->bankAccount->bank_address ?? '') }}">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $account ? __('Update') : __('Create') }}</button>
                        <a href="{{ route('treasury-accounts.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
