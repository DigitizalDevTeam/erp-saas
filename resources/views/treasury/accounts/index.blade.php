@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Accounts') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Accounts') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury accounts')
            <a href="{{ route('treasury.settings') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-settings"></i> {{ __('Settings') }}
            </a>
            <a href="{{ route('treasury-accounts.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Financial Account') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($accounts->isEmpty())
                        <p class="text-muted">{{ __('No financial accounts yet. Create one to start using Treasury.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Code') }}</th>
                                        <th>{{ __('Account') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Currency') }}</th>
                                        <th>{{ __('Branch') }}</th>
                                        <th>{{ __('Posted Balance') }}</th>
                                        <th>{{ __('Negative Balance') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($accounts as $account)
                                        <tr>
                                            <td><strong>{{ $account->code }}</strong></td>
                                            <td>
                                                {{ $account->bankAccount->holder_name ?? '—' }}
                                                <br><small class="text-muted">{{ $account->bankAccount->bank_name ?? '' }} · {{ $account->bankAccount->account_number ?? '' }}</small>
                                            </td>
                                            <td>{{ $account->typeLabel() }}</td>
                                            <td>{{ $account->currency }}@if ($account->currency_locked) <small class="text-muted">({{ __('locked') }})</small>@endif</td>
                                            <td>{{ $account->branch->name ?? '—' }}</td>
                                            <td>{{ number_format((float) ($balances[$account->id] ?? '0'), 2) }} {{ $account->currency }}</td>
                                            <td>{{ ucfirst($account->negative_balance_policy) }}@if ($account->negative_balance_limit !== null) <small class="text-muted">({{ number_format((float) $account->negative_balance_limit, 2) }})</small>@endif</td>
                                            <td>
                                                @if ($account->isActive())
                                                    <span class="badge bg-success">{{ __('Active') }}</span>
                                                @else
                                                    <span class="badge bg-secondary">{{ __('Inactive') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('treasury-accounts.statement', $account->id) }}" class="btn btn-sm btn-secondary">{{ __('Statement') }}</a>
                                                @can('manage treasury accounts')
                                                    <a href="{{ route('treasury-accounts.edit', $account->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
                                                    @if ($account->isActive())
                                                        <form method="POST" action="{{ route('treasury-accounts.deactivate', $account->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Deactivate this account? New activity will be blocked; reporting is kept.') }}')">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-warning">{{ __('Deactivate') }}</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('treasury-accounts.reactivate', $account->id) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-success">{{ __('Reactivate') }}</button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
