@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Transfers') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Transfers') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury transfers')
            <a href="{{ route('treasury-transfers.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Transfer') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-transfers.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryTransfer::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $state)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($transfers->isEmpty())
                        <p class="text-muted">{{ __('No transfers yet. Move money between two accounts of this company — immediately, or delayed through a transit account.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('From') }}</th>
                                        <th>{{ __('To') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Timing') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($transfers as $transfer)
                                        <tr>
                                            <td>{{ $transfer->operation->reference ?? __('Draft') }}</td>
                                            <td>{{ $transfer->sourceAccount->code ?? '—' }}</td>
                                            <td>{{ $transfer->destinationAccount->code ?? '—' }}</td>
                                            <td>{{ number_format((float) $transfer->amount_debited, 2) }} {{ $transfer->source_currency }}</td>
                                            <td>{{ ucfirst($transfer->timing) }}</td>
                                            <td><span class="badge bg-{{ $transfer->state === 'confirmed' ? 'success' : ($transfer->state === 'returned' || $transfer->state === 'cancelled' ? 'danger' : ($transfer->state === 'in_transit' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $transfer->state)) }}</span></td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-transfers.show', $transfer) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $transfers->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
