@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Payment Batches') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Payment Batches') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('execute treasury payments')
            <a href="{{ route('treasury-payment-batches.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Payment Batch') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-payment-batches.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryPaymentBatch::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $state)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($batches->isEmpty())
                        <p class="text-muted">{{ __('No payment batches yet. Group several approved payment requests into one batch and execute them together.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Items') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Executed') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($batches as $batch)
                                        <tr>
                                            <td>{{ $batch->reference ?? __('Draft') }}</td>
                                            <td>{{ $batch->items_count }}</td>
                                            <td><span class="badge bg-{{ $batch->state === 'paid' ? 'success' : ($batch->state === 'failed' || $batch->state === 'cancelled' ? 'danger' : ($batch->state === 'partially_paid' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $batch->state)) }}</span></td>
                                            <td>{{ $batch->executed_at ? $batch->executed_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-payment-batches.show', $batch) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $batches->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
