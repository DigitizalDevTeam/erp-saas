@extends('layouts.admin')
@section('page-title')
    {{ __('Reconciliation') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reconciliation') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Dimension totals must equal the eligible journal line amounts. A dimension report must add up to the GL, never to a different total.') }} {{ __('Currency') }}: {{ $currency_code }}.</p>

                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.expense') }}">{{ __('Expense by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.cash') }}">{{ __('Cash outflow by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link active" href="{{ route('cost-centre-reports.reconciliation') }}">{{ __('Reconciliation') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.unassigned') }}">{{ __('Unassigned / invalid') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.depreciation') }}">{{ __('Depreciation by asset') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.maintenance') }}">{{ __('Maintenance by asset') }}</a></li>
                    </ul>

                    <form method="GET" action="{{ route('cost-centre-reports.reconciliation') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" class="form-control" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" class="form-control" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Scope') }}</label>
                            <select class="form-control" name="scope">
                                <option value="expense" {{ $scope === 'expense' ? 'selected' : '' }}>{{ __('Expense accounts only (class 6)') }}</option>
                                <option value="all" {{ $scope === 'all' ? 'selected' : '' }}>{{ __('All accounts') }}</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary">{{ __('Run check') }}</button>
                            <a href="{{ route('cost-centre-reports.reconciliation', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-secondary">{{ __('Export CSV') }}</a>
                            <a href="{{ route('cost-centre-reports.reconciliation', array_merge(request()->query(), ['export' => 'xlsx'])) }}" class="btn btn-outline-secondary">{{ __('Export XLSX') }}</a>
                            <a href="{{ route('cost-centre-reports.reconciliation', array_merge(request()->query(), ['export' => 'pdf'])) }}" class="btn btn-outline-secondary">{{ __('Export PDF') }}</a>
                        </div>
                    </form>

                    <div class="row mb-3">
                        <div class="col-md-3"><strong>{{ __('Lines checked') }}:</strong> {{ $lines_checked }}</div>
                        <div class="col-md-3"><strong>{{ __('GL total') }}:</strong> {{ number_format((float) $gl_total, 2) }}</div>
                        <div class="col-md-3"><strong>{{ __('Dimensioned total') }}:</strong> {{ number_format((float) $dimensioned_total, 2) }}</div>
                        <div class="col-md-3"><strong>{{ __('Difference') }}:</strong> {{ number_format((float) $difference, 2) }}
                            @if ($balanced)
                                <span class="badge bg-success">{{ __('Balanced') }}</span>
                            @else
                                <span class="badge bg-danger">{{ __('Out of balance') }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($mismatches === [] && $unassigned === [])
                        <div class="alert alert-success">{{ __('Every eligible line reconciles: dimension totals equal the GL amounts.') }}</div>
                    @endif

                    @if ($mismatches !== [])
                        <h5>{{ __('Lines whose dimensions do not sum to the line') }}</h5>
                        <div class="table-responsive mb-4">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Line') }}</th>
                                        <th>{{ __('Account') }}</th>
                                        <th>{{ __('Date') }}</th>
                                        <th class="text-end">{{ __('GL amount') }}</th>
                                        <th class="text-end">{{ __('Dimensioned') }}</th>
                                        <th class="text-end">{{ __('Difference') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($mismatches as $line)
                                        <tr>
                                            <td>{{ $line['line_type'] }} #{{ $line['line_id'] }}</td>
                                            <td>{{ $line['account_code'] }}</td>
                                            <td>{{ $line['date'] }}</td>
                                            <td class="text-end">{{ number_format((float) $line['net'], 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $line['dimensioned'], 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $line['net'] - (float) $line['dimensioned'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($unassigned !== [])
                        <h5>{{ __('Lines with no dimensions') }}</h5>
                        <p class="text-muted">{{ __('These lines are also listed on the unassigned report.') }}</p>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Line') }}</th>
                                        <th>{{ __('Account') }}</th>
                                        <th>{{ __('Date') }}</th>
                                        <th class="text-end">{{ __('GL amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($unassigned as $line)
                                        <tr>
                                            <td>{{ $line['line_type'] }} #{{ $line['line_id'] }}</td>
                                            <td>{{ $line['account_code'] }}</td>
                                            <td>{{ $line['date'] }}</td>
                                            <td class="text-end">{{ number_format((float) $line['net'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
