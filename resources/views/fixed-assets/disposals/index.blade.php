@extends('layouts.admin')
@section('page-title')
    {{ __('Disposals') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Disposals') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-disposals.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Disposal') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-disposals.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by asset code or name') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetDisposal::STATUSES as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="method" class="form-control">
                                <option value="">{{ __('All methods') }}</option>
                                @foreach (\App\Models\FixedAssetDisposal::METHODS as $method)
                                    <option value="{{ $method }}" {{ request('method') === $method ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Method') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Expected Proceeds') }}</th>
                                    <th>{{ __('Actual Proceeds') }}</th>
                                    <th>{{ __('Gain / Loss') }}</th>
                                    <th>{{ __('Requested') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($disposals as $disposal)
                                    <tr>
                                        <td>
                                            @if ($disposal->asset)
                                                <a href="{{ route('fixed-assets.show', $disposal->asset->id) }}"><strong>{{ $disposal->asset->code }}</strong></a> — {{ $disposal->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $disposal->method)) }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $disposal->status)) }}</td>
                                        <td>{{ number_format((float) $disposal->expected_proceeds, 2) }}</td>
                                        <td>{{ $disposal->actual_proceeds !== null ? number_format((float) $disposal->actual_proceeds, 2) : '—' }}</td>
                                        <td>{{ $disposal->gain_loss_amount !== null ? number_format((float) $disposal->gain_loss_amount, 2) : '—' }}</td>
                                        <td>{{ $disposal->requested_at ? $disposal->requested_at->format('Y-m-d') : '—' }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-disposals.show', $disposal->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center">{{ __('No disposals found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $disposals->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
