@extends('layouts.admin')
@section('page-title')
    {{ $balance ? __('Edit Opening Balance') : __('Create Opening Balance') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-opening-balances.index') }}">{{ __('Opening Balances') }}</a></li>
    <li class="breadcrumb-item">{{ $balance ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $balance ? route('treasury-opening-balances.update', $balance->id) : route('treasury-opening-balances.store') }}">
                        @csrf
                        @if ($balance)
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="financial_account_id">{{ __('Financial Account') }} *</label>
                                    <select name="financial_account_id" id="financial_account_id" class="form-control" required {{ $balance ? 'disabled' : '' }}>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (string) old('financial_account_id', $balance->financial_account_id ?? '') === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->bankAccount->holder_name ?? '' }} ({{ $account->currency }})</option>
                                        @endforeach
                                    </select>
                                    @if ($balance)
                                        <input type="hidden" name="financial_account_id" value="{{ $balance->financial_account_id }}">
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="amount">{{ __('Amount') }} *</label>
                                    <input type="number" step="0.0001" name="amount" id="amount" class="form-control" value="{{ old('amount', $balance->amount ?? '') }}" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="cut_off_date">{{ __('Cut-off Date') }} *</label>
                                    <input type="date" name="cut_off_date" id="cut_off_date" class="form-control" value="{{ old('cut_off_date', $balance ? $balance->cut_off_date->format('Y-m-d') : '') }}" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="exchange_rate">{{ __('Exchange Rate') }}</label>
                                    <input type="number" step="0.0000000001" min="0" name="exchange_rate" id="exchange_rate" class="form-control" value="{{ old('exchange_rate', $balance->exchange_rate ?? '') }}">
                                    <small class="text-muted">{{ __('Required when the account currency differs from the base currency (:base). A missing rate blocks approval — a rate is never guessed.', ['base' => $baseCurrency]) }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="rate_source">{{ __('Rate Source') }}</label>
                                    <select name="rate_source" id="rate_source" class="form-control">
                                        <option value="">{{ __('None (base currency)') }}</option>
                                        @foreach (\App\Models\TreasuryOpeningBalance::RATE_SOURCE_LABELS as $value => $label)
                                            <option value="{{ $value }}" {{ old('rate_source', $balance->rate_source ?? '') === $value ? 'selected' : '' }}>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="rate_date">{{ __('Rate Date') }}</label>
                                    <input type="date" name="rate_date" id="rate_date" class="form-control" value="{{ old('rate_date', $balance && $balance->rate_date ? $balance->rate_date->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="batch_reference">{{ __('Batch Reference') }}</label>
                                    <input type="text" name="batch_reference" id="batch_reference" class="form-control" value="{{ old('batch_reference', $balance->batch_reference ?? '') }}" maxlength="100" {{ $balance ? 'disabled' : '' }}>
                                    <small class="text-muted">{{ __('Leave empty to generate one. A reference the company already used is refused.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="attachments">{{ __('Attachments') }}</label>
                                    <input type="text" name="attachments" id="attachments" class="form-control" value="{{ old('attachments', $balance->attachments ?? '') }}" maxlength="1000">
                                    <small class="text-muted">{{ __('File names or references, comma separated.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="includes_liabilities" id="includes_liabilities" value="1" {{ old('includes_liabilities', $balance->includes_liabilities ?? false) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="includes_liabilities">{{ __('Includes liabilities already in the migration') }}</label>
                                    </div>
                                    <small class="text-muted">{{ __('Flagged so later settlements do not recreate them.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="includes_receivables" id="includes_receivables" value="1" {{ old('includes_receivables', $balance->includes_receivables ?? false) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="includes_receivables">{{ __('Includes receivables already in the migration') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $balance ? __('Update Draft') : __('Create Draft') }}</button>
                        <a href="{{ route('treasury-opening-balances.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
