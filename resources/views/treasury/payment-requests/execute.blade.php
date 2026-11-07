@extends('layouts.admin')
@section('page-title')
    {{ __('Execute Payment') }} {{ $paymentRequest->operation->reference ?? __('Draft') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-payment-requests.index') }}">{{ __('Payment Requests') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-payment-requests.show', $paymentRequest) }}">{{ $paymentRequest->operation->reference ?? __('Draft') }}</a></li>
    <li class="breadcrumb-item">{{ __('Execute') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Execution check') }}</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Beneficiary') }}</strong>
                            <div>{{ $paymentRequest->beneficiaryLabel() }}</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Source account') }}</strong>
                            <div>{{ $preview['account']->code ?? '—' }}</div>
                            <small class="text-muted">{{ __('Available') }}: {{ $preview['available_balance'] !== null ? number_format((float) $preview['available_balance'], 2) : '—' }}</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Amount') }}</strong>
                            <div>{{ number_format((float) $paymentRequest->amount_original, 2) }} {{ $paymentRequest->currency }}</div>
                            <small class="text-muted">{{ __('Remaining') }}: {{ number_format((float) $paymentRequest->remainingAmountOriginal(), 2) }} {{ $paymentRequest->currency }}</small>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Posting date') }}</strong>
                            <div>{{ $preview['posting_date'] }}</div>
                        </div>
                    </div>

                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>{{ __('Check') }}</th>
                                <th>{{ __('Result') }}</th>
                                <th>{{ __('Detail') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['checks'] as $check)
                                <tr>
                                    <td>{{ ucfirst(str_replace('_', ' ', $check['key'])) }}</td>
                                    <td>
                                        @if ($check['passed'])
                                            <span class="badge bg-success">{{ __('Passed') }}</span>
                                        @else
                                            <span class="badge bg-danger">{{ __('Failed') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $check['detail'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if (! empty($preview['allocations']))
                        <p class="text-muted">
                            {{ __('Settles bills') }}: {{ number_format((float) $preview['allocated_to_bills_base'], 2) }}
                            — {{ __('manual portion') }}: {{ number_format((float) $preview['manual_portion_base'], 2) }}.
                        </p>
                    @endif

                    @if (! $preview['can_execute'] && ! $showForm)
                        <div class="alert alert-danger">{{ __('This payment cannot be executed until the failed checks are resolved.') }}</div>
                    @endif
                </div>
            </div>

            @if ($showForm)
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ __('Confirm execution') }}</h5></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('treasury-payment-requests.execute', $paymentRequest) }}">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Amount to execute') }} ({{ $paymentRequest->currency }})</label>
                                    <input type="number" step="0.0001" min="0.0001" max="{{ $paymentRequest->remainingAmountOriginal() }}" name="amount_original" class="form-control" value="{{ old('amount_original', $paymentRequest->remainingAmountOriginal()) }}" required>
                                    <small class="text-muted">{{ __('A smaller amount pays this request in part. The request is paid only when the full amount is settled.') }}</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Posting date') }}</label>
                                    <input type="date" name="posting_date" class="form-control" value="{{ old('posting_date', $preview['posting_date']) }}">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('Provider reference') }}</label>
                                    <input type="text" name="provider_reference" class="form-control" value="{{ old('provider_reference') }}" maxlength="100">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">{{ __('Note') }}</label>
                                    <input type="text" name="result" class="form-control" value="{{ old('result') }}" maxlength="1000">
                                </div>
                                @if ($paymentRequest->state === 'outcome_unknown')
                                    <div class="col-md-12 mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="provider_queried" value="1" class="form-check-input" id="provider_queried" required>
                                            <label class="form-check-label" for="provider_queried">{{ __('I have queried the provider and it has not confirmed this payment.') }}</label>
                                        </div>
                                    </div>
                                @endif
                                @foreach ($preview['checks'] as $check)
                                    @if ($check['key'] === 'period' && ! $check['passed'])
                                        <div class="col-md-12 mb-3">
                                            <div class="alert alert-warning">
                                                <strong>{{ __('Closed period.') }}</strong>
                                                {{ __('This payment can only post as an authorised adjustment to an open period.') }}
                                            </div>
                                            <div class="form-check mb-2">
                                                <input type="checkbox" name="adjustment" value="1" class="form-check-input" id="adjustment">
                                                <label class="form-check-label" for="adjustment">{{ __('Post as an authorised adjustment to an open period') }}</label>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">{{ __('Authorised by (user id)') }}</label>
                                                    <input type="number" name="adjustment_authorised_by" class="form-control" value="{{ old('adjustment_authorised_by') }}">
                                                    <small class="text-muted">{{ __('A different user than the executor, holding the approve treasury payments permission.') }}</small>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">{{ __('Adjustment reason') }}</label>
                                                    <input type="text" name="adjustment_reason" class="form-control" value="{{ old('adjustment_reason') }}" maxlength="1000">
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('Execute payment') }}</button>
                            <a href="{{ route('treasury-payment-requests.show', $paymentRequest) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
