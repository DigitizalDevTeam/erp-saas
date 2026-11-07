@extends('layouts.admin')
@section('page-title')
    {{ __('Account Statement') }} — {{ $account->code }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-accounts.index') }}">{{ __('Treasury Accounts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Statement') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h5>{{ $account->displayName() }}</h5>
                    <p class="text-muted">{{ $account->typeLabel() }} · {{ $account->currency }} · {{ $account->isActive() ? __('Active') : __('Inactive') }}</p>

                    <form method="GET" action="{{ route('treasury-accounts.statement', $account->id) }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label" for="from">{{ __('From') }}</label>
                            <input type="date" name="from" id="from" class="form-control" value="{{ $from }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="to">{{ __('To') }}</label>
                            <input type="date" name="to" id="to" class="form-control" value="{{ $to }}">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <p><strong>{{ __('Pre-period balance') }}:</strong> {{ number_format((float) $statement['pre_period_balance'], 2) }} {{ $account->currency }}</p>

                    @if (empty($statement['lines']))
                        <p class="text-muted">{{ __('No confirmed opening balances in this period.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Date') }}</th>
                                        <th>{{ __('Description') }}</th>
                                        <th class="text-end">{{ __('Amount') }} ({{ $account->currency }})</th>
                                        <th class="text-end">{{ __('Running Balance') }} ({{ $account->currency }})</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($statement['lines'] as $line)
                                        <tr>
                                            <td>{{ $line['date'] }}</td>
                                            <td>{{ $line['description'] }}</td>
                                            <td class="text-end">{{ number_format((float) $line['amount_base'], 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $line['running_balance'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <p><strong>{{ __('Closing balance') }}:</strong> {{ number_format((float) $statement['closing_balance'], 2) }} {{ $account->currency }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
