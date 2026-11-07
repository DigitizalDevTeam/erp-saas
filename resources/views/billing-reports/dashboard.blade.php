@extends('layouts.admin')
@section('page-title')
    {{ __('Billing Dashboard') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales') }}</li>
    <li class="breadcrumb-item">{{ __('Billing Dashboard') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('billing-reports.index') }}" class="btn btn-sm btn-secondary">
            {{ __('Billing Reports') }}
        </a>
        <a href="{{ route('billing-contracts.index') }}" class="btn btn-sm btn-primary">
            {{ __('Billing Contracts') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Billing overview for your company. Forecast figures are ESTIMATES — planned amounts are not money until the invoice is issued. Planned and issued are always shown separately, never mixed.') }}</small></p>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Active contracts') }}</h6>
                                    <h3>{{ $data['active_contracts'] }}</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Forecast this month (estimate)') }}</h6>
                                    @forelse ($data['forecast_this_month'] as $currency => $amount)
                                        <h5>{{ $currency }} {{ number_format((float) $amount, 2) }}</h5>
                                    @empty
                                        <h5>—</h5>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Issued this month (subtotal)') }}</h6>
                                    @forelse ($data['issued_this_month'] as $currency => $amount)
                                        <h5>{{ $currency }} {{ number_format((float) $amount, 2) }}</h5>
                                    @empty
                                        <h5>—</h5>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Overdue balance') }} ({{ $data['overdue_count'] }})</h6>
                                    @forelse ($data['overdue_balance'] as $currency => $amount)
                                        <h5>{{ $currency }} {{ number_format((float) $amount, 2) }}</h5>
                                    @empty
                                        <h5>—</h5>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($data['failed_deliveries'] > 0 || $data['stuck_generated'] > 0)
                        <div class="alert alert-warning mt-3">
                            {{ __('Failed deliveries (final):') }} {{ $data['failed_deliveries'] }} —
                            {{ __('Occurrences stuck mid-generation:') }} {{ $data['stuck_generated'] }}.
                            {{ __('See the Delivery Failures report for detail; a failed delivery never creates a second invoice.') }}
                            <a href="{{ route('billing-reports.show', 'delivery-failures') }}">{{ __('Open report') }}</a>
                        </div>
                    @endif

                    <h5 class="mt-4">{{ __('Contracts ending soon') }} ({{ $days }} {{ __('days') }})</h5>
                    @if (count($data['expiring_soon']) > 0)
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Reference') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('End date') }}</th>
                                    <th>{{ __('Days left') }}</th>
                                    <th>{{ __('Period amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data['expiring_soon'] as $contract)
                                    <tr>
                                        <td><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></td>
                                        <td>{{ $contract->customer ? $contract->customer->name : '—' }}</td>
                                        <td>{{ $contract->end_date ? $contract->end_date->format('Y-m-d') : '—' }}</td>
                                        <td>{{ $contract->end_date ? (int) \Carbon\Carbon::today()->diffInDays($contract->end_date) : '—' }}</td>
                                        <td>{{ $contract->currency }} {{ number_format((float) collect($contract->lines)->sum(fn ($line) => (float) $line->amount), 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-muted">{{ __('No contracts ending in the next') }} {{ $days }} {{ __('days.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
