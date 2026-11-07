@extends('layouts.admin')
@section('page-title')
    {{ __('End Contract') }} — {{ $contract->reference }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></li>
    <li class="breadcrumb-item">{{ __('End') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Ending a contract cancels its unissued periods starting on or after the effective date. The period that straddles the date is re-priced to the days actually covered. Outstanding invoices stay collectible.') }}</small></p>
                    <form method="GET" action="{{ route('billing-contracts.end-form', $contract->id) }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Effective date') }}</label>
                            <input type="date" name="effective_date" class="form-control" value="{{ $effectiveDate }}">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-secondary mt-4" type="submit">{{ __('Preview') }}</button>
                        </div>
                    </form>

                    <h6>{{ __('Unissued periods affected') }} ({{ count($affected) }})</h6>
                    @if (count($affected) > 0)
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>{{ __('Coverage') }}</th><th>{{ __('Billing date') }}</th><th>{{ __('Amount') }}</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($affected as $occurrence)
                                        <tr>
                                            <td>{{ $occurrence->period_start->format('Y-m-d') }} → {{ $occurrence->coverageEndInclusive() }}</td>
                                            <td>{{ $occurrence->billing_date->format('Y-m-d') }}</td>
                                            <td>{{ number_format((float) $occurrence->amount, 2) }} {{ $occurrence->currency }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No unissued periods start on or after this date.') }}</p>
                    @endif

                    <form method="POST" action="{{ route('billing-contracts.end', $contract->id) }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="effective_date" value="{{ $effectiveDate }}">
                        <div class="form-group">
                            <label class="form-label">{{ __('Reason') }}</label>
                            <input type="text" name="reason" class="form-control" placeholder="{{ __('Why is this contract ending?') }}">
                        </div>
                        <button type="submit" class="btn btn-danger">{{ __('End contract') }}</button>
                        <a href="{{ route('billing-contracts.show', $contract->id) }}" class="btn btn-light">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
