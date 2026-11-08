@extends('layouts.admin')
@section('page-title')
    {{ $location->exists ? __('Edit Asset Location') : __('Create Asset Location') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-locations.index') }}">{{ __('Asset Locations') }}</a></li>
    <li class="breadcrumb-item">{{ $location->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $location->exists ? route('fixed-asset-locations.update', $location->id) : route('fixed-asset-locations.store') }}">
                        @csrf
                        @if ($location->exists)
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="code">{{ __('Code') }} *</label>
                                    <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $location->code) }}" maxlength="50" required>
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="text-muted">{{ __('Short, unique per company. Never reused after deactivation.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="form-label" for="name">{{ __('Name') }} *</label>
                                    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $location->name) }}" maxlength="255" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="branch_id">{{ __('Branch') }}</label>
                                    <select name="branch_id" id="branch_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" {{ (string) old('branch_id', $location->branch_id) === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="room">{{ __('Room') }}</label>
                                    <input type="text" name="room" id="room" class="form-control" value="{{ old('room', $location->room) }}" maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="form-check mt-2">
                            <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $location->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_active">{{ __('Active') }}</label>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">{{ $location->exists ? __('Update Location') : __('Create Location') }}</button>
                            <a href="{{ route('fixed-asset-locations.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
