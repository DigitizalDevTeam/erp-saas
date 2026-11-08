@extends('layouts.admin')
@section('page-title')
    {{ __('Asset Assignments') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Asset Assignments') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage asset cost centre assignments')
            <a href="{{ route('asset-cost-centre-assignments.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Assignment') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Effective-dated cost-centre splits for fixed assets. A new assignment supersedes the open one — history is never rewritten. Depreciation for a period follows the assignment in force for that period.') }}</p>

                    <form method="GET" action="{{ route('asset-cost-centre-assignments.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="asset_id" class="form-control">
                                <option value="">{{ __('All assets') }}</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}" {{ (string) request('asset_id') === (string) $asset->id ? 'selected' : '' }}>{{ $asset->code }} — {{ $asset->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="cost_centre_id" class="form-control">
                                <option value="">{{ __('All centres') }}</option>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (string) request('cost_centre_id') === (string) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by asset code or name') }}">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th class="text-end">{{ __('Share') }}</th>
                                    <th>{{ __('Valid from') }}</th>
                                    <th>{{ __('Valid to') }}</th>
                                    <th>{{ __('Created') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td><a href="{{ route('asset-cost-centre-assignments.show', $row->asset_id) }}"><strong>{{ $row->asset?->code }}</strong> — {{ $row->asset?->name }}</a></td>
                                        <td>{{ $row->costCentre?->code }} — {{ $row->costCentre?->name }}</td>
                                        <td class="text-end">{{ number_format((float) $row->percentage, 2) }}%</td>
                                        <td>{{ $row->valid_from?->format('Y-m-d') }}</td>
                                        <td>{{ $row->valid_to?->format('Y-m-d') ?? __('Open') }}</td>
                                        <td>{{ $row->created_at?->format('Y-m-d') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">{{ __('No assignments yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
