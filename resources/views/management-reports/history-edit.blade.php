@extends('layouts.admin')
@section('page-title')
    {{ __('Edit History Row') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('management-history.index') }}">{{ __('Management History') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit History Row') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ $row->year }} · {{ __('month.' . ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'][$row->month - 1]) }} · {{ $row->line }}</p>
                    <form method="POST" action="{{ route('management-history.update', $row) }}">
                        @csrf
                        @method('PUT')
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="amount" value="{{ old('amount', $row->amount) }}" class="form-control @error('amount') is-invalid @enderror" required>
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
