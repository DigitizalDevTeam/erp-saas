@extends('layouts.admin')
@section('page-title')
    {{ __('Petty Cash') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Petty Cash') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury cash sessions')
            <a href="{{ route('treasury-petty-cash.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Fund') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($funds->isEmpty())
                        <p class="text-muted">{{ __('No petty-cash funds yet. A fund is a small cash float held by a custodian on one financial account.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Account') }}</th>
                                        <th>{{ __('Custodian') }}</th>
                                        <th>{{ __('Balance') }}</th>
                                        <th>{{ __('Limit') }}</th>
                                        <th>{{ __('Replenishment Due') }}</th>
                                        <th>{{ __('Open Expenses') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($funds as $fund)
                                        @php $summary = $summaries[$fund->id]; @endphp
                                        <tr>
                                            <td>{{ $fund->financialAccount ? $fund->financialAccount->displayName() : '—' }}</td>
                                            <td>{{ $fund->custodian ? $fund->custodian->name : '—' }}</td>
                                            <td>{{ number_format((float) $summary['posted_balance'], 2) }} {{ $fund->financialAccount ? $fund->financialAccount->currency : '' }}</td>
                                            <td>{{ $summary['limit'] !== null ? number_format((float) $summary['limit'], 2) : '—' }}</td>
                                            <td>{{ number_format((float) $summary['replenishment_due'], 2) }}</td>
                                            <td>{{ $summary['expenses']['pending'] + $summary['expenses']['approved'] }}</td>
                                            <td><span class="badge bg-{{ $fund->isActive() ? 'success' : 'secondary' }}">{{ ucfirst($fund->status) }}</span></td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-petty-cash.show', $fund) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $funds->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
