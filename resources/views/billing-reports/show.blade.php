@extends('layouts.admin')
@section('page-title')
    {{ __($data['title']) }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('billing-reports.index') }}">{{ __('Billing Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __($data['title']) }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('billing-reports.show', ['report' => $reportKey, 'export' => 'csv'] + request()->except(['export', 'page'])) }}" class="btn btn-sm btn-secondary">{{ __('CSV') }}</a>
        <a href="{{ route('billing-reports.show', ['report' => $reportKey, 'export' => 'xlsx'] + request()->except(['export', 'page'])) }}" class="btn btn-sm btn-secondary">{{ __('Excel') }}</a>
        <a href="{{ route('billing-reports.show', ['report' => $reportKey, 'export' => 'pdf'] + request()->except(['export', 'page'])) }}" class="btn btn-sm btn-secondary">{{ __('PDF') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __($data['description']) }}</small></p>

                    @if (! empty($data['reconciliation']))
                        @if ($data['reconciliation']['matches'])
                            <div class="alert alert-success">
                                {{ __('Reconciled:') }} {{ __($data['reconciliation']['label']) }}.
                            </div>
                        @else
                            <div class="alert alert-danger">
                                {{ __('RECONCILIATION BREAK:') }} {{ __($data['reconciliation']['label']) }} — {{ __('the report does not match its source. This is a bug, not a rounding difference.') }}
                            </div>
                        @endif
                    @endif

                    <form method="GET" action="{{ route('billing-reports.show', $reportKey) }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <input type="date" name="from" class="form-control" value="{{ $filters['from'] ?? '' }}" placeholder="{{ __('From') }}">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="to" class="form-control" value="{{ $filters['to'] ?? '' }}" placeholder="{{ __('To') }}">
                        </div>
                        <div class="col-md-2">
                            <select name="customer_id" class="form-control">
                                <option value="">{{ __('All customers') }}</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}" {{ (string) ($filters['customer_id'] ?? '') === (string) $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="contract_id" class="form-control">
                                <option value="">{{ __('All contracts') }}</option>
                                @foreach ($contracts as $contract)
                                    <option value="{{ $contract->id }}" {{ (string) ($filters['contract_id'] ?? '') === (string) $contract->id ? 'selected' : '' }}>{{ $contract->reference }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="frequency" class="form-control">
                                <option value="">{{ __('All frequencies') }}</option>
                                @foreach (\App\Models\BillingContract::FREQUENCIES as $frequency)
                                    <option value="{{ $frequency }}" {{ ($filters['frequency'] ?? '') === $frequency ? 'selected' : '' }}>{{ ucfirst(str_replace('-', ' ', $frequency)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="search" class="form-control" placeholder="{{ __('Search') }}" value="{{ $filters['search'] ?? '' }}">
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="days" class="form-control" min="1" max="365" value="{{ $filters['days'] ?? 30 }}" title="{{ __('Expiry window (days)') }}">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Filter') }}</button>
                            <a href="{{ route('billing-reports.show', $reportKey) }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>
                        </div>
                    </form>

                    @if (count($data['rows']) > 0)
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        @foreach ($data['headings'] as $heading)
                                            <th>{{ $heading }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data['rows'] as $row)
                                        <tr>
                                            @foreach ($row as $cell)
                                                <td>{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No rows for these filters.') }}</p>
                    @endif

                    @if (! empty($data['totals']))
                        <p>
                            @foreach ($data['totals'] as $label => $value)
                                <strong>{{ $label }}:</strong> {{ $value }}&nbsp;&nbsp;
                            @endforeach
                        </p>
                    @endif

                    @if ($exports->count() > 0)
                        <h5 class="mt-4">{{ __('Recent exports') }}</h5>
                        <ul>
                            @foreach ($exports as $export)
                                <li>
                                    {{ strtoupper($export->format) }} — {{ ucfirst($export->status) }}
                                    @if (isset($downloadUrls[$export->id]))
                                        <a href="{{ $downloadUrls[$export->id] }}">{{ __('Download') }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
