@extends('layouts.admin')
@section('page-title')
    {{ __('Profitability by Centre and Project') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Profitability by Centre and Project') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ $basis_label }} {{ __('Currency') }}: {{ $currency_code }}.</p>

                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.expense') }}">{{ __('Expense by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.cash') }}">{{ __('Cash outflow by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.reconciliation') }}">{{ __('Reconciliation') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.unassigned') }}">{{ __('Unassigned / invalid') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.depreciation') }}">{{ __('Depreciation by asset') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.maintenance') }}">{{ __('Maintenance by asset') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.project-centre') }}">{{ __('Cost by project & centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link active" href="{{ route('cost-centre-reports.profitability') }}">{{ __('Profitability') }}</a></li>
                    </ul>

                    <form method="GET" action="{{ route('cost-centre-reports.profitability') }}" class="row g-2 mb-3">
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
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Project') }}</label>
                            <select class="form-control" name="project_id">
                                <option value="">{{ __('All projects') }}</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}" {{ (int) $selectedProjectId === (int) $project->id ? 'selected' : '' }}>{{ $project->project_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('cost-centre-reports.profitability', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-secondary">{{ __('Export CSV') }}</a>
                            <a href="{{ route('cost-centre-reports.profitability', array_merge(request()->query(), ['export' => 'xlsx'])) }}" class="btn btn-outline-secondary">{{ __('Export XLSX') }}</a>
                            <a href="{{ route('cost-centre-reports.profitability', array_merge(request()->query(), ['export' => 'pdf'])) }}" class="btn btn-outline-secondary">{{ __('Export PDF') }}</a>
                        </div>
                    </form>

                    @if(isset($reconciliation))
                        <div class="alert {{ $reconciliation['matches'] ? 'alert-success' : 'alert-warning' }}">
                            {{ __('Reconciliation') }}:
                            {{ __('Revenue — GL') }} {{ number_format((float) $reconciliation['revenue_gl_total'], 2) }} ·
                            {{ __('dimensioned') }} {{ number_format((float) $reconciliation['revenue_dimensioned_total'], 2) }} ·
                            {{ __('undimensioned') }} {{ number_format((float) $reconciliation['revenue_undimensioned_total'], 2) }}<br>
                            {{ __('Cost — GL') }} {{ number_format((float) $reconciliation['cost_gl_total'], 2) }} ·
                            {{ __('dimensioned') }} {{ number_format((float) $reconciliation['cost_dimensioned_total'], 2) }} ·
                            {{ __('undimensioned') }} {{ number_format((float) $reconciliation['cost_undimensioned_total'], 2) }} ·
                            {{ __('Production') }} {{ number_format((float) $reconciliation['production_total'], 2) }} —
                            {{ $reconciliation['matches'] ? __('Reconciled') : __('Not reconciled — see the Reconciliation report') }}
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th>{{ __('Project') }}</th>
                                    <th class="text-end">{{ __('Revenue') }} ({{ $currency_code }})</th>
                                    <th class="text-end">{{ __('Cost') }} ({{ $currency_code }})</th>
                                    <th class="text-end">{{ __('Production') }} ({{ $currency_code }})</th>
                                    <th class="text-end">{{ __('Margin') }} ({{ $currency_code }})</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td><strong>{{ $row['cost_centre_code'] ?? __('Unassigned') }}</strong>@if ($row['cost_centre_name'] ?? null) — {{ $row['cost_centre_name'] }} @endif</td>
                                        <td>{{ $row['project_name'] ?? __('Unassigned') }}</td>
                                        <td class="text-end">{{ number_format((float) $row['revenue'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['cost'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['production'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $row['margin'], 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">{{ __('No revenue or costs in this period.') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">{{ __('Total') }}</th>
                                    <th class="text-end">{{ number_format((float) $revenue_total, 2) }}</th>
                                    <th class="text-end">{{ number_format((float) $cost_total, 2) }}</th>
                                    <th class="text-end">{{ number_format((float) $production_total, 2) }}</th>
                                    <th class="text-end">{{ number_format((float) $margin_total, 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
