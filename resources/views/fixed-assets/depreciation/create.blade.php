@extends('layouts.admin')
@section('page-title')
    {{ __('New Depreciation Run') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-depreciation.index') }}">{{ __('Depreciation') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Run') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-asset-depreciation.preview') }}">
                        @csrf
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('Period (month)') }}</label>
                            <input type="month" name="period" value="{{ $period }}" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Preview Amounts') }}</button>
                        <a href="{{ route('fixed-asset-depreciation.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
