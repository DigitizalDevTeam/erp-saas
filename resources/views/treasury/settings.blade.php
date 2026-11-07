@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Settings') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-accounts.index') }}">{{ __('Treasury Accounts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Settings') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('treasury.settings.save') }}">
                        @csrf

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_base_currency">{{ __('Base Currency') }}</label>
                                    <input type="text" name="treasury_base_currency" id="treasury_base_currency" class="form-control" value="{{ old('treasury_base_currency', $settings['treasury_base_currency'] ?? 'MZN') }}" maxlength="3" required>
                                    <small class="text-muted">{{ __('Company base currency (MZN). Every operation stores its original amount, base amount, rate, rate source and rate date.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_reference_prefix">{{ __('Document Reference Prefix') }}</label>
                                    <input type="text" name="treasury_reference_prefix" id="treasury_reference_prefix" class="form-control" value="{{ old('treasury_reference_prefix', $settings['treasury_reference_prefix'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Prefix used in Treasury references ({COMPANY}-{TYPE}-{YEAR}-{SEQUENCE}).') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="treasury_negative_balance_policy">{{ __('Negative-Balance Policy (default for new accounts)') }}</label>
                            <select name="treasury_negative_balance_policy" id="treasury_negative_balance_policy" class="form-control">
                                @foreach (['block' => __('Block'), 'allow' => __('Allow'), 'approval' => __('Approval')] as $value => $label)
                                    <option value="{{ $value }}" {{ ($settings['treasury_negative_balance_policy'] ?? 'block') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Block is the default. Allow needs a named account limit and approver; approval routes over-limit items to the approver.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="treasury_reservation_policy">{{ __('Fund Reservation Policy') }}</label>
                            <select name="treasury_reservation_policy" id="treasury_reservation_policy" class="form-control">
                                @foreach (['off' => __('Off'), 'on' => __('On')] as $value => $label)
                                    <option value="{{ $value }}" {{ ($settings['treasury_reservation_policy'] ?? 'off') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Reservations are created when a payment is approved (Phase 2). Available balance = posted balance − active reservations.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="treasury_approval_default">{{ __('Approval Default') }}</label>
                            <select name="treasury_approval_default" id="treasury_approval_default" class="form-control">
                                @foreach (['none' => __('None'), 'manager' => __('Manager'), 'finance_controller' => __('Finance controller')] as $value => $label)
                                    <option value="{{ $value }}" {{ ($settings['treasury_approval_default'] ?? 'manager') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_retention_months">{{ __('Retention (months)') }}</label>
                                    <input type="number" min="1" name="treasury_retention_months" id="treasury_retention_months" class="form-control" value="{{ old('treasury_retention_months', $settings['treasury_retention_months'] ?? '60') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_forecast_horizons">{{ __('Forecast Horizons (days)') }}</label>
                                    <input type="text" name="treasury_forecast_horizons" id="treasury_forecast_horizons" class="form-control" value="{{ old('treasury_forecast_horizons', $settings['treasury_forecast_horizons'] ?? '7,15,30,90') }}">
                                    <small class="text-muted">{{ __('Comma-separated day counts, e.g. 7,15,30,90.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="treasury_notification_channels">{{ __('Notification Channels') }}</label>
                            <select name="treasury_notification_channels" id="treasury_notification_channels" class="form-control">
                                @foreach (['email' => __('Email'), 'none' => __('None')] as $value => $label)
                                    <option value="{{ $value }}" {{ ($settings['treasury_notification_channels'] ?? 'email') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_statement_date_tolerance_days">{{ __('Statement Import: Date Tolerance (days)') }}</label>
                                    <input type="number" min="0" name="treasury_statement_date_tolerance_days" id="treasury_statement_date_tolerance_days" class="form-control" value="{{ old('treasury_statement_date_tolerance_days', $settings['treasury_statement_date_tolerance_days'] ?? '3') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <div class="form-check form-switch" style="margin-top: 2rem;">
                                        <input class="form-check-input" type="checkbox" name="treasury_statement_auto_match" id="treasury_statement_auto_match" value="1" {{ ($settings['treasury_statement_auto_match'] ?? '0') === '1' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="treasury_statement_auto_match">{{ __('Auto-match statement lines (exact matches only)') }}</label>
                                    </div>
                                    <small class="text-muted">{{ __('Off means manual confirmation by default.') }}</small>
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-3">{{ __('Receipt Posting Accounts (Phase 1)') }}</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_fee_account_code">{{ __('Bank Fee Account Code') }}</label>
                                    <input type="text" name="treasury_fee_account_code" id="treasury_fee_account_code" class="form-control" value="{{ old('treasury_fee_account_code', $settings['treasury_fee_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 61.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_withholding_account_code">{{ __('Withholding Account Code') }}</label>
                                    <input type="text" name="treasury_withholding_account_code" id="treasury_withholding_account_code" class="form-control" value="{{ old('treasury_withholding_account_code', $settings['treasury_withholding_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 24.') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_discount_account_code">{{ __('Discount Account Code') }}</label>
                                    <input type="text" name="treasury_discount_account_code" id="treasury_discount_account_code" class="form-control" value="{{ old('treasury_discount_account_code', $settings['treasury_discount_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 61.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_customer_credit_account_code">{{ __('Customer Credit Liability Account Code') }}</label>
                                    <input type="text" name="treasury_customer_credit_account_code" id="treasury_customer_credit_account_code" class="form-control" value="{{ old('treasury_customer_credit_account_code', $settings['treasury_customer_credit_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 211.') }}</small>
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-3">{{ __('Transfer Exchange-Difference Accounts (Phase 4)') }}</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_exchange_loss_account_code">{{ __('Exchange Difference — Loss Account Code') }}</label>
                                    <input type="text" name="treasury_exchange_loss_account_code" id="treasury_exchange_loss_account_code" class="form-control" value="{{ old('treasury_exchange_loss_account_code', $settings['treasury_exchange_loss_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 694 (unfavourable exchange differences).') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_exchange_gain_account_code">{{ __('Exchange Difference — Gain Account Code') }}</label>
                                    <input type="text" name="treasury_exchange_gain_account_code" id="treasury_exchange_gain_account_code" class="form-control" value="{{ old('treasury_exchange_gain_account_code', $settings['treasury_exchange_gain_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 784 (favourable exchange differences).') }}</small>
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-3">{{ __('Cash Variance Accounts (Phase 5)') }}</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_cash_variance_shortage_account_code">{{ __('Cash Shortage — Variance Account Code') }}</label>
                                    <input type="text" name="treasury_cash_variance_shortage_account_code" id="treasury_cash_variance_shortage_account_code" class="form-control" value="{{ old('treasury_cash_variance_shortage_account_code', $settings['treasury_cash_variance_shortage_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 684 (cash shortage at till close).') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="treasury_cash_variance_surplus_account_code">{{ __('Cash Surplus — Variance Account Code') }}</label>
                                    <input type="text" name="treasury_cash_variance_surplus_account_code" id="treasury_cash_variance_surplus_account_code" class="form-control" value="{{ old('treasury_cash_variance_surplus_account_code', $settings['treasury_cash_variance_surplus_account_code'] ?? '') }}" maxlength="20">
                                    <small class="text-muted">{{ __('Leave empty to use the first chart account starting with 764 (cash surplus at till close).') }}</small>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        <a href="{{ route('treasury-accounts.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
