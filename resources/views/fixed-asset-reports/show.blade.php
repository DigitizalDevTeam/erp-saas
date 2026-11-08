@extends('layouts.admin')
@section('page-title')
    {{ __($data['title']) }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-reports.index') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __($data['title']) }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('fixed-asset-reports.show', ['report' => $reportKey, 'export' => 'csv'] + request()->except(['export', 'page'])) }}" class="btn btn-sm btn-secondary">{{ __('CSV') }}</a>
        <a href="{{ route('fixed-asset-reports.show', ['report' => $reportKey, 'export' => 'xlsx'] + request()->except(['export', 'page'])) }}" class="btn btn-sm btn-secondary">{{ __('Excel') }}</a>
        <a href="{{ route('fixed-asset-reports.show', ['report' => $reportKey, 'export' => 'pdf'] + request()->except(['export', 'page'])) }}" class="btn btn-sm btn-secondary">{{ __('PDF') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __($data['description']) }}</small></p>

                    @if (! empty($data['reconciliation']))
                        @if ($data['reconciliation']['matches'])
                            <div class="alert alert-success">
                                {{ __('Reconciled:') }} {{ __($data['reconciliation']['note']) }}
                            </div>
                        @else
                            <div class="alert alert-danger">
                                {{ __($data['reconciliation']['note']) }}
                            </div>
                        @endif
                    @endif

                    <form method="GET" action="{{ route('fixed-asset-reports.show', $reportKey) }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <input type="date" name="from" class="form-control" value="{{ $filters['from'] ?? '' }}" placeholder="{{ __('From') }}">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="to" class="form-control" value="{{ $filters['to'] ?? '' }}" placeholder="{{ __('To') }}">
                        </div>
                        <div class="col-md-2">
                            <select name="category_id" class="form-control">
                                <option value="">{{ __('All categories') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" {{ (string) ($filters['category_id'] ?? '') === (string) $category->id ? 'selected' : '' }}>{{ $category->code }} — {{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="location_id" class="form-control">
                                <option value="">{{ __('All locations') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" {{ (string) ($filters['location_id'] ?? '') === (string) $location->id ? 'selected' : '' }}>{{ $location->code }} — {{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAsset::STATUSES as $status)
                                    <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="custodian_id" class="form-control">
                                <option value="">{{ __('All custodians') }}</option>
                                @foreach ($custodians as $custodian)
                                    <option value="{{ $custodian->id }}" {{ (string) ($filters['custodian_id'] ?? '') === (string) $custodian->id ? 'selected' : '' }}>{{ $custodian->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="search" class="form-control" placeholder="{{ __('Search') }}" value="{{ $filters['search'] ?? '' }}">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Filter') }}</button>
                            <a href="{{ route('fixed-asset-reports.show', $reportKey) }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>
                        </div>
                    </form>

                    @if (count($data['rows']) > 0)
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        @foreach ($data['headings'] as $heading)
                                            <th>{{ $heading }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data['rows'] as $row)
                                        <tr>
                                            @foreach ($row as $cell)
                                                <td>{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">{{ __('No rows for these filters.') }}</p>
                    @endif

                    @if (! empty($data['totals']))
                        <p>
                            @foreach ($data['totals'] as $label => $value)
                                <strong>{{ $label }}:</strong> {{ $value }}&nbsp;&nbsp;
                            @endforeach
                        </p>
                    @endif

                    @if (! empty($data['groups']))
                        <div class="row mt-3">
                            @foreach ($data['groups'] as $groupKey => $groupRows)
                                <div class="col-md-4">
                                    <h5>{{ ucfirst(str_replace('_', ' ', str_replace('by_', '', $groupKey))) }}</h5>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>{{ __('Group') }}</th>
                                                    <th>{{ __('Count') }}</th>
                                                    <th>{{ __('Cost') }}</th>
                                                    <th>{{ __('Book Value') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($groupRows as $groupRow)
                                                    <tr>
                                                        <td>{{ $groupRow['label'] }}</td>
                                                        <td>{{ $groupRow['count'] }}</td>
                                                        <td>{{ number_format((float) $groupRow['cost'], 2) }}</td>
                                                        <td>{{ number_format((float) $groupRow['book_value'], 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($exports->count() > 0)
                        <h5 class="mt-4">{{ __('Recent exports') }}</h5>
                        <ul>
                            @foreach ($exports as $export)
                                <li>
                                    {{ strtoupper($export->format) }} — {{ ucfirst($export->status) }}
                                    @if (isset($downloadUrls[$export->id]))
                                        <a href="{{ $downloadUrls[$export->id] }}">{{ __('Download') }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
