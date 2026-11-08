@extends('layouts.admin')
@section('page-title')
    {{ __('Physical Verification') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Physical Verification') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-verifications.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Verification Campaign') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-verifications.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by campaign name') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetPhysicalVerification::STATUSES as $status)
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
                                    <th>{{ __('Campaign') }}</th>
                                    <th>{{ __('Location') }}</th>
                                    <th>{{ __('Deadline') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Assets') }}</th>
                                    <th>{{ __('Found') }}</th>
                                    <th>{{ __('Missing') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($campaigns as $campaign)
                                    @php $counts = $campaign->resultCounts(); @endphp
                                    <tr>
                                        <td><strong>{{ $campaign->name }}</strong></td>
                                        <td>{{ $campaign->location ? $campaign->location->code.' — '.$campaign->location->name : __('Whole company') }}</td>
                                        <td>{{ $campaign->deadline ? $campaign->deadline->format('Y-m-d') : '—' }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $campaign->status)) }}</td>
                                        <td>{{ $campaign->items->count() }}</td>
                                        <td>{{ $counts['found'] ?? 0 }}</td>
                                        <td>{{ $counts['missing'] ?? 0 }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-verifications.show', $campaign->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center">{{ __('No verification campaigns found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $campaigns->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
