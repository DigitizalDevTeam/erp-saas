@extends('layouts.admin')
@section('page-title')
    {{ __('Production Orders') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Production Orders') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen production orders')
            <a href="{{ route('kitchen.production-orders.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Production Order') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen.production-orders.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by order number or recipe') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\KitchenProductionOrder::STATUSES as $status)
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
                                <th>{{ __('Order No') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Recipe') }}</th>
                                <th>{{ __('Section') }}</th>
                                <th>{{ __('Planned Qty') }}</th>
                                <th>{{ __('Planned Cost') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($orders as $order)
                                <tr>
                                    <td>{{ $order->order_number ?? __('Draft') }}</td>
                                    <td>{{ $order->production_date ? $order->production_date->format('Y-m-d') : '-' }}</td>
                                    <td>{{ $order->recipe ? $order->recipe->name : '-' }} @if ($order->recipe) <small class="text-muted">(v{{ $order->recipe_version }})</small> @endif</td>
                                    <td>{{ $order->section ? ucfirst(str_replace('_', ' ', $order->section)) : '-' }}</td>
                                    <td>{{ number_format((float) $order->planned_quantity, 2) }} {{ $order->plannedUnit ? $order->plannedUnit->name : '' }}</td>
                                    <td>{{ number_format((float) $order->planned_cost, 2) }}</td>
                                    <td>
                                        @php
                                            $badge = [
                                                'draft' => 'bg-secondary',
                                                'approved' => 'bg-info',
                                                'in_preparation' => 'bg-warning',
                                                'completed' => 'bg-success',
                                                'validated' => 'bg-dark',
                                            ][$order->status] ?? 'bg-secondary';
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span>
                                    </td>
                                    <td>
                                        <a href="{{ route('kitchen.production-orders.show', $order->id) }}" class="btn btn-sm btn-info" title="{{ __('View') }}"><i class="ti ti-eye"></i></a>
                                        @can('manage kitchen production orders')
                                            @if ($order->isDraft())
                                                <a href="{{ route('kitchen.production-orders.edit', $order->id) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center">{{ __('No production orders yet. Create the first one.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $orders->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
