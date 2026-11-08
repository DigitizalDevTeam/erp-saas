@extends('layouts.admin')
@section('page-title')
    {{ $category->exists ? __('Edit Asset Category') : __('Create Asset Category') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-categories.index') }}">{{ __('Asset Categories') }}</a></li>
    <li class="breadcrumb-item">{{ $category->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-10">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $category->exists ? route('fixed-asset-categories.update', $category->id) : route('fixed-asset-categories.store') }}">
                        @csrf
                        @if ($category->exists)
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="code">{{ __('Code') }} *</label>
                                    <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $category->code) }}" maxlength="50" required>
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="text-muted">{{ __('Short, unique per company. Never reused after deactivation. Used in asset numbers, e.g. HOYA-IT-2026-0001.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="form-label" for="name">{{ __('Name') }} *</label>
                                    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $category->name) }}" maxlength="255" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">{{ __('Description') }}</label>
                            <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $category->description) }}</textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="branch_id">{{ __('Branch') }}</label>
                                    <select name="branch_id" id="branch_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" {{ (string) old('branch_id', $category->branch_id) === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="classification">{{ __('Classification') }} *</label>
                                    <select name="classification" id="classification" class="form-control">
                                        @foreach (\App\Models\FixedAssetCategory::CLASSIFICATIONS as $classification)
                                            <option value="{{ $classification }}" {{ old('classification', $category->classification) === $classification ? 'selected' : '' }}>{{ ucfirst($classification) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="capitalization_threshold">{{ __('Capitalization Threshold') }}</label>
                                    <input type="number" step="0.01" min="0" name="capitalization_threshold" id="capitalization_threshold" class="form-control" value="{{ old('capitalization_threshold', $category->capitalization_threshold) }}">
                                    <small class="text-muted">{{ __('Base currency. Purchases below this are expensed, not capitalized.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="default_useful_life">{{ __('Default Useful Life') }}</label>
                                    <input type="number" min="1" name="default_useful_life" id="default_useful_life" class="form-control" value="{{ old('default_useful_life', $category->default_useful_life) }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="default_life_unit">{{ __('Life Unit') }} *</label>
                                    <select name="default_life_unit" id="default_life_unit" class="form-control">
                                        @foreach (\App\Models\FixedAssetCategory::LIFE_UNITS as $unit)
                                            <option value="{{ $unit }}" {{ old('default_life_unit', $category->default_life_unit) === $unit ? 'selected' : '' }}>{{ ucfirst($unit) }}s</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('Depreciation starts on the purchase date; the life is per asset in weeks, months or years.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="depreciation_method">{{ __('Depreciation Method') }} *</label>
                                    <select name="depreciation_method" id="depreciation_method" class="form-control">
                                        @foreach (\App\Models\FixedAssetCategory::METHODS as $method)
                                            <option value="{{ $method }}" {{ old('depreciation_method', $category->depreciation_method) === $method ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('Only straight-line is available in v1.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="depreciation_rate">{{ __('Depreciation Rate (%)') }}</label>
                                    <input type="number" step="0.0001" min="0" name="depreciation_rate" id="depreciation_rate" class="form-control" value="{{ old('depreciation_rate', $category->depreciation_rate) }}">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="residual_value_type">{{ __('Residual Value Rule') }} *</label>
                                    <select name="residual_value_type" id="residual_value_type" class="form-control">
                                        @foreach (\App\Models\FixedAssetCategory::RESIDUAL_TYPES as $type)
                                            <option value="{{ $type }}" {{ old('residual_value_type', $category->residual_value_type) === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label class="form-label" for="residual_value_percent">{{ __('Residual %') }}</label>
                                    <input type="number" step="0.0001" min="0" max="100" name="residual_value_percent" id="residual_value_percent" class="form-control" value="{{ old('residual_value_percent', $category->residual_value_percent) }}">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label class="form-label" for="residual_value">{{ __('Residual Value') }}</label>
                                    <input type="number" step="0.01" min="0" name="residual_value" id="residual_value" class="form-control" value="{{ old('residual_value', $category->residual_value) }}">
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-3">{{ __('Chart Accounts') }}</h6>
                        <div class="row">
                            @php
                                $accountFields = [
                                    'asset_account_id' => __('Asset Account'),
                                    'accum_depreciation_account_id' => __('Accumulated Depreciation Account'),
                                    'depreciation_expense_account_id' => __('Depreciation Expense Account'),
                                    'impairment_account_id' => __('Impairment Account'),
                                    'disposal_gain_account_id' => __('Disposal Gain Account'),
                                    'disposal_loss_account_id' => __('Disposal Loss Account'),
                                ];
                            @endphp
                            @foreach ($accountFields as $field => $label)
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                        <select name="{{ $field }}" id="{{ $field }}" class="form-control">
                                            <option value="">{{ __('None') }}</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}" {{ (string) old($field, $category->$field) === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="form-check mt-2">
                            <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $category->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_active">{{ __('Active') }}</label>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">{{ $category->exists ? __('Update Category') : __('Create Category') }}</button>
                            <a href="{{ route('fixed-asset-categories.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
