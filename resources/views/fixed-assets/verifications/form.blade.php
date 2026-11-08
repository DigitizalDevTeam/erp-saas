@extends('layouts.admin')
@section('page-title')
    {{ __('New Verification Campaign') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-verifications.index') }}">{{ __('Physical Verification') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Verification Campaign') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Start a Physical Verification') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-asset-verifications.store') }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="name">{{ __('Campaign Name') }} *</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="location_id">{{ __('Location') }}</label>
                            <select name="location_id" id="location_id" class="form-control">
                                <option value="">{{ __('Whole company') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" {{ (int) old('location_id') === (int) $location->id ? 'selected' : '' }}>{{ $location->code }} — {{ $location->name }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">{{ __('Leave empty to verify every in-service asset in the company.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="deadline">{{ __('Deadline') }} *</label>
                            <input type="date" name="deadline" id="deadline" value="{{ old('deadline') }}" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="notes">{{ __('Notes') }}</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Create Campaign') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
