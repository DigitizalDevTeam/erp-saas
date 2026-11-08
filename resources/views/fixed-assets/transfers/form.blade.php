@extends('layouts.admin')
@section('page-title')
    {{ __('New Transfer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-transfers.index') }}">{{ __('Transfers') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Transfer') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Transfer an Asset') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-asset-transfers.store') }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="asset_id">{{ __('Asset') }} *</label>
                            <select name="asset_id" id="asset_id" class="form-control" required>
                                <option value="">{{ __('Select an available or assigned asset') }}</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}" {{ (int) old('asset_id', $selectedAsset?->id) === (int) $asset->id ? 'selected' : '' }}>{{ $asset->code }} — {{ $asset->name }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">{{ __('The asset moves only when the destination receives it.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="to_location_id">{{ __('New Location') }}</label>
                            <select name="to_location_id" id="to_location_id" class="form-control">
                                <option value="">{{ __('Keep the current location') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" {{ (int) old('to_location_id') === (int) $location->id ? 'selected' : '' }}>{{ $location->code }} — {{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="to_custodian_id">{{ __('New Custodian') }}</label>
                            <select name="to_custodian_id" id="to_custodian_id" class="form-control">
                                <option value="">{{ __('Keep the current custodian') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" {{ (int) old('to_custodian_id') === (int) $employee->id ? 'selected' : '' }}>{{ $employee->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="to_cost_centre_id">{{ __('New Cost Centre') }}</label>
                            <select name="to_cost_centre_id" id="to_cost_centre_id" class="form-control">
                                <option value="">{{ __('Keep the current cost centre') }}</option>
                                @foreach ($costCentres as $costCentre)
                                    <option value="{{ $costCentre->id }}" {{ (int) old('to_cost_centre_id') === (int) $costCentre->id ? 'selected' : '' }}>{{ $costCentre->code }} — {{ $costCentre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="reason">{{ __('Reason') }}</label>
                            <textarea name="reason" id="reason" class="form-control" rows="2" maxlength="500">{{ old('reason') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="notes">{{ __('Notes') }}</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Request Transfer') }}</button>
                        <a href="{{ route('fixed-asset-transfers.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
