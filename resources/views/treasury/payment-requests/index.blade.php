@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Payment Requests') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Payment Requests') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create treasury payment requests')
            <a href="{{ route('treasury-payment-requests.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Payment Request') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-payment-requests.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryPaymentRequest::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $state)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="beneficiary_type" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All beneficiaries') }}</option>
                                @foreach (\App\Models\TreasuryPaymentRequest::BENEFICIARY_TYPES as $type)
                                    <option value="{{ $type }}" {{ request('beneficiary_type') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($requests->isEmpty())
                        <p class="text-muted">{{ __('No payment requests yet. Create one to start a payment approval workflow.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Beneficiary') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Due date') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($requests as $paymentRequest)
                                        <tr>
                                            <td>{{ $paymentRequest->operation->reference ?? __('Draft') }}</td>
                                            <td>{{ ucfirst($paymentRequest->beneficiary_type) }} #{{ $paymentRequest->beneficiary_id }}</td>
                                            <td>{{ number_format((float) $paymentRequest->amount_original, 2) }} {{ $paymentRequest->currency }}</td>
                                            <td><span class="badge bg-{{ $paymentRequest->state === 'paid' || $paymentRequest->state === 'approved' ? 'success' : ($paymentRequest->state === 'rejected' || $paymentRequest->state === 'failed' ? 'danger' : ($paymentRequest->state === 'awaiting_approval' || $paymentRequest->state === 'under_review' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $paymentRequest->state)) }}</span></td>
                                            <td>{{ $paymentRequest->due_date ? $paymentRequest->due_date->format('Y-m-d') : '—' }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-payment-requests.show', $paymentRequest) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $requests->appends(request()->query())->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
