@extends('layouts.admin')
@section('page-title')
    {{ __('Add History Row') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('management-history.index') }}">{{ __('Management History') }}</a></li>
    <li class="breadcrumb-item">{{ __('Add History Row') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Line keys: revenue_ex, revenue_iva, cogs, variables, fixed, other, depreciation, amortization, personnel:{department name}, channel:{name}:ex or :iva. One row per year + month + line.') }}</p>
                    <form method="POST" action="{{ route('management-history.store') }}">
                        @csrf
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Year') }} <span class="text-danger">*</span></label>
                                <input type="number" name="year" value="{{ old('year', date('Y')) }}" min="2000" max="2100" class="form-control @error('year') is-invalid @enderror" required>
                                @error('year')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Month') }} <span class="text-danger">*</span></label>
                                <select name="month" class="form-control @error('month') is-invalid @enderror" required>
                                    @foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $i => $monthKey)
                                        <option value="{{ $i + 1 }}" {{ (string) old('month', date('n')) === (string) ($i + 1) ? 'selected' : '' }}>{{ __('month.' . $monthKey) }}</option>
                                    @endforeach
                                </select>
                                @error('month')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Line') }} <span class="text-danger">*</span></label>
                                <input type="text" name="line" list="line-suggestions" value="{{ old('line') }}" class="form-control @error('line') is-invalid @enderror" required>
                                <datalist id="line-suggestions">
                                    @foreach ($lineSuggestions as $suggestion)
                                        <option value="{{ $suggestion }}"></option>
                                    @endforeach
                                </datalist>
                                @error('line')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="amount" value="{{ old('amount') }}" class="form-control @error('amount') is-invalid @enderror" required>
                                @error('amount')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="text-end">
                            <a href="{{ route('management-history.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
