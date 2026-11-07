@extends('layouts.admin')
@section('page-title')
    {{ $transfer ? __('Edit Transfer') : __('New Transfer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-transfers.index') }}">{{ __('Transfers') }}</a></li>
    <li class="breadcrumb-item">{{ $transfer ? __('Edit') : __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($accounts->count() < 2)
                        <p class="text-muted">{{ __('A transfer needs at least two active accounts. Create the accounts first.') }}</p>
                        <a href="{{ route('treasury-transfers.index') }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    @else
                        <form method="POST" action="{{ $transfer ? route('treasury-transfers.update', $transfer) : route('treasury-transfers.store') }}">
                            @csrf
                            @if ($transfer)
                                @method('PUT')
                            @endif
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Source Account') }} <span class="text-danger">*</span></label>
                                    <select name="source_account_id" id="source_account_id" class="form-control" required>
                                        <option value="">{{ __('Select an account') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" data-currency="{{ $account->currency }}" {{ (int) old('source_account_id', $transfer->source_account_id ?? 0) === (int) $account->id ? 'selected' : '' }}>{{ $account->displayName() }} — {{ $account->currency }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Destination Account') }} <span class="text-danger">*</span></label>
                                    <select name="destination_account_id" id="destination_account_id" class="form-control" required>
                                        <option value="">{{ __('Select an account') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" data-currency="{{ $account->currency }}" {{ (int) old('destination_account_id', $transfer->destination_account_id ?? 0) === (int) $account->id ? 'selected' : '' }}>{{ $account->displayName() }} — {{ $account->currency }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Timing') }} <span class="text-danger">*</span></label>
                                    <select name="timing" id="timing" class="form-control">
                                        <option value="immediate" {{ old('timing', $transfer->timing ?? 'immediate') === 'immediate' ? 'selected' : '' }}>{{ __('Immediate — both legs at once') }}</option>
                                        <option value="delayed" {{ old('timing', $transfer->timing ?? '') === 'delayed' ? 'selected' : '' }}>{{ __('Delayed — through a transit account') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3" id="transit_wrap" style="display: none;">
                                    <label class="form-label">{{ __('Transit Account') }} <span class="text-danger">*</span></label>
                                    <select name="transit_account_id" id="transit_account_id" class="form-control">
                                        <option value="">{{ __('Select a transit account') }}</option>
                                        @foreach ($transitAccounts as $account)
                                            <option value="{{ $account->id }}" data-currency="{{ $account->currency }}" {{ (int) old('transit_account_id', $transfer->transit_account_id ?? 0) === (int) $account->id ? 'selected' : '' }}>{{ $account->displayName() }} — {{ $account->currency }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('The money waits here until it is cleared to the destination.') }}</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Transfer Date') }}</label>
                                    <input type="date" name="transfer_date" class="form-control" value="{{ old('transfer_date', $transfer->operation->effective_date ? $transfer->operation->effective_date->format('Y-m-d') : date('Y-m-d')) }}">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Amount (source currency)') }} <span class="text-danger">*</span></label>
                                    <input type="number" step="0.0001" min="0.0001" name="amount_debited" class="form-control" value="{{ old('amount_debited', $transfer->amount_debited ?? '') }}" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Fee (source currency)') }}</label>
                                    <input type="number" step="0.0001" min="0" name="fee_amount" class="form-control" value="{{ old('fee_amount', $transfer->fee_amount ?? '0') }}">
                                    <small class="text-muted">{{ __('Charged on top of the amount, as its own expense line.') }}</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Evidence / Note') }}</label>
                                    <input type="text" name="evidence" class="form-control" value="{{ old('evidence', $transfer->evidence ?? '') }}" maxlength="1000">
                                </div>
                            </div>
                            <div id="fx_wrap" style="display: none;">
                                <h6 class="mt-2">{{ __('Exchange Rate') }}</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Rate (source → destination)') }} <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0000000001" min="0" name="exchange_rate" id="exchange_rate" class="form-control" value="{{ old('exchange_rate', $transfer->exchange_rate ?? '') }}">
                                        <small class="text-muted">{{ __('1 source unit buys this many destination units.') }}</small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Rate Source') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="rate_source" class="form-control" value="{{ old('rate_source', $transfer->rate_source ?? '') }}" maxlength="20" placeholder="bank / central bank / manual">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Rate Date') }}</label>
                                        <input type="date" name="rate_date" class="form-control" value="{{ old('rate_date', $transfer->rate_date ? $transfer->rate_date->format('Y-m-d') : date('Y-m-d')) }}">
                                    </div>
                                </div>
                            </div>
                            <div id="base_rate_wrap" style="display: none;">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">{{ __('Base Rate (source → :base)', ['base' => $baseCurrency]) }} <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0000000001" min="0" name="base_exchange_rate" id="base_exchange_rate" class="form-control" value="{{ old('base_exchange_rate', $transfer->base_exchange_rate ?? '') }}">
                                        <small class="text-muted">{{ __('Needed only when neither account uses the base currency. Otherwise it is 1, or the same as the rate above.') }}</small>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">{{ $transfer ? __('Update draft') : __('Create draft') }}</button>
                            <a href="{{ route('treasury-transfers.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        (function () {
            var baseCurrency = '{{ $baseCurrency }}';
            var sourceSelect = document.getElementById('source_account_id');
            var destinationSelect = document.getElementById('destination_account_id');
            var timingSelect = document.getElementById('timing');
            var transitWrap = document.getElementById('transit_wrap');
            var fxWrap = document.getElementById('fx_wrap');
            var baseRateWrap = document.getElementById('base_rate_wrap');

            function currencyOf(select) {
                var option = select.options[select.selectedIndex];
                return option ? (option.getAttribute('data-currency') || '') : '';
            }

            function refresh() {
                var sourceCurrency = currencyOf(sourceSelect);
                var destinationCurrency = currencyOf(destinationSelect);
                transitWrap.style.display = timingSelect.value === 'delayed' ? '' : 'none';
                var needsFx = sourceCurrency !== '' && destinationCurrency !== '' && sourceCurrency !== destinationCurrency;
                fxWrap.style.display = needsFx ? '' : 'none';
                var needsBaseRate = sourceCurrency !== '' && destinationCurrency !== ''
                    && sourceCurrency !== baseCurrency && destinationCurrency !== baseCurrency;
                baseRateWrap.style.display = needsBaseRate ? '' : 'none';
            }

            sourceSelect.addEventListener('change', refresh);
            destinationSelect.addEventListener('change', refresh);
            timingSelect.addEventListener('change', refresh);
            refresh();
        })();
    </script>
@endpush
