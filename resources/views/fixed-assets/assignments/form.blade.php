@extends('layouts.admin')
@section('page-title')
    {{ __('New Assignment') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-assignments.index') }}">{{ __('Assignments') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Assignment') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Assign an Asset') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-asset-assignments.store') }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="asset_id">{{ __('Asset') }} *</label>
                            <select name="asset_id" id="asset_id" class="form-control" required>
                                <option value="">{{ __('Select an available asset') }}</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}" {{ (int) old('asset_id', $selectedAsset?->id) === (int) $asset->id ? 'selected' : '' }}>{{ $asset->code }} — {{ $asset->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="custodian_id">{{ __('Custodian') }} *</label>
                            <select name="custodian_id" id="custodian_id" class="form-control" required>
                                <option value="">{{ __('Select an employee') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" {{ (int) old('custodian_id') === (int) $employee->id ? 'selected' : '' }}>{{ $employee->name }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">{{ __('The asset is assigned only when the custodian accepts it.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="location_id">{{ __('Location') }}</label>
                            <select name="location_id" id="location_id" class="form-control">
                                <option value="">{{ __('Keep the current location') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" {{ (int) old('location_id') === (int) $location->id ? 'selected' : '' }}>{{ $location->code }} — {{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="cost_centre_id">{{ __('Cost Centre') }}</label>
                            <select name="cost_centre_id" id="cost_centre_id" class="form-control">
                                <option value="">{{ __('Keep the current cost centre') }}</option>
                                @foreach ($costCentres as $costCentre)
                                    <option value="{{ $costCentre->id }}" {{ (int) old('cost_centre_id') === (int) $costCentre->id ? 'selected' : '' }}>{{ $costCentre->code }} — {{ $costCentre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="condition">{{ __('Condition') }}</label>
                            <input type="text" name="condition" id="condition" value="{{ old('condition') }}" class="form-control" maxlength="100" placeholder="{{ __('e.g. Good, New, Used') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="accessories">{{ __('Accessories') }}</label>
                            <textarea name="accessories" id="accessories" class="form-control" rows="2">{{ old('accessories') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="notes">{{ __('Notes') }}</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Request Assignment') }}</button>
                        <a href="{{ route('fixed-asset-assignments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
