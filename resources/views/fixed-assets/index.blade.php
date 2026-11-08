@extends('layouts.admin')
@section('page-title')
    {{ __('Asset Register') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Asset Register') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-assets.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by name, code or serial') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="category_id" class="form-control">
                                <option value="">{{ __('All categories') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" {{ (string) request('category_id') === (string) $category->id ? 'selected' : '' }}>{{ $category->code }} — {{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAsset::STATUSES as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="location_id" class="form-control">
                                <option value="">{{ __('All locations') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" {{ (string) request('location_id') === (string) $location->id ? 'selected' : '' }}>{{ $location->code }} — {{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th>{{ __('Location') }}</th>
                                    <th>{{ __('Cost Centre') }}</th>
                                    <th>{{ __('Book Value') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($assets as $asset)
                                    <tr>
                                        <td><strong>{{ $asset->code }}</strong></td>
                                        <td>{{ $asset->name }}</td>
                                        <td>{{ $asset->category->code ?? '—' }}</td>
                                        <td>{{ $asset->location->name ?? '—' }}</td>
                                        <td>{{ $asset->costCentre->name ?? '—' }}</td>
                                        <td>{{ number_format((float) $asset->current_book_value, 2) }} {{ $asset->currency }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $asset->status)) }}</td>
                                        <td>
                                            <a href="{{ route('fixed-assets.show', $asset->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                            <a href="{{ route('fixed-assets.label', $asset->id) }}" class="btn btn-sm btn-secondary" target="_blank">{{ __('Label') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center">{{ __('No assets found. Asset registration opens in Phase 1.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $assets->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
