@extends('layouts.admin')
@section('page-title')
    {{ __('Edit Expense Line') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.index') }}">{{ __('Advances') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.show', $advance) }}">{{ $advance->operation->reference ?? __('Draft') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit Line') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    @if ($line->review_reason)
                        <div class="alert alert-warning">{{ __('Returned for correction:') }} {{ $line->review_reason }}</div>
                    @endif

                    <form method="POST" action="{{ route('treasury-advances.update-line', $line) }}">
                        @csrf
                        @method('PUT')
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="expense_date">{{ __('Expense Date') }} *</label>
                                    <input type="date" name="expense_date" id="expense_date" class="form-control" value="{{ old('expense_date', $line->expense_date) }}" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="counterparty">{{ __('Counterparty') }}</label>
                                    <input type="text" name="counterparty" id="counterparty" class="form-control" maxlength="150" value="{{ old('counterparty', $line->counterparty) }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="document">{{ __('Document') }}</label>
                                    <input type="text" name="document" id="document" class="form-control" maxlength="150" value="{{ old('document', $line->document) }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="amount">{{ __('Amount') }} *</label>
                                    <input type="number" step="0.0001" min="0.0001" name="amount" id="amount" class="form-control" value="{{ old('amount', $line->amount) }}" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="chart_account_id">{{ __('Expense Account') }} *</label>
                                    <select name="chart_account_id" id="chart_account_id" class="form-control" required>
                                        @foreach ($chartAccounts as $chart)
                                            <option value="{{ $chart->id }}" {{ (int) old('chart_account_id', $line->chart_account_id) === (int) $chart->id ? 'selected' : '' }}>{{ $chart->code }} — {{ $chart->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="cost_centre_id">{{ __('Cost Centre') }}</label>
                                    <select name="cost_centre_id" id="cost_centre_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($costCentres as $centre)
                                            <option value="{{ $centre->id }}" {{ (int) old('cost_centre_id', $line->cost_centre_id ?? 0) === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="evidence">{{ __('Evidence') }}</label>
                            <textarea name="evidence" id="evidence" class="form-control" rows="2" maxlength="1000">{{ old('evidence', $line->evidence) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Save Line') }}</button>
                        <a href="{{ route('treasury-advances.show', $advance) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
