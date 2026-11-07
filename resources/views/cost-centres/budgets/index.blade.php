@extends('layouts.admin')
@section('page-title')
    {{ __('Cost Centre Budgets') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Budgets') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('approve cost centre budgets')
            <a href="{{ route('cost-centre-budget-approvals.index') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-check"></i> {{ __('Approvals') }}
            </a>
        @endcan
        @can('manage cost centre budgets')
            <a href="{{ route('cost-centre-budgets.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Budget') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('cost-centre-budgets.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <input type="number" name="fiscal_year" value="{{ $fiscalYear }}" class="form-control" min="2000" max="2100" placeholder="{{ __('Fiscal year') }}">
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

                    <p class="text-muted">{{ __('Only approved budgets limit spending. A draft budget counts as zero until it is approved. Available = approved − committed − actual.') }}</p>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost Centre') }}</th>
                                    <th>{{ __('Month') }}</th>
                                    <th>{{ __('Scope') }}</th>
                                    <th class="text-end">{{ __('Budget') }}</th>
                                    <th class="text-end">{{ __('Approved') }}</th>
                                    <th class="text-end">{{ __('Committed') }}</th>
                                    <th class="text-end">{{ __('Actual') }}</th>
                                    <th class="text-end">{{ __('Available') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    @php $budget = $row['budget']; $figures = $row['figures']; @endphp
                                    <tr>
                                        <td>{{ $budget->centre?->code }} — {{ $budget->centre?->name }}</td>
                                        <td>{{ $budget->fiscal_year }}-{{ str_pad((string) $budget->period, 2, '0', STR_PAD_LEFT) }}</td>
                                        <td>{{ $budget->scope_key }}</td>
                                        <td class="text-end">{{ number_format((float) $budget->budget_amount, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $figures['approved'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $figures['committed'], 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $figures['actual'], 2) }}</td>
                                        <td class="text-end {{ (float) $figures['available'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format((float) $figures['available'], 2) }}</td>
                                        <td>
                                            @if ($budget->status === 'approved')
                                                <span class="badge bg-success">{{ __('Approved') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ __('Draft') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @can('manage cost centre budgets')
                                                @if ($budget->status === 'draft')
                                                    <a href="{{ route('cost-centre-budgets.edit', $budget) }}" class="btn btn-sm btn-light">{{ __('Edit') }}</a>
                                                @else
                                                    <form method="POST" action="{{ route('cost-centre-budgets.reopen', $budget) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-light" onclick="return confirm('{{ __('Reopen this budget as a draft? Its approved figure returns to zero until it is approved again.') }}')">{{ __('Reopen') }}</button>
                                                    </form>
                                                @endif
                                            @endcan
                                            @can('approve cost centre budgets')
                                                @if ($budget->status === 'draft')
                                                    <form method="POST" action="{{ route('cost-centre-budgets.approve', $budget) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-primary">{{ __('Approve') }}</button>
                                                    </form>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center text-muted">{{ __('No budgets found for this filter.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
