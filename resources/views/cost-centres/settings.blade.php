@extends('layouts.admin')
@section('page-title')
    {{ __('Cost Centre Settings') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Settings') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('cost-centres.settings.save') }}">
                        @csrf

                        <div class="form-group">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="require_cost_centre_for_expenses" id="require_cost_centre_for_expenses" value="1" {{ ($settings['require_cost_centre_for_expenses'] ?? '0') === '1' ? 'checked' : '' }}>
                                <label class="form-check-label" for="require_cost_centre_for_expenses">{{ __('Require a cost centre for expenses') }}</label>
                            </div>
                            <small class="text-muted">{{ __('When on, expenses must carry a cost centre (enforced from Phase 1).') }}</small>
                        </div>

                        <div class="form-group">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_splits" id="allow_splits" value="1" {{ ($settings['allow_splits'] ?? '1') === '1' ? 'checked' : '' }}>
                                <label class="form-check-label" for="allow_splits">{{ __('Allow splits across several cost centres') }}</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="cost_centre_parents_summary_only" id="cost_centre_parents_summary_only" value="1" {{ ($settings['cost_centre_parents_summary_only'] ?? '1') === '1' ? 'checked' : '' }}>
                                <label class="form-check-label" for="cost_centre_parents_summary_only">{{ __('Parents are summary-only (children only take direct postings)') }}</label>
                            </div>
                            <small class="text-muted">{{ __('Plan decision D2: a parent centre never takes a direct posting — only its leaf children do.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="budget_policy">{{ __('Budget policy') }}</label>
                            <select name="budget_policy" id="budget_policy" class="form-control">
                                @foreach (['off' => __('Off'), 'warn' => __('Warn'), 'block' => __('Block'), 'approval' => __('Require approval')] as $value => $label)
                                    <option value="{{ $value }}" {{ ($settings['budget_policy'] ?? 'off') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Takes effect with budgets in Phase 5.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="budget_approval_threshold">{{ __('Approval threshold (over-budget amount)') }}</label>
                            <input type="number" step="0.01" min="0" name="budget_approval_threshold" id="budget_approval_threshold" class="form-control" value="{{ old('budget_approval_threshold', $settings['budget_approval_threshold'] ?? '0') }}">
                            <small class="text-muted">{{ __('Over-budget amounts at or below this need only the cost centre manager; above it the finance controller must also approve. 0 means every over-budget amount needs both steps.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="budget_finance_controller_user_id">{{ __('Finance controller') }}</label>
                            <select name="budget_finance_controller_user_id" id="budget_finance_controller_user_id" class="form-control">
                                <option value="">{{ __('Company user (default)') }}</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}" {{ (string) ($settings['budget_finance_controller_user_id'] ?? '') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }} ({{ $user->email }})</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Second approval step for over-budget requests. Leave empty for the company user.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="dimension_on_cash_transactions">{{ __('Cost centre on cash transactions') }}</label>
                            <select name="dimension_on_cash_transactions" id="dimension_on_cash_transactions" class="form-control">
                                <option value="optional" {{ ($settings['dimension_on_cash_transactions'] ?? 'optional') === 'optional' ? 'selected' : '' }}>{{ __('Optional') }}</option>
                                <option value="required" {{ ($settings['dimension_on_cash_transactions'] ?? 'optional') === 'required' ? 'selected' : '' }}>{{ __('Required') }}</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="period_lock_policy">{{ __('Period lock policy') }}</label>
                            <select name="period_lock_policy" id="period_lock_policy" class="form-control">
                                <option value="block" {{ ($settings['period_lock_policy'] ?? 'block') === 'block' ? 'selected' : '' }}>{{ __('Block changes in closed periods') }}</option>
                                <option value="warn" {{ ($settings['period_lock_policy'] ?? 'block') === 'warn' ? 'selected' : '' }}>{{ __('Warn') }}</option>
                                <option value="off" {{ ($settings['period_lock_policy'] ?? 'block') === 'off' ? 'selected' : '' }}>{{ __('Off') }}</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="rounding_account">{{ __('Rounding account (GL code)') }}</label>
                            <input type="text" name="rounding_account" id="rounding_account" class="form-control" value="{{ old('rounding_account', $settings['rounding_account'] ?? '') }}" maxlength="50">
                            <small class="text-muted">{{ __('GL account code that takes rounding residuals from splits (Phase 1). Leave empty for none.') }}</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="default_allocation_basis">{{ __('Default allocation basis') }}</label>
                            <select name="default_allocation_basis" id="default_allocation_basis" class="form-control">
                                <option value="percentage" {{ ($settings['default_allocation_basis'] ?? 'percentage') === 'percentage' ? 'selected' : '' }}>{{ __('Percentage') }}</option>
                                <option value="fixed_amount" {{ ($settings['default_allocation_basis'] ?? 'percentage') === 'fixed_amount' ? 'selected' : '' }}>{{ __('Fixed amount') }}</option>
                            </select>
                        </div>

                        <div class="form-group mt-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save Settings') }}</button>
                            <a href="{{ route('cost-centres.index') }}" class="btn btn-light">{{ __('Back to List') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
