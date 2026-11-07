@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Transfer') }} {{ $transfer->operation->reference ?? __('Draft') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-transfers.index') }}">{{ __('Transfers') }}</a></li>
    <li class="breadcrumb-item">{{ $transfer->operation->reference ?? __('Draft') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury transfers')
            @if ($transfer->isDraft())
                <a href="{{ route('treasury-transfers.edit', $transfer) }}" class="btn btn-sm btn-outline-primary">{{ __('Edit Draft') }}</a>
                <a href="{{ route('treasury-transfers.confirm-form', $transfer) }}" class="btn btn-sm btn-primary">{{ __('Confirm') }}</a>
                <form method="POST" action="{{ route('treasury-transfers.cancel', $transfer) }}" class="d-inline" onsubmit="return confirm('{{ __('Cancel this transfer draft?') }}')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Cancel') }}</button>
                </form>
            @elseif ($transfer->isInTransit())
                <form method="POST" action="{{ route('treasury-transfers.clear', $transfer) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Clear — Money Arrived') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#returnModal">{{ __('Return to Source') }}</button>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('Reference') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->operation->reference ?? __('Draft — reference is allocated on confirm') }}</dd>
                        <dt class="col-sm-4">{{ __('State') }}</dt>
                        <dd class="col-sm-8"><span class="badge bg-{{ $transfer->state === 'confirmed' ? 'success' : ($transfer->state === 'returned' || $transfer->state === 'cancelled' ? 'danger' : ($transfer->state === 'in_transit' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $transfer->state)) }}</span></dd>
                        <dt class="col-sm-4">{{ __('Timing') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst($transfer->timing) }}</dd>
                        <dt class="col-sm-4">{{ __('From') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->sourceAccount ? $transfer->sourceAccount->displayName() : '—' }} — {{ $transfer->source_currency }}</dd>
                        <dt class="col-sm-4">{{ __('To') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->destinationAccount ? $transfer->destinationAccount->displayName() : '—' }} — {{ $transfer->destination_currency }}</dd>
                        @if ($transfer->transitAccount)
                            <dt class="col-sm-4">{{ __('Transit Account') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->transitAccount->displayName() }} — {{ $transfer->transitAccount->currency }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Amount Debited') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $transfer->amount_debited, 2) }} {{ $transfer->source_currency }}</dd>
                        <dt class="col-sm-4">{{ __('Amount Credited') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $transfer->amount_credited, 2) }} {{ $transfer->destination_currency }}</dd>
                        @if ($transfer->source_currency !== $transfer->destination_currency)
                            <dt class="col-sm-4">{{ __('Exchange Rate') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->exchange_rate }} {{ $transfer->source_currency }} → {{ $transfer->destination_currency }} ({{ $transfer->rate_source ?? __('manual') }}{{ $transfer->rate_date ? ', ' . $transfer->rate_date->format('Y-m-d') : '' }})</dd>
                        @endif
                        @if ((float) $transfer->fee_amount > 0)
                            <dt class="col-sm-4">{{ __('Fee') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $transfer->fee_amount, 2) }} {{ $transfer->source_currency }}</dd>
                        @endif
                        @if ((float) $transfer->exchange_difference_base != 0)
                            <dt class="col-sm-4">{{ __('Exchange Difference') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $transfer->exchange_difference_base, 2) }} {{ (float) $transfer->exchange_difference_base > 0 ? __('(loss)') : __('(gain)') }}</dd>
                        @endif
                        @if ($transfer->evidence)
                            <dt class="col-sm-4">{{ __('Evidence / Note') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->evidence }}</dd>
                        @endif
                        @if ($transfer->return_reason)
                            <dt class="col-sm-4">{{ __('Return Reason') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->return_reason }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Transfer Date') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->operation->effective_date ? $transfer->operation->effective_date->format('Y-m-d') : '—' }}</dd>
                        @if ($transfer->confirmed_at)
                            <dt class="col-sm-4">{{ __('Confirmed') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->confirmed_at->format('Y-m-d H:i') }}</dd>
                        @endif
                        @if ($transfer->cleared_at && $transfer->isDelayed())
                            <dt class="col-sm-4">{{ __('Cleared') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->cleared_at->format('Y-m-d H:i') }}</dd>
                        @endif
                        @if ($transfer->returned_at)
                            <dt class="col-sm-4">{{ __('Returned') }}</dt>
                            <dd class="col-sm-8">{{ $transfer->returned_at->format('Y-m-d H:i') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Money Movements') }}</h6>
                    @php $entries = $transfer->operation ? $transfer->operation->entries()->with('financialAccount')->orderBy('id')->get() : collect(); @endphp
                    @if ($entries->isEmpty())
                        <p class="text-muted">{{ __('No movements yet — the money moves when the transfer is confirmed.') }}</p>
                    @else
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Account') }}</th>
                                    <th>{{ __('Direction') }}</th>
                                    <th class="text-end">{{ __('Amount (base)') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $entry)
                                    <tr>
                                        <td>{{ $entry->financialAccount->code ?? '—' }}</td>
                                        <td>{{ ucfirst($entry->direction) }}</td>
                                        <td class="text-end">{{ number_format((float) $entry->amount_base, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="text-muted small">{{ __('The money is in exactly one place at a time — source, then transit, then destination. It is never counted twice.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($transfer->isInTransit())
        <div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('treasury-transfers.return', $transfer) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Return Transfer to Source') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted">{{ __('The money comes back to the source account. The transfer fee stays charged.') }}</p>
                            <div class="form-group">
                                <label class="form-label">{{ __('Reason') }}</label>
                                <input type="text" name="reason" class="form-control" maxlength="1000" placeholder="{{ __('Destination rejected the transfer') }}">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                            <button type="submit" class="btn btn-danger">{{ __('Return') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection
