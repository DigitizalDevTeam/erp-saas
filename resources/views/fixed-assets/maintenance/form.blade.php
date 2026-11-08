@extends('layouts.admin')
@section('page-title')
    {{ __('New Maintenance Request') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-maintenance.index') }}">{{ __('Maintenance') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Maintenance Request') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Report a Fault / Request Maintenance') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-asset-maintenance.store') }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="asset_id">{{ __('Asset') }} *</label>
                            <select name="asset_id" id="asset_id" class="form-control" required>
                                <option value="">{{ __('Select an in-service asset') }}</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}" {{ (int) old('asset_id', $selectedAsset?->id) === (int) $asset->id ? 'selected' : '' }}>{{ $asset->code }} — {{ $asset->name }} ({{ ucfirst(str_replace('_', ' ', $asset->status)) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="type">{{ __('Type') }} *</label>
                            <select name="type" id="type" class="form-control" required>
                                @foreach (\App\Models\FixedAssetMaintenanceRequest::TYPES as $type)
                                    <option value="{{ $type }}" {{ old('type') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="priority">{{ __('Priority') }}</label>
                            <select name="priority" id="priority" class="form-control">
                                @foreach (\App\Models\FixedAssetMaintenanceRequest::PRIORITIES as $priority)
                                    <option value="{{ $priority }}" {{ old('priority', 'medium') === $priority ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="description">{{ __('Description') }} *</label>
                            <textarea name="description" id="description" class="form-control" rows="3" required>{{ old('description') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="expected_cost">{{ __('Expected Cost') }}</label>
                            <input type="number" step="0.01" min="0" name="expected_cost" id="expected_cost" value="{{ old('expected_cost') }}" class="form-control">
                            <small class="form-text text-muted">{{ __('Estimate only. Actual costs are recorded after approval and posted as expenses.') }}</small>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Request Maintenance') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
