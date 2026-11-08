@extends('layouts.admin')
@section('page-title')
    {{ __('Execute Disposal') }} — {{ $disposal->asset ? $disposal->asset->code : '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-disposals.index') }}">{{ __('Disposals') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-disposals.show', $disposal->id) }}">{{ $disposal->asset ? $disposal->asset->code : '' }}</a></li>
    <li class="breadcrumb-item">{{ __('Execute') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Execute Disposal') }}</h5></div>
                <div class="card-body">
                    <p class="text-muted">{{ __('Executing posts the disposal journal and removes the asset from the books. Any depreciation not yet posted up to the disposal date is posted first, and the gain or loss is worked out from the actual proceeds below — not the expected amount.') }}</p>
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Asset') }}</dt>
                        <dd class="col-sm-8">
                            @if ($disposal->asset)
                                <strong>{{ $disposal->asset->code }}</strong> — {{ $disposal->asset->name }}
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Method') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst(str_replace('_', ' ', $disposal->method)) }}</dd>
                        <dt class="col-sm-4">{{ __('Expected Proceeds') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $disposal->expected_proceeds, 2) }}</dd>
                    </dl>
                    <form method="POST" action="{{ route('fixed-asset-disposals.execute', $disposal->id) }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="actual_proceeds">{{ __('Actual Proceeds') }} *</label>
                            <input type="number" step="0.01" min="0" name="actual_proceeds" id="actual_proceeds" value="{{ old('actual_proceeds', $disposal->expected_proceeds) }}" class="form-control" required>
                            @if ($disposal->method !== 'sale')
                                <small class="form-text text-muted">{{ __('Must be 0 for scrapping, donation and write-off.') }}</small>
                            @endif
                        </div>
                        @if ($disposal->method === 'sale')
                            <div class="form-group">
                                <label class="form-label" for="proceeds_received">{{ __('Proceeds') }} *</label>
                                <select name="proceeds_received" id="proceeds_received" class="form-control">
                                    <option value="1" {{ (string) old('proceeds_received', '1') === '1' ? 'selected' : '' }}>{{ __('Received now — cash / bank') }}</option>
                                    <option value="0" {{ (string) old('proceeds_received') === '0' ? 'selected' : '' }}>{{ __('On credit — customer owes it') }}</option>
                                </select>
                            </div>
                        @endif
                        <div class="form-group">
                            <label class="form-label" for="disposal_date">{{ __('Disposal Date') }}</label>
                            <input type="date" name="disposal_date" id="disposal_date" value="{{ old('disposal_date', now()->format('Y-m-d')) }}" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Execute Disposal') }}</button>
                        <a href="{{ route('fixed-asset-disposals.show', $disposal->id) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
