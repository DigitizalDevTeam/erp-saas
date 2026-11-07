@extends('layouts.admin')
@section('page-title')
    {{ __('Unassigned / Invalid Allocations') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Unassigned / Invalid') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Lines that should carry a dimension but don’t, and dimensions that point at a centre that can no longer take them.') }} {{ __('Currency') }}: {{ $currency_code }}.</p>

                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.expense') }}">{{ __('Expense by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.cash') }}">{{ __('Cash outflow by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.reconciliation') }}">{{ __('Reconciliation') }}</a></li>
                        <li class="nav-item"><a class="nav-link active" href="{{ route('cost-centre-reports.unassigned') }}">{{ __('Unassigned / invalid') }}</a></li>
                    </ul>

                    <form method="GET" action="{{ route('cost-centre-reports.unassigned') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" class="form-control" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" class="form-control" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('cost-centre-reports.unassigned', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-secondary">{{ __('Export CSV') }}</a>
                        </div>
                    </form>

                    <h5>{{ __('Expense lines with no dimensions') }}</h5>
                    <div class="table-responsive mb-4">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Line') }}</th>
                                    <th>{{ __('Account') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($missing_dimensions as $line)
                                    <tr>
                                        <td>{{ $line['line_type'] }} #{{ $line['line_id'] }}</td>
                                        <td>{{ $line['account_code'] }}</td>
                                        <td>{{ $line['date'] }}</td>
                                        <td class="text-end">{{ number_format((float) $line['net'], 2) }}</td>
                                        <td>{{ $line['reason'] }}</td>
                                        <td>
                                            @if ($line['line_type'] === 'transaction_line' && in_array($line['tl_reference'], ['Bill', 'Bill Account'], true))
                                                <form method="POST" action="{{ route('gl-dimensions.sync-bill', (int) $line['tl_reference_id']) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Sync from allocation') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">{{ __('No expense lines are missing dimensions.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h5>{{ __('Dimension rows pointing at an invalid centre') }}</h5>
                    <div class="table-responsive mb-4">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Line') }}</th>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Source') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invalid_dimensions as $row)
                                    <tr>
                                        <td>{{ $row['line_type'] }} #{{ $row['line_id'] }}</td>
                                        <td>{{ $row['cost_centre_code'] ?? '#'.$row['cost_centre_id'] }}</td>
                                        <td class="text-end">{{ number_format((float) $row['allocated_amount'], 2) }}</td>
                                        <td>{{ $row['source'] }}</td>
                                        <td>{{ $row['reason'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No dimension rows point at an invalid centre.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h5>{{ __('Posted allocations whose centre is now inactive') }}</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Allocation') }}</th>
                                    <th>{{ __('Source') }}</th>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invalid_allocations as $row)
                                    <tr>
                                        <td>#{{ $row['allocation_id'] }}</td>
                                        <td>{{ $row['source_type'] }} #{{ $row['source_id'] }}</td>
                                        <td>{{ $row['cost_centre_code'] ?? '#'.$row['cost_centre_id'] }}</td>
                                        <td class="text-end">{{ number_format((float) $row['allocated_amount'], 2) }}</td>
                                        <td>{{ $row['reason'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No posted allocations point at an inactive centre.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
