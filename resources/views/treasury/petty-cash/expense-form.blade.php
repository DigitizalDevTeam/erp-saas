@extends('layouts.admin')
@section('page-title')
    {{ __('Record Petty-Cash Expense') }} — {{ __('Fund') }} #{{ $fund->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-petty-cash.index') }}">{{ __('Petty Cash') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-petty-cash.show', $fund) }}">{{ __('Fund') }} #{{ $fund->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Expense') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="mb-3">{{ __('The expense is recorded on the existing Expense model with this fund and the cost centre on the line. It starts pending, then is approved, then executed — the fund pays out only at execution.') }}</p>
                    <form method="POST" action="{{ route('treasury-petty-cash.record-expense', $fund) }}">
                        @csrf
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name', '') }}" required maxlength="255">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                                <input type="number" step="0.0001" min="0.0001" name="amount" class="form-control" value="{{ old('amount', '') }}" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
                                <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Category') }} <span class="text-danger">*</span></label>
                                <select name="expense_category_id" class="form-control" required>
                                    <option value="">{{ __('Select a category') }}</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" {{ (int) old('expense_category_id') === (int) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Expense Chart Account') }} <span class="text-danger">*</span></label>
                                <select name="chart_account_id" class="form-control" required>
                                    <option value="">{{ __('Select a chart account') }}</option>
                                    @foreach ($chartAccounts as $chartAccount)
                                        <option value="{{ $chartAccount->id }}" {{ (int) old('chart_account_id') === (int) $chartAccount->id ? 'selected' : '' }}>{{ $chartAccount->code }} — {{ $chartAccount->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Cost Centre (optional)') }}</label>
                                <select name="cost_centre_id" class="form-control">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($costCentres as $costCentre)
                                        <option value="{{ $costCentre->id }}" {{ (int) old('cost_centre_id') === (int) $costCentre->id ? 'selected' : '' }}>{{ $costCentre->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Branch') }}</label>
                                <input type="number" name="branch_id" class="form-control" value="{{ old('branch_id', '') }}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Description') }}</label>
                            <textarea name="description" class="form-control" rows="2" maxlength="1000">{{ old('description', '') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Record Expense') }}</button>
                        <a href="{{ route('treasury-petty-cash.show', $fund) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
