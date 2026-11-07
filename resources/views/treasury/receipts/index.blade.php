@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Receipts') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Receipts') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create treasury receipts')
            <a href="{{ route('treasury-receipts.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Receipt') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-receipts.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryReceipt::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst($state) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="customer_id" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All customers') }}</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}" {{ (string) request('customer_id') === (string) $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($receipts->isEmpty())
                        <p class="text-muted">{{ __('No receipts yet. Create one to record money received from a customer.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Customer') }}</th>
                                        <th>{{ __('Method') }}</th>
                                        <th>{{ __('Gross') }}</th>
                                        <th>{{ __('Unapplied') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Value date') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($receipts as $receipt)
                                        <tr>
                                            <td>{{ $receipt->operation->reference ?? __('Draft') }}</td>
                                            <td>{{ $receipt->customer->name ?? ($receipt->classification ?? '—') }}</td>
                                            <td>{{ $receipt->methodLabel() }}</td>
                                            <td>{{ number_format((float) $receipt->operation->amount_original, 2) }} {{ $receipt->operation->currency }}</td>
                                            <td>
                                                @if ($receipt->isConfirmed())
                                                    {{ number_format((float) app(\App\Services\TreasuryReceiptService::class)->remainingUnapplied($receipt), 2) }} {{ $receipt->operation->currency }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td><span class="badge bg-{{ $receipt->state === 'confirmed' ? 'success' : ($receipt->state === 'reversed' ? 'danger' : ($receipt->state === 'pending' ? 'warning' : 'secondary')) }}">{{ ucfirst($receipt->state) }}</span></td>
                                            <td>{{ $receipt->value_date ? $receipt->value_date->format('Y-m-d') : '—' }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-receipts.show', $receipt) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $receipts->appends(request()->query())->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
