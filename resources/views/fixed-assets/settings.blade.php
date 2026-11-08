@extends('layouts.admin')
@section('page-title')
    {{ __('Fixed Asset Settings') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Settings') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-assets.settings.save') }}">
                        @csrf

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fixed_asset_base_currency">{{ __('Base Currency') }} *</label>
                                    <input type="text" name="fixed_asset_base_currency" id="fixed_asset_base_currency" class="form-control @error('fixed_asset_base_currency') is-invalid @enderror" value="{{ old('fixed_asset_base_currency', $settings['fixed_asset_base_currency']) }}" maxlength="3" required>
                                    @error('fixed_asset_base_currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="text-muted">{{ __('Every asset stores its own currency + rate + base value. Base is MZN.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fixed_asset_reference_prefix">{{ __('Reference Prefix') }}</label>
                                    <input type="text" name="fixed_asset_reference_prefix" id="fixed_asset_reference_prefix" class="form-control" value="{{ old('fixed_asset_reference_prefix', $settings['fixed_asset_reference_prefix']) }}" maxlength="10">
                                    <small class="text-muted">{{ __('Prefix for asset numbers, e.g. HOYA. Empty means the company name is used.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fixed_asset_clearing_account_code">{{ __('Asset Clearing Account Code') }}</label>
                                    <input type="text" name="fixed_asset_clearing_account_code" id="fixed_asset_clearing_account_code" class="form-control" value="{{ old('fixed_asset_clearing_account_code', $settings['fixed_asset_clearing_account_code']) }}" maxlength="50">
                                    <small class="text-muted">{{ __('Chart account credited on capitalization when there is no supplier invoice yet. Named by the accountant.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fixed_asset_label_size">{{ __('Label Size') }} *</label>
                                    <select name="fixed_asset_label_size" id="fixed_asset_label_size" class="form-control">
                                        @foreach (\App\Services\FixedAssetSettings::LABEL_SIZES as $size)
                                            <option value="{{ $size }}" {{ old('fixed_asset_label_size', $settings['fixed_asset_label_size']) === $size ? 'selected' : '' }}>
                                                {{ ucfirst($size) }} ({{ \App\Services\FixedAssetSettings::LABEL_DIMENSIONS[$size]['width'] }} × {{ \App\Services\FixedAssetSettings::LABEL_DIMENSIONS[$size]['height'] }} mm)
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('QR label size. The printer itself is still to be confirmed.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fixed_asset_approval_threshold_base">{{ __('Second Approval Level At / Above (Base)') }}</label>
                                    <input type="number" step="0.01" min="0" name="fixed_asset_approval_threshold_base" id="fixed_asset_approval_threshold_base" class="form-control" value="{{ old('fixed_asset_approval_threshold_base', $settings['fixed_asset_approval_threshold_base']) }}">
                                    <small class="text-muted">{{ __('Acquisitions at or above this base amount need a second, independent approval level. Approval levels differ per company.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fixed_asset_maintenance_expense_code">{{ __('Maintenance Expense Account Code') }}</label>
                                    <input type="text" name="fixed_asset_maintenance_expense_code" id="fixed_asset_maintenance_expense_code" class="form-control" value="{{ old('fixed_asset_maintenance_expense_code', $settings['fixed_asset_maintenance_expense_code']) }}" maxlength="50">
                                    <small class="text-muted">{{ __('Chart account debited for maintenance costs (default 6226, Conservation and repair). Empty means 6226.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save Settings') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
