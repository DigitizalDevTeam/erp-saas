@extends('layouts.admin')
@section('page-title')
    {{ $receipt ? __('Edit Receipt') : __('New Receipt') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-receipts.index') }}">{{ __('Treasury Receipts') }}</a></li>
    <li class="breadcrumb-item">{{ $receipt ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $receipt ? route('treasury-receipts.update', $receipt->id) : route('treasury-receipts.store') }}">
                        @csrf
                        @if ($receipt)
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="financial_account_id">{{ __('Financial Account') }} *</label>
                                    <select name="financial_account_id" id="financial_account_id" class="form-control" required>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (string) old('financial_account_id', $receipt->operation->financial_account_id ?? '') === (string) $account->id ? 'selected' : '' }}>{{ $account->displayName() }} ({{ $account->currency }})</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('The receipt currency must match the account currency.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="customer_id">{{ __('Customer') }}</label>
                                    <select name="customer_id" id="customer_id" class="form-control">
                                        <option value="">{{ __('No customer — classify below') }}</option>
                                        @foreach ($customers as $customer)
                                            <option value="{{ $customer->id }}" {{ (string) old('customer_id', $receipt->customer_id ?? '') === (string) $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="amount">{{ __('Gross Amount') }} *</label>
                                    <input type="number" step="0.0001" min="0.0001" name="amount" id="amount" class="form-control" value="{{ old('amount', $receipt->operation->amount_original ?? '') }}" required>
                                    <small class="text-muted">{{ __('Gross amount the customer paid, before bank fees and withholding.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="currency">{{ __('Currency') }} *</label>
                                    <select name="currency" id="currency" class="form-control" required>
                                        @foreach ($currencies as $currency)
                                            <option value="{{ $currency }}" {{ old('currency', $receipt->operation->currency ?? 'MZN') === $currency ? 'selected' : '' }}>{{ $currency }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="method">{{ __('Method') }} *</label>
                                    <select name="method" id="method" class="form-control" required>
                                        @foreach ($methods as $value => $label)
                                            <option value="{{ $value }}" {{ old('method', $receipt->method ?? 'transfer') === $value ? 'selected' : '' }}>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="fees">{{ __('Bank Fees') }}</label>
                                    <input type="number" step="0.0001" min="0" name="fees" id="fees" class="form-control" value="{{ old('fees', $receipt->fees ?? '0') }}">
                                    <small class="text-muted">{{ __('Deducted by the bank; posted as its own expense line.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="withholding">{{ __('Withholding') }}</label>
                                    <input type="number" step="0.0001" min="0" name="withholding" id="withholding" class="form-control" value="{{ old('withholding', $receipt->withholding ?? '0') }}">
                                    <small class="text-muted">{{ __('Tax withheld by the customer; posted as its own line.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="discount">{{ __('Discount Granted') }}</label>
                                    <input type="number" step="0.0001" min="0" name="discount" id="discount" class="form-control" value="{{ old('discount', $receipt->discount ?? '0') }}">
                                    <small class="text-muted">{{ __('Added to the settlement value: the invoice is settled by gross + discount.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="posting_date">{{ __('Posting Date') }}</label>
                                    <input type="date" name="posting_date" id="posting_date" class="form-control" value="{{ old('posting_date', $receipt?->operation?->posting_date ? $receipt->operation->posting_date->format('Y-m-d') : date('Y-m-d')) }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="effective_date">{{ __('Effective Date') }}</label>
                                    <input type="date" name="effective_date" id="effective_date" class="form-control" value="{{ old('effective_date', $receipt?->operation?->effective_date ? $receipt->operation->effective_date->format('Y-m-d') : date('Y-m-d')) }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="value_date">{{ __('Value Date') }}</label>
                                    <input type="date" name="value_date" id="value_date" class="form-control" value="{{ old('value_date', $receipt?->value_date ? $receipt->value_date->format('Y-m-d') : date('Y-m-d')) }}">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="exchange_rate">{{ __('Exchange Rate') }}</label>
                                    <input type="number" step="0.0000000001" min="0" name="exchange_rate" id="exchange_rate" class="form-control" value="{{ old('exchange_rate', $receipt->operation->exchange_rate ?? '') }}">
                                    <small class="text-muted">{{ __('Required for foreign currency. Never guessed — confirmation is refused without a rate.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="rate_source">{{ __('Rate Source') }}</label>
                                    <select name="rate_source" id="rate_source" class="form-control">
                                        <option value="">{{ __('—') }}</option>
                                        @foreach (\App\Models\TreasuryOpeningBalance::RATE_SOURCES as $source)
                                            <option value="{{ $source }}" {{ old('rate_source', $receipt->operation->rate_source ?? '') === $source ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $source)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="rate_date">{{ __('Rate Date') }}</label>
                                    <input type="date" name="rate_date" id="rate_date" class="form-control" value="{{ old('rate_date', $receipt?->operation?->rate_date ? $receipt->operation->rate_date->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="external_transaction_id">{{ __('External Transaction ID') }}</label>
                                    <input type="text" name="external_transaction_id" id="external_transaction_id" class="form-control" value="{{ old('external_transaction_id', $receipt->external_transaction_id ?? '') }}" maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="cheque_bank">{{ __('Cheque Bank') }}</label>
                                    <input type="text" name="cheque_bank" id="cheque_bank" class="form-control" value="{{ old('cheque_bank', $receipt->cheque_bank ?? '') }}" maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="cheque_number">{{ __('Cheque Number') }}</label>
                                    <input type="text" name="cheque_number" id="cheque_number" class="form-control" value="{{ old('cheque_number', $receipt->cheque_number ?? '') }}" maxlength="50">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="classification">{{ __('Classification (no-customer receipts)') }}</label>
                                    <input type="text" name="classification" id="classification" class="form-control" value="{{ old('classification', $receipt->classification ?? '') }}" maxlength="50">
                                    <small class="text-muted">{{ __('Required with a reason when the receipt has no customer.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="classification_reason">{{ __('Classification Reason') }}</label>
                                    <input type="text" name="classification_reason" id="classification_reason" class="form-control" value="{{ old('classification_reason', $receipt->classification_reason ?? '') }}">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="evidence">{{ __('Evidence (note / file reference)') }}</label>
                            <input type="text" name="evidence" id="evidence" class="form-control" value="{{ old('evidence', $receipt->evidence ?? '') }}">
                            <small class="text-muted">{{ __('Evidence changes neither cash nor debt until the receipt is confirmed.') }}</small>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $receipt ? __('Save') : __('Create Draft') }}</button>
                        <a href="{{ $receipt ? route('treasury-receipts.show', $receipt) : route('treasury-receipts.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
