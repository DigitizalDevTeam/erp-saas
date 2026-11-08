@extends('layouts.admin')
@section('page-title')
    {{ __('Asset Categories') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Categories') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed asset categories')
            <a href="{{ route('fixed-asset-categories.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Create Category') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-categories.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by name or code') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="classification" class="form-control">
                                <option value="">{{ __('All classifications') }}</option>
                                @foreach (\App\Models\FixedAssetCategory::CLASSIFICATIONS as $classification)
                                    <option value="{{ $classification }}" {{ request('classification') === $classification ? 'selected' : '' }}>{{ ucfirst($classification) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Classification') }}</th>
                                    <th>{{ __('Default Life') }}</th>
                                    <th>{{ __('Method') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($categories as $category)
                                    <tr>
                                        <td><strong>{{ $category->code }}</strong></td>
                                        <td>{{ $category->name }}</td>
                                        <td>{{ ucfirst($category->classification) }}</td>
                                        <td>
                                            @if ($category->default_useful_life)
                                                {{ $category->default_useful_life }} {{ __($category->default_life_unit . ($category->default_useful_life > 1 ? 's' : '')) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $category->depreciation_method)) }}</td>
                                        <td>
                                            @if ($category->is_active)
                                                <span class="badge bg-success">{{ __('Active') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ __('Inactive') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @can('manage fixed asset categories')
                                                <a href="{{ route('fixed-asset-categories.edit', $category->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
                                                <form method="POST" action="{{ route('fixed-asset-categories.deactivate', $category->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Deactivate this category? Its code will never be reused.') }}')">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-danger">{{ __('Deactivate') }}</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center">{{ __('No categories found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $categories->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
