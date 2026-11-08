@extends('layouts.admin')
@section('page-title')
    {{ __('Depreciation Runs') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Depreciation') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-depreciation.report') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-chart-bar"></i> {{ __('Monthly Report') }}
            </a>
            <a href="{{ route('fixed-asset-depreciation.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Run') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-depreciation.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetDepreciationRun::STATUSES as $status)
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
                                    <th>{{ __('Period') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Assets') }}</th>
                                    <th>{{ __('Total') }}</th>
                                    <th>{{ __('Journal') }}</th>
                                    <th>{{ __('Posted') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($runs as $run)
                                    <tr>
                                        <td><strong>{{ $run->period ? $run->period->format('Y-m') : '—' }}</strong></td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $run->status)) }}</td>
                                        <td>{{ $run->asset_count }}</td>
                                        <td>{{ number_format((float) $run->total_amount, 2) }}</td>
                                        <td>
                                            @if ($run->journal_id)
                                                <a href="{{ route('journal-entry.show', $run->journal_id) }}">{{ __('Journal #:id', ['id' => $run->journal_id]) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $run->posted_at ? $run->posted_at->format('Y-m-d H:i') : '—' }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-depreciation.show', $run->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center">{{ __('No depreciation runs yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (method_exists($runs, 'links'))
                        {{ $runs->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
