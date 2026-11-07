@extends('layouts.admin')
@section('page-title')
    {{ __('Recurring Occurrences') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-recurring-rules.index') }}">{{ __('Recurring') }}</a></li>
    <li class="breadcrumb-item">{{ __('Occurrences') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-recurring-occurrences.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (\App\Models\TreasuryRecurringOccurrence::STATES as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst($state) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="date" name="from" class="form-control" value="{{ request('from') }}" onchange="this.form.submit()">
                        </div>
                        <div class="col-md-3">
                            <input type="date" name="to" class="form-control" value="{{ request('to') }}" onchange="this.form.submit()">
                        </div>
                    </form>

                    @if ($occurrences->isEmpty())
                        <p class="text-muted">{{ __('No occurrences in this range.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Scheduled') }}</th>
                                        <th>{{ __('Rule') }}</th>
                                        <th>{{ __('Period') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($occurrences as $occurrence)
                                        <tr>
                                            <td>{{ $occurrence->scheduled_date }}</td>
                                            <td><a href="{{ route('treasury-recurring-rules.show', $occurrence->rule_id) }}">{{ $occurrence->rule->beneficiary ?? '—' }}</a></td>
                                            <td>{{ $occurrence->period_key }}</td>
                                            <td class="text-end">{{ number_format((float) $occurrence->amount, 2) }} {{ $occurrence->currency }}</td>
                                            <td>{{ ucfirst($occurrence->state) }}</td>
                                            <td>
                                                @if ($occurrence->state === 'generated')
                                                    <form method="POST" action="{{ route('treasury-recurring-occurrences.confirm', $occurrence->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Confirm') }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('treasury-recurring-occurrences.paid', $occurrence->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Mark paid') }}</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('treasury-recurring-occurrences.skip', $occurrence->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Skip') }}</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $occurrences->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
