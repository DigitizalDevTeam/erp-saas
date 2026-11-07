@extends('layouts.admin')
@section('page-title')
    {{ $budget->exists ? __('Edit Budget') : __('Create Budget') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centre-budgets.index') }}">{{ __('Budgets') }}</a></li>
    <li class="breadcrumb-item">{{ $budget->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $budget->exists ? route('cost-centre-budgets.update', $budget) : route('cost-centre-budgets.store') }}">
                        @csrf
                        @if ($budget->exists)
                            @method('PUT')
                        @endif

                        <div class="form-group">
                            <label class="form-label" for="cost_centre_id">{{ __('Cost Centre') }}</label>
                            <select name="cost_centre_id" id="cost_centre_id" class="form-control" {{ $budget->exists ? 'disabled' : '' }} required>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (int) old('cost_centre_id', $budget->cost_centre_id) === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                @endforeach
                            </select>
                            @if ($budget->exists)
                                <small class="text-muted">{{ __('The centre cannot change after creation — a different centre is a different budget.') }}</small>
                            @endif
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fiscal_year">{{ __('Fiscal year') }}</label>
                                    <input type="number" name="fiscal_year" id="fiscal_year" class="form-control" min="2000" max="2100" value="{{ old('fiscal_year', $budget->fiscal_year) }}" {{ $budget->exists ? 'disabled' : '' }} required>
                                    <small class="text-muted">{{ __('Calendar year — the app closes the year on Dec 31.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="period">{{ __('Month') }}</label>
                                    <select name="period" id="period" class="form-control" {{ $budget->exists ? 'disabled' : '' }} required>
                                        @for ($m = 1; $m <= 12; $m++)
                                            <option value="{{ $m }}" {{ (int) old('period', $budget->period) === $m ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', (string) $m)->format('F') }}</option>
                                        @endfor
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="gl_account_id">{{ __('GL account (optional)') }}</label>
                                    <select name="gl_account_id" id="gl_account_id" class="form-control" {{ $budget->exists ? 'disabled' : '' }}>
                                        <option value="">{{ __('Whole centre — all expense accounts') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (int) old('gl_account_id', $budget->gl_account_id) === (int) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="project_id">{{ __('Project (optional)') }}</label>
                                    <select name="project_id" id="project_id" class="form-control" {{ $budget->exists ? 'disabled' : '' }}>
                                        <option value="">{{ __('All projects') }}</option>
                                        @foreach ($projects as $project)
                                            <option value="{{ $project->id }}" {{ (int) old('project_id', $budget->project_id) === (int) $project->id ? 'selected' : '' }}>{{ $project->project_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="budget_amount">{{ __('Budget amount') }}</label>
                            <input type="number" step="0.01" min="0" name="budget_amount" id="budget_amount" class="form-control" value="{{ old('budget_amount', $budget->budget_amount) }}" required>
                            <small class="text-muted">{{ __('The planned figure. It only limits spending once the budget is approved.') }}</small>
                        </div>

                        <div class="form-group mt-3">
                            <button type="submit" class="btn btn-primary">{{ $budget->exists ? __('Update Budget') : __('Create Budget') }}</button>
                            <a href="{{ route('cost-centre-budgets.index') }}" class="btn btn-light">{{ __('Back to List') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
