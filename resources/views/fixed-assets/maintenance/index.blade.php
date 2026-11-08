@extends('layouts.admin')
@section('page-title')
    {{ __('Maintenance') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Maintenance') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-maintenance.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Maintenance Request') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-maintenance.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by asset code or name') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetMaintenanceRequest::STATUSES as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Priority') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Total Cost') }}</th>
                                    <th>{{ __('Requested') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($requests as $maintenance)
                                    <tr>
                                        <td>
                                            @if ($maintenance->asset)
                                                <a href="{{ route('fixed-assets.show', $maintenance->asset->id) }}"><strong>{{ $maintenance->asset->code }}</strong></a> — {{ $maintenance->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ ucfirst($maintenance->type) }}</td>
                                        <td>{{ ucfirst($maintenance->priority) }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $maintenance->status)) }}</td>
                                        <td>{{ number_format((float) $maintenance->totalCost(), 2) }}</td>
                                        <td>{{ $maintenance->requested_at ? $maintenance->requested_at->format('Y-m-d') : '—' }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-maintenance.show', $maintenance->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center">{{ __('No maintenance requests found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $requests->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
