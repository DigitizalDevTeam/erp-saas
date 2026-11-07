@extends('layouts.admin')
@section('page-title')
    {{ __('Cost Centre Dashboard') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Dashboard') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Budget status per cost centre for the month. Expense is accrual basis (recognised at the bill); cash is what was paid in the month. Currency') }}: {{ $currency_code }}.</p>

                    <form method="GET" action="{{ route('cost-centre-dashboard.index') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Fiscal year') }}</label>
                            <input type="number" class="form-control" name="fiscal_year" value="{{ $fiscal_year }}" min="2000" max="2100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Month') }}</label>
                            <select class="form-control" name="period">
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ (int) $selectedPeriod === $m ? 'selected' : '' }}>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="card bg-light"><div class="card-body py-2">
                                <small class="text-muted">{{ __('Approved') }}</small>
                                <div class="h5 mb-0">{{ number_format((float) $totals['approved'], 2) }}</div>
                            </div></div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light"><div class="card-body py-2">
                                <small class="text-muted">{{ __('Committed') }}</small>
                                <div class="h5 mb-0">{{ number_format((float) $totals['committed'], 2) }}</div>
                            </div></div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light"><div class="card-body py-2">
                                <small class="text-muted">{{ __('Actual') }}</small>
                                <div class="h5 mb-0">{{ number_format((float) $totals['actual'], 2) }}</div>
                            </div></div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light"><div class="card-body py-2">
                                <small class="text-muted">{{ __('Available') }}</small>
                                <div class="h5 mb-0">{{ number_format((float) $totals['available'], 2) }}</div>
                            </div></div>
                        </div>
                    </div>

                    @if (! empty($warnings))
                        <div class="alert alert-warning">
                            <strong>{{ __('Overspend warnings') }}</strong>
                            <ul class="mb-0">
                                @foreach ($warnings as $warning)
                                    <li><strong>{{ $warning['cost_centre_code'] }}</strong> — {{ $warning['message'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th class="text-end">{{ __('Approved') }}</th>
                                    <th class="text-end">{{ __('Committed') }}</th>
                                    <th class="text-end">{{ __('Actual') }}</th>
                                    <th class="text-end">{{ __('Available') }}</th>
                                    <th class="text-end">{{ __('Variance %') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($centres as $centre)
                                    <tr>
                                        <td><strong>{{ $centre['cost_centre_code'] }}</strong>@if ($centre['cost_centre_name']) — {{ $centre['cost_centre_name'] }} @endif</td>
                                        <td class="text-end">{{ number_format((float) $centre['approved'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $centre['committed'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $centre['actual'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $centre['available'], 2) }}</td>
                                        <td class="text-end">{{ $centre['variance_percent'] !== null ? $centre['variance_percent'].'%' : '—' }}</td>
                                        <td>
                                            @if ($centre['status'] === 'over')
                                                <span class="badge bg-danger">{{ __('Over budget') }}</span>
                                            @elseif ($centre['status'] === 'no_budget')
                                                <span class="badge bg-warning">{{ __('No budget') }}</span>
                                            @else
                                                <span class="badge bg-success">{{ __('Within budget') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('cost-centre-allocations.index', ['cost_centre_id' => $centre['cost_centre_id']]) }}" class="btn btn-sm btn-outline-primary">{{ __('Allocation ledger') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">{{ __('No centres with budgets or spending in this month.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ __('Expense this month (accrual)') }}</span><strong>{{ number_format((float) $expense_total, 2) }} {{ $currency_code }}</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ __('Cash paid this month') }}</span><strong>{{ number_format((float) $cash_total, 2) }} {{ $currency_code }}</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ __('Pending budget approvals') }}</span>
                                    <a href="{{ route('cost-centre-budget-approvals.index') }}"><strong>{{ $pending_approvals }}</strong></a>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ __('Reconciliation') }}</span>
                                    @if ($reconciliation['balanced'])
                                        <span class="badge bg-success">{{ __('Balanced') }}</span>
                                    @else
                                        <span class="badge bg-danger">{{ __('Difference') }} {{ number_format((float) $reconciliation['difference'], 2) }}</span>
                                    @endif
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ __('Lines checked') }}</span><strong>{{ $reconciliation['lines_checked'] }}</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>{{ __('Lines missing dimensions') }}</span>
                                    <a href="{{ route('cost-centre-reports.unassigned', ['from' => $from, 'to' => $to]) }}"><strong>{{ $unassigned_counts['missing_dimensions'] }}</strong></a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
