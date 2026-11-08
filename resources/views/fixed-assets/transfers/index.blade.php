@extends('layouts.admin')
@section('page-title')
    {{ __('Transfers') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Transfers') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            <a href="{{ route('fixed-asset-transfers.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Transfer') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-transfers.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by asset code, name or new custodian') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAssetTransfer::STATUSES as $status)
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
                                    <th>{{ __('From') }}</th>
                                    <th>{{ __('To') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Requested') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($transfers as $transfer)
                                    <tr>
                                        <td>
                                            @if ($transfer->asset)
                                                <strong>{{ $transfer->asset->code }}</strong><br>
                                                {{ $transfer->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $transfer->fromLocation ? $transfer->fromLocation->code : '—' }}@if ($transfer->fromCustodian)<br><span class="text-muted">{{ $transfer->fromCustodian->name }}</span>@endif</td>
                                        <td>{{ $transfer->toLocation ? $transfer->toLocation->code : '—' }}@if ($transfer->toCustodian)<br><span class="text-muted">{{ $transfer->toCustodian->name }}</span>@endif</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $transfer->status)) }}</td>
                                        <td>{{ $transfer->requested_at ? $transfer->requested_at->format('Y-m-d') : '—' }}</td>
                                        <td>
                                            <a href="{{ route('fixed-asset-transfers.show', $transfer->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">{{ __('No transfers found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $transfers->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
