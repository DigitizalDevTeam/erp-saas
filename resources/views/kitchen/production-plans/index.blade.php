@extends('layouts.admin')
@section('page-title')
    {{ __('Production Plans') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Production Plans') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen production')
            <a href="{{ route('kitchen.production-plans.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Production Plan') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen.production-plans.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by plan number') }}">
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="meal_service" class="form-control">
                                <option value="">{{ __('All meal services') }}</option>
                                @foreach ($mealServices as $service)
                                    <option value="{{ $service }}" {{ request('meal_service') === $service ? 'selected' : '' }}>{{ ucfirst($service) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="plan_date" value="{{ request('plan_date') }}" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Plan No') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Meal Service') }}</th>
                                <th>{{ __('Covers') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($plans as $plan)
                                <tr>
                                    <td>{{ $plan->plan_number }}</td>
                                    <td>{{ $plan->plan_date ? $plan->plan_date->format('Y-m-d') : '-' }}</td>
                                    <td>{{ ucfirst($plan->meal_service) }}</td>
                                    <td>{{ $plan->occupancyCovers() !== null ? $plan->occupancyCovers() : '-' }}</td>
                                    <td>
                                        @php
                                            $badge = [
                                                'draft' => 'bg-secondary',
                                                'pending_approval' => 'bg-warning',
                                                'approved' => 'bg-info',
                                                'in_production' => 'bg-primary',
                                                'completed' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                            ][$plan->status] ?? 'bg-secondary';
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $plan->status)) }}</span>
                                        @if ($plan->isPendingApproval() && $plan->approval_step)
                                            <small class="text-muted d-block">{{ __('Waiting on') }}: {{ ucfirst(str_replace('_', ' ', $plan->approval_step)) }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('kitchen.production-plans.show', $plan->id) }}" class="btn btn-sm btn-info" title="{{ __('View') }}"><i class="ti ti-eye"></i></a>
                                        @can('manage kitchen production')
                                            @if ($plan->isDraft() || $plan->isRejected())
                                                <a href="{{ route('kitchen.production-plans.edit', $plan->id) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center">{{ __('No production plans yet. Create the first one.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $plans->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
