@extends('layouts.admin')
@section('page-title')
    {{ __('Refund Credit Note') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('invoice.index') }}">{{ __('Credit Note') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('note.view', \Crypt::encrypt($note->id)) }}">{{ auth()->user()->creditNoteNumberFormat($note->uid) }}</a></li>
    <li class="breadcrumb-item">{{ __('Refund') }}</li>
@endsection
@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <p class="mb-3">
                    {{ __('Refund the full amount of') }} <strong>{{ auth()->user()->creditNoteNumberFormat($note->uid) }}</strong>:
                    <strong>{{ \Auth::user()->priceFormat($note->amount) }}</strong>.
                    {{ __('One refund per note — the full amount, paid once.') }}
                </p>
                @if(!empty($existingRefund))
                    <div class="alert alert-info">
                        {{ __('This note was already refunded.') }} {{ __('Reference') }}: <strong>{{ $existingRefund->reference }}</strong>
                    </div>
                @else
                {{ Form::open(['route' => ['credit.note.refund.store', $invoice_id, $note->id], 'method' => 'post', 'class' => 'needs-validation', 'novalidate', 'files' => true]) }}
                <div class="row">
                    <div class="form-group col-md-12">
                        {{ Form::label('method', __('How is the money paid back?'), ['class' => 'form-label']) }}<x-required></x-required>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="method" id="method_transfer" value="transfer" checked required>
                            <label class="form-check-label" for="method_transfer">{{ __('Bank transfer') }}</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="method" id="method_cash" value="cash" required>
                            <label class="form-check-label" for="method_cash">{{ __('Cash') }}</label>
                        </div>
                    </div>

                    <div id="transfer-section" class="col-md-12">
                        <div class="form-group">
                            {{ Form::label('financial_account_id', __('Bank account'), ['class' => 'form-label']) }}<x-required></x-required>
                            <select name="financial_account_id" id="bank_account_id" class="form-control">
                                @foreach($bankAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            {{ Form::label('transfer_receipt', __('Transfer receipt'), ['class' => 'form-label']) }}<x-required></x-required>
                            <input type="file" name="transfer_receipt" id="transfer_receipt" class="form-control" required>
                            <small class="text-muted">{{ __('Attach the bank transfer receipt.') }}</small>
                        </div>
                    </div>

                    <div id="cash-section" class="col-md-12" style="display:none;">
                        <div class="form-group">
                            {{ Form::label('cash_financial_account_id', __('Cash register'), ['class' => 'form-label']) }}
                            <select name="financial_account_id" id="cash_account_id" class="form-control" disabled>
                                <option value="">{{ __('Choose a cash register') }}</option>
                                @foreach($cashAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            {{ Form::label('petty_cash_fund_id', __('Or petty cash fund'), ['class' => 'form-label']) }}
                            <select name="petty_cash_fund_id" id="petty_cash_fund_id" class="form-control">
                                <option value="">{{ __('No petty cash fund') }}</option>
                                @foreach($pettyFunds as $fund)
                                    <option value="{{ $fund->id }}">{{ $fund->financialAccount?->code ?? '' }} — {{ __('Petty cash') }} ({{ __('limit') }} {{ \Auth::user()->priceFormat($fund->limit_amount) }})</option>
                                @endforeach
                            </select>
                        </div>
                        @if($openSessions->isNotEmpty())
                        <div class="form-group">
                            {{ Form::label('cash_session_id', __('Till session (optional — when refunding at the till)'), ['class' => 'form-label']) }}
                            <select name="cash_session_id" id="cash_session_id" class="form-control">
                                <option value="">{{ __('No session — back office') }}</option>
                                @foreach($openSessions as $session)
                                    <option value="{{ $session->id }}">{{ $session->register?->code ?? '' }} — {{ __('opened') }} {{ $session->opened_at }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        <div class="form-group">
                            {{ Form::label('signed_receipt', __('Signed customer receipt (optional)'), ['class' => 'form-label']) }}
                            <input type="file" name="signed_receipt" id="signed_receipt" class="form-control">
                            <small class="text-muted">{{ __('The customer signs the receipt when cash is handed over.') }}</small>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="{{ route('note.view', \Crypt::encrypt($note->id)) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    <input type="submit" value="{{ __('Pay refund') }}" class="btn btn-primary">
                </div>
                {{ Form::close() }}
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
@push('script-page')
<script>
    // Toggle transfer / cash sections. The two source selects share one
    // field name (financial_account_id): only the visible one is enabled,
    // so the server only ever receives the source for the chosen method.
    function toggleRefundMethod() {
        var isTransfer = document.getElementById('method_transfer').checked;
        document.getElementById('transfer-section').style.display = isTransfer ? '' : 'none';
        document.getElementById('cash-section').style.display = isTransfer ? 'none' : '';
        document.getElementById('bank_account_id').disabled = !isTransfer;
        document.getElementById('cash_account_id').disabled = isTransfer;
        document.getElementById('transfer_receipt').required = isTransfer;
    }
    document.getElementById('method_transfer').addEventListener('change', toggleRefundMethod);
    document.getElementById('method_cash').addEventListener('change', toggleRefundMethod);
    toggleRefundMethod();
</script>
@endpush
