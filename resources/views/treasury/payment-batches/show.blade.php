@extends('layouts.admin')
@section('page-title')
    {{ __('Payment Batch') }} {{ $batch->reference ?? __('Draft') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-payment-batches.index') }}">{{ __('Payment Batches') }}</a></li>
    <li class="breadcrumb-item">{{ $batch->reference ?? __('Draft') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('execute treasury payments')
            @if (in_array($batch->state, ['draft', 'processing', 'partially_paid'], true))
                <form method="POST" action="{{ route('treasury-payment-batches.execute', $batch) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('{{ __('Execute the pending items of this batch?') }}')">{{ __('Execute batch') }}</button>
                </form>
            @endif
            @if (in_array($batch->state, ['draft', 'partially_paid'], true))
                <form method="POST" action="{{ route('treasury-payment-batches.cancel', $batch) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('{{ __('Cancel this batch? Pending items are skipped.') }}')">{{ __('Cancel') }}</button>
                </form>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('State') }}</strong>
                            <div><span class="badge bg-{{ $batch->state === 'paid' ? 'success' : ($batch->state === 'failed' || $batch->state === 'cancelled' ? 'danger' : ($batch->state === 'partially_paid' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $batch->state)) }}</span></div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Reference') }}</strong>
                            <div>{{ $batch->reference ?? __('Draft — allocated on first execution') }}</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Executed') }}</strong>
                            <div>{{ $batch->executed_at ? $batch->executed_at->format('Y-m-d H:i') : '—' }}</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <strong>{{ __('Note') }}</strong>
                            <div>{{ $batch->note ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('Items') }}</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Payment request') }}</th>
                                    <th>{{ __('Beneficiary') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('State') }}</th>
                                    <th>{{ __('Attempt') }}</th>
                                    <th>{{ __('Failure reason') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($batch->items as $item)
                                    <tr>
                                        <td>
                                            @if ($item->paymentRequest)
                                                <a href="{{ route('treasury-payment-requests.show', $item->paymentRequest) }}">{{ $item->paymentRequest->operation->reference ?? ('#' . $item->payment_request_id) }}</a>
                                            @else
                                                #{{ $item->payment_request_id }}
                                            @endif
                                        </td>
                                        <td>{{ $item->paymentRequest ? $item->paymentRequest->beneficiaryLabel() : '—' }}</td>
                                        <td>{{ number_format((float) $item->amount_original, 2) }} {{ $item->currency }}</td>
                                        <td><span class="badge bg-{{ $item->state === 'paid' ? 'success' : ($item->state === 'failed' ? 'danger' : 'secondary') }}">{{ ucfirst($item->state) }}</span></td>
                                        <td>{{ $item->attempt ? '#' . $item->attempt->attempt_number . ' (' . ucfirst(str_replace('_', ' ', $item->attempt->state)) . ')' : '—' }}</td>
                                        <td>{{ $item->failure_reason ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
