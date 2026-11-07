@extends('layouts.admin')
@section('page-title')
    {{ __('Due occurrences') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Due occurrences') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Pending occurrences whose billing date has been reached, across all active contracts. Generate each one, or run the daily job billing:generate-invoices.') }}</small></p>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Contract') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Period') }}</th>
                                    <th>{{ __('Coverage') }}</th>
                                    <th>{{ __('Billing date') }}</th>
                                    <th>{{ __('Due date') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($occurrences as $occurrence)
                                    <tr>
                                        <td><a href="{{ route('billing-contracts.show', $occurrence->contract_id) }}">{{ $occurrence->contract->reference }}</a></td>
                                        <td>{{ $occurrence->contract->customer->name ?? '—' }}</td>
                                        <td><a href="{{ route('billing-contracts.occurrence-show', [$occurrence->contract_id, $occurrence->id]) }}">{{ $occurrence->period_key }}</a></td>
                                        <td>{{ $occurrence->period_start->format('Y-m-d') }} → {{ $occurrence->coverageEndInclusive() }}</td>
                                        <td>{{ $occurrence->billing_date->format('Y-m-d') }}</td>
                                        <td>{{ $occurrence->due_date->format('Y-m-d') }}</td>
                                        <td>{{ number_format((float) $occurrence->amount, 2) }} {{ $occurrence->currency }}</td>
                                        <td>
                                            @can('manage billing contracts')
                                                <form method="POST" action="{{ route('billing-contracts.generate-occurrence', [$occurrence->contract_id, $occurrence->id]) }}" class="d-inline">
                                                    @csrf
                                                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Generate') }}</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">{{ __('No due occurrences.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $occurrences->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
