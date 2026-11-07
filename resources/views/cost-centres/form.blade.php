@extends('layouts.admin')
@section('page-title')
    {{ $centre->exists ? __('Edit Cost Centre') : __('Create Cost Centre') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ $centre->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $centre->exists ? route('cost-centres.update', $centre->id) : route('cost-centres.store') }}">
                        @csrf
                        @if ($centre->exists)
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="code">{{ __('Code') }} *</label>
                                    <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $centre->code) }}" maxlength="50" required>
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="text-muted">{{ __('Short, unique per company. Never reused after deactivation.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="form-label" for="name">{{ __('Name') }} *</label>
                                    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $centre->name) }}" maxlength="255" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">{{ __('Description') }}</label>
                            <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $centre->description) }}</textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="parent_id">{{ __('Parent Cost Centre') }}</label>
                                    <select name="parent_id" id="parent_id" class="form-control">
                                        <option value="">{{ __('None (top level)') }}</option>
                                        @foreach ($parents as $parent)
                                            <option value="{{ $parent->id }}" {{ (string) old('parent_id', $centre->parent_id) === (string) $parent->id ? 'selected' : '' }}>{{ $parent->code }} — {{ $parent->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('parent_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    <small class="text-muted">{{ __('Parents are summary-only: only leaf centres take direct postings.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="branch_id">{{ __('Branch') }}</label>
                                    <select name="branch_id" id="branch_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" {{ (string) old('branch_id', $centre->branch_id) === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="manager_user_id">{{ __('Manager') }}</label>
                                    <select name="manager_user_id" id="manager_user_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($managers as $manager)
                                            <option value="{{ $manager->id }}" {{ (string) old('manager_user_id', $centre->manager_user_id) === (string) $manager->id ? 'selected' : '' }}>{{ $manager->name }} ({{ $manager->email }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="status">{{ __('Status') }}</label>
                                    <select name="status" id="status" class="form-control">
                                        @foreach (\App\Models\CostCentre::STATUSES as $status)
                                            <option value="{{ $status }}" {{ old('status', $centre->status) === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('Inactive centres stay reportable for history but cannot take new transactions.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="effective_from">{{ __('Effective From') }}</label>
                                    <input type="date" name="effective_from" id="effective_from" class="form-control" value="{{ old('effective_from', $centre->effective_from ? $centre->effective_from->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="effective_to">{{ __('Effective To') }}</label>
                                    <input type="date" name="effective_to" id="effective_to" class="form-control" value="{{ old('effective_to', $centre->effective_to ? $centre->effective_to->format('Y-m-d') : '') }}">
                                    <small class="text-muted">{{ __('Leave empty for no end date.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                            <a href="{{ route('cost-centres.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
