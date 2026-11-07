@extends('layouts.admin')
@section('page-title')
    {{ isset($rule) ? __('Edit Recurring Rule') : __('New Recurring Rule') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-recurring-rules.index') }}">{{ __('Recurring') }}</a></li>
    <li class="breadcrumb-item">{{ isset($rule) ? __('Edit') : __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    @if (isset($rule))
                        <p class="text-muted"><small>{{ __('Editing the amount or schedule regenerates future unconfirmed occurrences. Confirmed, paid and skipped occurrences keep their amount.') }}</small></p>
                    @endif
                    <form method="POST" action="{{ isset($rule) ? route('treasury-recurring-rules.update', $rule->id) : route('treasury-recurring-rules.store') }}">
                        @csrf
                        @if (isset($rule))
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="beneficiary">{{ __('Beneficiary') }} *</label>
                                    <input type="text" name="beneficiary" id="beneficiary" class="form-control" value="{{ old('beneficiary', $rule->beneficiary ?? '') }}" maxlength="150" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="category">{{ __('Category') }}</label>
                                    <input type="text" name="category" id="category" class="form-control" value="{{ old('category', $rule->category ?? '') }}" maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">{{ __('Description') }}</label>
                            <input type="text" name="description" id="description" class="form-control" value="{{ old('description', $rule->description ?? '') }}" maxlength="250">
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="direction">{{ __('Direction') }} *</label>
                                    <select name="direction" id="direction" class="form-control" required>
                                        <option value="outflow" {{ old('direction', $rule->direction ?? 'outflow') === 'outflow' ? 'selected' : '' }}>{{ __('Outflow (we pay)') }}</option>
                                        <option value="inflow" {{ old('direction', $rule->direction ?? '') === 'inflow' ? 'selected' : '' }}>{{ __('Inflow (we receive)') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="amount">{{ __('Amount') }} ({{ $baseCurrency }}) *</label>
                                    <input type="number" name="amount" id="amount" class="form-control" value="{{ old('amount', isset($rule) ? $rule->amount : '') }}" min="0.0001" step="0.0001" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="amount_type">{{ __('Amount type') }}</label>
                                    <select name="amount_type" id="amount_type" class="form-control">
                                        <option value="fixed" {{ old('amount_type', $rule->amount_type ?? 'fixed') === 'fixed' ? 'selected' : '' }}>{{ __('Fixed') }}</option>
                                        <option value="estimated" {{ old('amount_type', $rule->amount_type ?? '') === 'estimated' ? 'selected' : '' }}>{{ __('Estimated') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="frequency">{{ __('Frequency') }} *</label>
                                    <select name="frequency" id="frequency" class="form-control" required>
                                        @foreach (['monthly' => __('Monthly'), 'quarterly' => __('Quarterly'), 'semi-annual' => __('Semi-annual'), 'annual' => __('Annual'), 'custom' => __('Custom')] as $value => $label)
                                            <option value="{{ $value }}" {{ old('frequency', $rule->frequency ?? 'monthly') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="custom_interval">{{ __('Every (custom only)') }}</label>
                                    <input type="number" name="custom_interval" id="custom_interval" class="form-control" value="{{ old('custom_interval', $rule->custom_interval ?? '') }}" min="1">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="custom_unit">{{ __('Unit (custom only)') }}</label>
                                    <select name="custom_unit" id="custom_unit" class="form-control">
                                        <option value="">{{ __('—') }}</option>
                                        @foreach (['days' => __('Days'), 'weeks' => __('Weeks'), 'months' => __('Months'), 'years' => __('Years')] as $value => $label)
                                            <option value="{{ $value }}" {{ old('custom_unit', $rule->custom_unit ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="start_date">{{ __('Start date') }} *</label>
                                    <input type="date" name="start_date" id="start_date" class="form-control" value="{{ old('start_date', isset($rule) ? $rule->start_date : date('Y-m-d')) }}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="end_date">{{ __('End date') }}</label>
                                    <input type="date" name="end_date" id="end_date" class="form-control" value="{{ old('end_date', $rule->end_date ?? '') }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="due_day">{{ __('Due day of month') }}</label>
                                    <input type="number" name="due_day" id="due_day" class="form-control" value="{{ old('due_day', $rule->due_day ?? '') }}" min="1" max="31" placeholder="{{ __('Start day') }}">
                                    <small class="text-muted">{{ __('31 January → 28 February → 31 March. Leave empty to use the start day.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="planned_account_id">{{ __('Planned account') }}</label>
                                    <select name="planned_account_id" id="planned_account_id" class="form-control">
                                        <option value="">{{ __('—') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (string) old('planned_account_id', $rule->planned_account_id ?? '') === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->bankAccount->holder_name ?? $account->bankAccount->bank_name ?? '' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Confirmation') }}</label>
                                    <div class="form-check">
                                        <input type="checkbox" name="approval_required" id="approval_required" class="form-check-input" value="1" {{ old('approval_required', $rule->approval_required ?? false) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="approval_required">{{ __('Confirmation by another user required') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ isset($rule) ? __('Save changes') : __('Create rule') }}</button>
                        <a href="{{ route('treasury-recurring-rules.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
