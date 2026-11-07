@extends('layouts.admin')
@section('page-title')
    {{ $contract->reference }} — {{ __('Payments') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></li>
    <li class="breadcrumb-item">{{ __('Payments') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('billing-contracts.show', $contract->id) }}" class="btn btn-sm btn-secondary">{{ __('Back to contract') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Payments are recorded on the invoice itself (the normal invoice payment flow) — this screen only tracks them per contract. A partial payment never creates a second invoice for the same period.') }}</small></p>
                    <div class="row mb-3">
                        <div class="col-md-3"><strong>{{ __('Invoiced') }}</strong><br>{{ number_format((float) $summary['invoiced'], 2) }} {{ $contract->currency }}</div>
                        <div class="col-md-3"><strong>{{ __('Paid') }}</strong><br>{{ number_format((float) $summary['paid'], 2) }} {{ $contract->currency }}</div>
                        <div class="col-md-3"><strong>{{ __('Outstanding') }}</strong><br>{{ number_format((float) $summary['outstanding'], 2) }} {{ $contract->currency }}</div>
                        <div class="col-md-3"><strong>{{ __('Overdue') }}</strong><br><span class="{{ (float) $summary['overdue'] > 0 ? 'text-danger' : '' }}">{{ number_format((float) $summary['overdue'], 2) }} {{ $contract->currency }} ({{ $summary['overdue_count'] }})</span></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>{{ __('Period') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Issue date') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Total') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Due') }}</th><th>{{ __('State') }}</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td><a href="{{ route('billing-contracts.occurrence-show', [$contract->id, $row['occurrence']->id]) }}">{{ $row['occurrence']->period_start->format('Y-m-d') }} → {{ $row['occurrence']->coverageEndInclusive() }}</a></td>
                                        <td><a href="{{ route('invoice.show', \Crypt::encrypt($row['invoice']->id)) }}">{{ \Auth::user()->invoiceNumberFormat($row['invoice']->invoice_id) }}</a></td>
                                        <td>{{ $row['invoice']->issue_date }}</td>
                                        <td>{{ $row['invoice']->due_date }}</td>
                                        <td>{{ number_format((float) $row['total'], 2) }}</td>
                                        <td>{{ number_format((float) $row['paid'], 2) }}</td>
                                        <td>{{ number_format((float) $row['due'], 2) }}</td>
                                        <td>
                                            <span class="badge bg-{{ $row['state'] === 'paid' ? 'success' : ($row['state'] === 'overdue' ? 'danger' : ($row['state'] === 'partially_paid' ? 'warning' : 'secondary')) }}">{{ ucfirst(str_replace('_', ' ', $row['state'])) }}</span>
                                            @if ($row['days_overdue'] > 0)
                                                <small class="text-muted">({{ $row['days_overdue'] }} {{ __('days') }})</small>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">{{ __('No invoices issued yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
