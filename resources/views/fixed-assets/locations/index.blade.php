@extends('layouts.admin')
@section('page-title')
    {{ __('Asset Locations') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Locations') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-locations.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Location') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-locations.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by name or code') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="branch_id" class="form-control">
                                <option value="">{{ __('All branches') }}</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ (string) request('branch_id') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('Room') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($locations as $location)
                                    <tr>
                                        <td><strong>{{ $location->code }}</strong></td>
                                        <td>{{ $location->name }}</td>
                                        <td>{{ $location->branch->name ?? '—' }}</td>
                                        <td>{{ $location->room ?? '—' }}</td>
                                        <td>
                                            @if ($location->is_active)
                                                <span class="badge bg-success">{{ __('Active') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ __('Inactive') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @can('manage fixed assets')
                                                <a href="{{ route('fixed-asset-locations.edit', $location->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
                                                <form method="POST" action="{{ route('fixed-asset-locations.deactivate', $location->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Deactivate this location? Its code will never be reused.') }}')">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-danger">{{ __('Deactivate') }}</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">{{ __('No locations found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $locations->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
