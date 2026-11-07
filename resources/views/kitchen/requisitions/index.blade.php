@extends('layouts.admin')
@section('page-title')
    {{ __('Requisitions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Requisitions') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen requisitions')
            <a href="{{ route('kitchen.requisitions.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Requisition') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen.requisitions.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by requisition number') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Requisition No') }}</th>
                                <th>{{ __('From') }}</th>
                                <th>{{ __('To Kitchen') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($requisitions as $requisition)
                                <tr>
                                    <td>{{ $requisition->requisition_number }}</td>
                                    <td>{{ $requisition->sourceWarehouse ? $requisition->sourceWarehouse->name : '-' }}</td>
                                    <td>{{ $requisition->kitchenWarehouse ? $requisition->kitchenWarehouse->name : '-' }}</td>
                                    <td>
                                        @php
                                            $badge = [
                                                'requested' => 'bg-warning',
                                                'approved' => 'bg-info',
                                                'issued' => 'bg-success',
                                                'partially_issued' => 'bg-primary',
                                                'rejected' => 'bg-danger',
                                            ][$requisition->status] ?? 'bg-secondary';
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $requisition->status)) }}</span>
                                    </td>
                                    <td>
                                        <a href="{{ route('kitchen.requisitions.show', $requisition->id) }}" class="btn btn-sm btn-info" title="{{ __('View') }}"><i class="ti ti-eye"></i></a>
                                        @can('manage kitchen requisitions')
                                            @if ($requisition->isRequested())
                                                <a href="{{ route('kitchen.requisitions.edit', $requisition->id) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                            @endif
                                            @if ($requisition->isApproved() || $requisition->isPartiallyIssued())
                                                <a href="{{ route('kitchen.requisitions.issue', $requisition->id) }}" class="btn btn-sm btn-success" title="{{ __('Issue') }}">{{ __('Issue') }}</a>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center">{{ __('No requisitions yet. Create the first one.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $requisitions->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
