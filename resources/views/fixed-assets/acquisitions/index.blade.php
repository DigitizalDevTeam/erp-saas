@extends('layouts.admin')
@section('page-title')
    {{ __('Acquisitions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Acquisitions') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-acquisitions.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by asset code, name or reference') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetAcquisition::STATUSES as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="source_type" class="form-control">
                                <option value="">{{ __('All sources') }}</option>
                                @foreach (\App\Models\FixedAssetAcquisition::SOURCE_TYPES as $sourceType)
                                    <option value="{{ $sourceType }}" {{ request('source_type') === $sourceType ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $sourceType)) }}</option>
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
                                    <th>{{ __('Source') }}</th>
                                    <th>{{ __('Cost (base)') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($acquisitions as $acquisition)
                                    <tr>
                                        <td>
                                            @if ($acquisition->asset)
                                                <strong>{{ $acquisition->asset->code }}</strong><br>
                                                {{ $acquisition->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $acquisition->source_type)) }}@if ($acquisition->source_reference)<br><span class="text-muted">{{ $acquisition->source_reference }}</span>@endif</td>
                                        <td>{{ number_format((float) $acquisition->total_cost_base, 2) }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $acquisition->status)) }}</td>
                                        <td>{{ $acquisition->created_at ? $acquisition->created_at->format('Y-m-d') : '—' }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-acquisitions.show', $acquisition->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">{{ __('No acquisitions found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $acquisitions->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
