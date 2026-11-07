@extends('layouts.admin')
@section('page-title')
    {{ __('Cost Centres') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Cost Centres') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage cost centre allocations')
            <a href="{{ route('cost-centre-allocations.index') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-arrows-split"></i> {{ __('Allocations') }}
            </a>
        @endcan
        @can('edit cost centres')
            <a href="{{ route('cost-centres.settings') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-settings"></i> {{ __('Settings') }}
            </a>
        @endcan
        @can('create cost centres')
            <a href="{{ route('cost-centres.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Cost Centre') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('cost-centres.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by name or code') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\CostCentre::STATUSES as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="branch_id" class="form-control">
                                <option value="">{{ __('All branches') }}</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ (string) request('branch_id') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Parent') }}</th>
                                <th>{{ __('Branch') }}</th>
                                <th>{{ __('Manager') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Effective') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $row)
                                @php $centre = $row['centre']; @endphp
                                <tr>
                                    <td>{{ $centre->code }}</td>
                                    <td style="padding-left: {{ 12 + $row['depth'] * 24 }}px">
                                        @if ($row['depth'] > 0)
                                            <span class="text-muted">&#8627;</span>
                                        @endif
                                        {{ $centre->name }}
                                    </td>
                                    <td>{{ $centre->parent ? $centre->parent->code.' — '.$centre->parent->name : '-' }}</td>
                                    <td>{{ $centre->branch ? $centre->branch->name : '-' }}</td>
                                    <td>{{ $centre->manager ? $centre->manager->name : '-' }}</td>
                                    <td>
                                        <span class="badge {{ $centre->status === 'active' ? 'bg-success' : 'bg-secondary' }}">{{ ucfirst($centre->status) }}</span>
                                    </td>
                                    <td>
                                        {{ $centre->effective_from ? $centre->effective_from->format('Y-m-d') : '—' }}
                                        &rarr;
                                        {{ $centre->effective_to ? $centre->effective_to->format('Y-m-d') : '—' }}
                                    </td>
                                    <td>
                                        @can('edit cost centres')
                                            <a href="{{ route('cost-centres.edit', $centre->id) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                        @endcan
                                        @can('deactivate cost centres')
                                            @if ($centre->status === 'active')
                                                <form method="POST" action="{{ route('cost-centres.deactivate', $centre->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Deactivate this cost centre? It stays reportable for history but cannot take new transactions.') }}')">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-warning" title="{{ __('Deactivate') }}"><i class="ti ti-ban"></i></button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('cost-centres.destroy', $centre->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete this cost centre? Only possible when it has no children and is not referenced anywhere — otherwise deactivate it instead.') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center">{{ __('No cost centres yet. Create the first one.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (! empty($centres))
                        {{ $centres->appends(request()->query())->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
