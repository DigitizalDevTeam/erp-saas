@extends('layouts.admin')
@section('page-title')
    {{ __('Assignments') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Assignments') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-assignments.create') }}" class="btn btn-sm btn-primary">
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
                    <form method="GET" action="{{ route('fixed-asset-assignments.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by asset code, name or custodian') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetAssignment::STATUSES as $status)
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
                                    <th>{{ __('Custodian') }}</th>
                                    <th>{{ __('Location') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Requested') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($assignments as $assignment)
                                    <tr>
                                        <td>
                                            @if ($assignment->asset)
                                                <strong>{{ $assignment->asset->code }}</strong><br>
                                                {{ $assignment->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $assignment->custodian ? $assignment->custodian->name : '—' }}</td>
                                        <td>{{ $assignment->location ? $assignment->location->code : '—' }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $assignment->status)) }}</td>
                                        <td>{{ $assignment->requested_at ? $assignment->requested_at->format('Y-m-d') : '—' }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-assignments.show', $assignment->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">{{ __('No assignments found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $assignments->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
