@extends('layouts.admin')
@section('page-title')
    {{ __('Opening Balances') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Opening Balances') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury accounts')
            <a href="{{ route('treasury-opening-balances.import') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-upload"></i> {{ __('Import CSV') }}
            </a>
            <a href="{{ route('treasury-opening-balances.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Opening Balance') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($balances->isEmpty())
                        <p class="text-muted">{{ __('No opening balances yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Batch') }}</th>
                                        <th>{{ __('Account') }}</th>
                                        <th>{{ __('Cut-off') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                        <th class="text-end">{{ __('Base Amount') }}</th>
                                        <th>{{ __('Rate') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($balances as $balance)
                                        <tr>
                                            <td>{{ $balance->batch_reference }}</td>
                                            <td>{{ $balance->financialAccount->code ?? '—' }}</td>
                                            <td>{{ $balance->cut_off_date->format('Y-m-d') }}</td>
                                            <td class="text-end">{{ number_format((float) $balance->amount, 2) }} {{ $balance->currency }}</td>
                                            <td class="text-end">{{ number_format((float) $balance->amount_base, 2) }}</td>
                                            <td>
                                                @if ($balance->exchange_rate)
                                                    {{ rtrim(rtrim($balance->exchange_rate, '0'), '.') }}
                                                    <small class="text-muted">({{ \App\Models\TreasuryOpeningBalance::RATE_SOURCE_LABELS[$balance->rate_source] ?? $balance->rate_source }})</small>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>
                                                @if ($balance->status === 'draft')
                                                    <span class="badge bg-warning">{{ __('Draft') }}</span>
                                                @elseif ($balance->status === 'approved')
                                                    <span class="badge bg-success">{{ __('Approved') }}</span>
                                                @else
                                                    <span class="badge bg-secondary">{{ __('Reversed') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @can('manage treasury accounts')
                                                    @if ($balance->isDraft())
                                                        <a href="{{ route('treasury-opening-balances.edit', $balance->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
                                                        <form method="POST" action="{{ route('treasury-opening-balances.approve', $balance->id) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('treasury-opening-balances.destroy', $balance->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete this draft?') }}')">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-danger">{{ __('Delete') }}</button>
                                                        </form>
                                                    @endif
                                                @endcan
                                                @can('reverse treasury operations')
                                                    @if ($balance->isApproved())
                                                        <form method="POST" action="{{ route('treasury-opening-balances.reverse', $balance->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Reverse this approved opening balance? The original is kept; a reversal row nets it to zero.') }}')">
                                                            @csrf
                                                            <input type="hidden" name="reversal_reason" value="Reversed from the opening balances list">
                                                            <button type="submit" class="btn btn-sm btn-warning">{{ __('Reverse') }}</button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $balances->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
