@extends('layouts.admin')
@section('page-title')
    {{ __('Cash Registers') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Cash Registers') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage treasury cash sessions')
            <a href="{{ route('treasury-cash-sessions.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Open Session') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-cash-sessions.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryCashSession::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst($state) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="register_id" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All registers') }}</option>
                                @foreach ($registers as $register)
                                    <option value="{{ $register->id }}" {{ (int) request('register_id') === (int) $register->id ? 'selected' : '' }}>{{ $register->code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($sessions->isEmpty())
                        <p class="text-muted">{{ __('No cash sessions yet. Open a session on a cash register to start counting the till.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Register') }}</th>
                                        <th>{{ __('Operator') }}</th>
                                        <th>{{ __('Opened') }}</th>
                                        <th>{{ __('Expected') }}</th>
                                        <th>{{ __('Counted') }}</th>
                                        <th>{{ __('Variance') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sessions as $session)
                                        <tr>
                                            <td>{{ $session->register ? $session->register->code : '—' }}</td>
                                            <td>{{ $session->operator ? $session->operator->name : '—' }}</td>
                                            <td>{{ $session->opened_at ? $session->opened_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td>{{ number_format((float) $session->expected_balance, 2) }} {{ $session->currency }}</td>
                                            <td>{{ $session->counted_balance !== null ? number_format((float) $session->counted_balance, 2) . ' ' . $session->currency : '—' }}</td>
                                            <td>
                                                @if ($session->variance_amount !== null)
                                                    <span class="{{ (float) $session->variance_amount < 0 ? 'text-danger' : ((float) $session->variance_amount > 0 ? 'text-success' : '') }}">{{ number_format((float) $session->variance_amount, 2) }} {{ $session->currency }}</span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td><span class="badge bg-{{ $session->state === 'closed' ? 'success' : ($session->state === 'locked' ? 'dark' : 'secondary') }}">{{ ucfirst($session->state) }}</span></td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-cash-sessions.show', $session) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $sessions->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
