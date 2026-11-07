@extends('layouts.admin')
@section('page-title')
    {{ __('Budget vs Actual') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Budget vs Actual') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('cost-centre-reports.budget', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('Export CSV') }}
        </a>
        <a href="{{ route('cost-centre-reports.budget', array_merge(request()->query(), ['export' => 'xlsx'])) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('Export XLSX') }}
        </a>
        <a href="{{ route('cost-centre-reports.budget', array_merge(request()->query(), ['export' => 'pdf'])) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('Export PDF') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('cost-centre-reports.budget') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <input type="number" name="fiscal_year" value="{{ $fiscal_year }}" class="form-control" min="2000" max="2100">
                        </div>
                        <div class="col-md-3">
                            <select name="period" class="form-control">
                                <option value="">{{ __('All months') }}</option>
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ (int) $selectedPeriod === $m ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', (string) $m)->format('F') }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="cost_centre_id" class="form-control">
                                <option value="">{{ __('All cost centres') }}</option>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (int) $selectedCentreId === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <p class="text-muted">{{ __('Accrual basis — actuals are the dimensioned GL expenses for the month. Available = approved − committed − actual. Rows with no budget at all are shown as "No budget" so spending without a budget stays visible.') }}</p>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost Centre') }}</th>
                                    <th>{{ __('Month') }}</th>
                                    <th>{{ __('Scope') }}</th>
                                    <th class="text-end">{{ __('Approved') }}</th>
                                    <th class="text-end">{{ __('Committed') }}</th>
                                    <th class="text-end">{{ __('Actual') }}</th>
                                    <th class="text-end">{{ __('Available') }}</th>
                                    <th class="text-end">{{ __('Variance') }}</th>
                                    <th class="text-end">{{ __('Variance %') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td>{{ $row['cost_centre_code'] }} — {{ $row['cost_centre_name'] }}</td>
                                        <td>{{ $row['fiscal_year'] }}-{{ str_pad((string) $row['period'], 2, '0', STR_PAD_LEFT) }}</td>
                                        <td>{{ $row['scope_key'] }}</td>
                                        <td class="text-end">{{ number_format((float) $row['approved'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['committed'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['actual'], 2) }}</td>
                                        <td class="text-end {{ (float) $row['available'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format((float) $row['available'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['variance'], 2) }}</td>
                                        <td class="text-end">{{ $row['variance_percent'] !== null ? number_format((float) $row['variance_percent'], 2).'%' : '—' }}</td>
                                        <td>
                                            @if ($row['status'] === 'over')
                                                <span class="badge bg-danger">{{ __('Over budget') }}</span>
                                            @elseif ($row['status'] === 'no_budget')
                                                <span class="badge bg-warning">{{ __('No budget') }}</span>
                                            @else
                                                <span class="badge bg-success">{{ __('Within budget') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center text-muted">{{ __('No budget or actual rows for this filter.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3">{{ __('Total') }}</td>
                                    <td class="text-end">{{ number_format((float) $totals['approved'], 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $totals['committed'], 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $totals['actual'], 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $totals['available'], 2) }}</td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
