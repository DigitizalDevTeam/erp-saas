@extends('layouts.admin')
@section('page-title')
    {{ __('Recipes') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Recipes') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create kitchen recipes')
            <a href="{{ route('kitchen.recipes.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Recipe') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen.recipes.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by name or code') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\KitchenRecipe::STATUSES as $status)
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
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Dish / Preparation') }}</th>
                                <th>{{ __('Version') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Updated') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($recipes as $recipe)
                                <tr>
                                    <td>{{ $recipe->code }}</td>
                                    <td>{{ $recipe->name }}</td>
                                    <td>{{ $recipe->product ? $recipe->product->name : '-' }}</td>
                                    <td>v{{ $recipe->version }}</td>
                                    <td>
                                        @php
                                            $badge = [
                                                'draft' => 'bg-secondary',
                                                'pending_approval' => 'bg-warning',
                                                'approved' => 'bg-success',
                                                'archived' => 'bg-dark',
                                            ][$recipe->status] ?? 'bg-secondary';
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $recipe->status)) }}</span>
                                    </td>
                                    <td>{{ $recipe->updated_at ? $recipe->updated_at->format('Y-m-d') : '-' }}</td>
                                    <td>
                                        <a href="{{ route('kitchen.recipes.show', $recipe->id) }}" class="btn btn-sm btn-info" title="{{ __('View') }}"><i class="ti ti-eye"></i></a>
                                        @can('create kitchen recipes')
                                            @if ($recipe->isDraft() || $recipe->isPendingApproval())
                                                <a href="{{ route('kitchen.recipes.edit', $recipe->id) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center">{{ __('No recipes yet. Create the first one.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $recipes->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
