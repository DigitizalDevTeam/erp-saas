@extends('layouts.admin')
@section('page-title')
    {{ __('Expense by Cost Centre') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Expense by Cost Centre') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ $basis_label }} {{ __('Currency') }}: {{ $currency_code }}.</p>

                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item"><a class="nav-link active" href="{{ route('cost-centre-reports.expense') }}">{{ __('Expense by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.cash') }}">{{ __('Cash outflow by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.reconciliation') }}">{{ __('Reconciliation') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.unassigned') }}">{{ __('Unassigned / invalid') }}</a></li>
                    </ul>

                    <form method="GET" action="{{ route('cost-centre-reports.expense') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" class="form-control" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" class="form-control" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Cost centre') }}</label>
                            <select class="form-control" name="cost_centre_id">
                                <option value="">{{ __('All centres') }}</option>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (int) $selectedCentreId === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('cost-centre-reports.expense', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-secondary">{{ __('Export CSV') }}</a>
                            <a href="{{ route('cost-centre-reports.expense', array_merge(request()->query(), ['export' => 'xlsx'])) }}" class="btn btn-outline-secondary">{{ __('Export XLSX') }}</a>
                            <a href="{{ route('cost-centre-reports.expense', array_merge(request()->query(), ['export' => 'pdf'])) }}" class="btn btn-outline-secondary">{{ __('Export PDF') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th>{{ __('Project') }}</th>
                                    <th class="text-end">{{ __('Lines') }}</th>
                                    <th class="text-end">{{ __('Total') }} ({{ $currency_code }})</th>
                                    <th class="text-end">{{ __('Total incl. children') }} ({{ $currency_code }})</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td><strong>{{ $row['cost_centre_code'] }}</strong>@if ($row['cost_centre_name']) — {{ $row['cost_centre_name'] }} @endif</td>
                                        <td>{{ $row['project_id'] ?? '—' }}</td>
                                        <td class="text-end">{{ $row['rows'] }}</td>
                                        <td class="text-end">{{ number_format((float) $row['total'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['total_including_children'], 2) }}</td>
                                    </tr>
                                    @foreach ($row['accounts'] as $account)
                                        <tr>
                                            <td class="ps-4 text-muted">{{ __('Account') }} {{ $account['account_code'] ?? '#'.$account['account_id'] }}</td>
                                            <td></td>
                                            <td></td>
                                            <td class="text-end text-muted">{{ number_format((float) $account['total'], 2) }}</td>
                                            <td></td>
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No expenses in this period.') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3">{{ __('Total') }}</th>
                                    <th class="text-end">{{ number_format((float) $total, 2) }}</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
