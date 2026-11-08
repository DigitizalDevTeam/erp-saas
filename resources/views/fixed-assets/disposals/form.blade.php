@extends('layouts.admin')
@section('page-title')
    {{ __('New Disposal') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-disposals.index') }}">{{ __('Disposals') }}</a></li>
    <li class="breadcrumb-item">{{ __('New Disposal') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Request a Disposal') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('fixed-asset-disposals.store') }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="asset_id">{{ __('Asset') }} *</label>
                            <select name="asset_id" id="asset_id" class="form-control" required>
                                <option value="">{{ __('Select an asset') }}</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}" {{ (int) old('asset_id', $selectedAsset?->id) === (int) $asset->id ? 'selected' : '' }}>{{ $asset->code }} — {{ $asset->name }} ({{ ucfirst(str_replace('_', ' ', $asset->status)) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="method">{{ __('Method') }} *</label>
                            <select name="method" id="method" class="form-control" required>
                                @foreach (\App\Models\FixedAssetDisposal::METHODS as $method)
                                    <option value="{{ $method }}" {{ old('method', 'sale') === $method ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">{{ __('Sale, scrapping or donation remove the asset from the books. Write-off is only for a lost or stolen asset.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="reason">{{ __('Reason') }} *</label>
                            <textarea name="reason" id="reason" class="form-control" rows="3" required>{{ old('reason') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="buyer_name">{{ __('Buyer (sale only)') }}</label>
                            <input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}" class="form-control" maxlength="255">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="expected_proceeds">{{ __('Expected Proceeds') }}</label>
                            <input type="number" step="0.01" min="0" name="expected_proceeds" id="expected_proceeds" value="{{ old('expected_proceeds', '0') }}" class="form-control">
                            <small class="form-text text-muted">{{ __('Estimate only — the gain or loss is worked out from the actual proceeds at execution. Use 0 for scrapping, donation and write-off.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="notes">{{ __('Notes') }}</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Request Disposal') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
