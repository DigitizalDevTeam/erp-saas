@extends('layouts.admin')

@section('page-title')
    {{ __('Dashboard') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales Users & Clients') }}</li>
@endsection

@section('content')
    <div class="row mb-4">
        <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
            <div class="card card-body mb-3">
                <span class="text-muted text-sm">{{ __('Sales Users') }}</span>
                <h4 class="mb-0">{{ $salesData['totalSalesUsers'] }}</h4>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
            <div class="card card-body mb-3">
                <span class="text-muted text-sm">{{ __('Users Login Enabled') }}</span>
                <h4 class="mb-0">{{ $salesData['activeSalesUsers'] }}</h4>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
            <div class="card card-body mb-3">
                <span class="text-muted text-sm">{{ __('Clients') }}</span>
                <h4 class="mb-0">{{ $salesData['totalClients'] }}</h4>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
            <div class="card card-body mb-3">
                <span class="text-muted text-sm">{{ __('Clients Login Enabled') }}</span>
                <h4 class="mb-0">{{ $salesData['activeClients'] }}</h4>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
            <div class="card card-body mb-3">
                <span class="text-muted text-sm">{{ __('Active In Last 7 Days') }}</span>
                <h4 class="mb-0">{{ $salesData['recentlyActiveUsers'] }}</h4>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
            <div class="card card-body mb-3">
                <span class="text-muted text-sm">{{ __('License Remaining') }}</span>
                <h4 class="mb-0">
                    @if (is_null($salesData['licenseDaysRemaining']))
                        {{ __('Lifetime') }}
                    @else
                        {{ $salesData['licenseDaysRemaining'] }} {{ __('days') }}
                    @endif
                </h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Sales Users Authorization') }}</h5>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Role') }}</th>
                                    <th>{{ __('Authorization') }}</th>
                                    <th>{{ __('Last Login') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($salesData['salesUsers'] as $salesUser)
                                    <tr>
                                        <td>
                                            <div class="fw-bold">{{ $salesUser->name }}</div>
                                            <small class="text-muted">{{ $salesUser->email }}</small>
                                        </td>
                                        <td>{{ $salesUser->roles->pluck('name')->join(', ') ?: '-' }}</td>
                                        <td>
                                            @if ($salesUser->is_enable_login)
                                                <span class="badge bg-success">{{ __('Enabled') }}</span>
                                            @else
                                                <span class="badge bg-danger">{{ __('Disabled') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ !empty($salesUser->last_login_at) ? \Auth::user()->DateTimeFormat($salesUser->last_login_at) : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center">{{ __('No sales users found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Clients Access Overview') }}</h5>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Client') }}</th>
                                    <th>{{ __('Authorization') }}</th>
                                    <th>{{ __('Last Login') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($salesData['clients'] as $client)
                                    <tr>
                                        <td>
                                            <div class="fw-bold">{{ $client->name }}</div>
                                            <small class="text-muted">{{ $client->email }}</small>
                                        </td>
                                        <td>
                                            @if ($client->is_enable_login)
                                                <span class="badge bg-success">{{ __('Enabled') }}</span>
                                            @else
                                                <span class="badge bg-danger">{{ __('Disabled') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ !empty($client->last_login_at) ? \Auth::user()->DateTimeFormat($client->last_login_at) : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center">{{ __('No clients found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Recent Login Activities') }}</h5>
                </div>
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('IP') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Details') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($salesData['loginLogs'] as $log)
                                    <tr>
                                        <td>{{ $log->user_name }}</td>
                                        <td>{{ ucfirst($log->user_type) }}</td>
                                        <td>{{ $log->ip ?: '-' }}</td>
                                        <td>{{ !empty($log->date) ? \Auth::user()->DateTimeFormat($log->date) : '-' }}</td>
                                        <td>{{ $log->details ?: '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center">{{ __('No login activity found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
