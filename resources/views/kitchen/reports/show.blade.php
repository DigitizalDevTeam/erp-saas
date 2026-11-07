@extends('layouts.admin')
@section('page-title')
    {{ __($title) }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.reports.index') }}">{{ __('Kitchen Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __($title) }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('kitchen.reports.export', ['report' => $key, 'format' => 'csv']) . '?' . http_build_query(request()->query()) }}" class="btn btn-sm btn-primary">{{ __('CSV') }}</a>
        <a href="{{ route('kitchen.reports.export', ['report' => $key, 'format' => 'xlsx']) . '?' . http_build_query(request()->query()) }}" class="btn btn-sm btn-success">{{ __('Excel') }}</a>
        <a href="{{ route('kitchen.reports.export', ['report' => $key, 'format' => 'pdf']) . '?' . http_build_query(request()->query()) }}" class="btn btn-sm btn-warning">{{ __('PDF') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ $description }}</p>

                    <form method="GET" action="{{ route('kitchen.reports.show', $key) }}" class="row g-2 mb-3">
                        @if (in_array('from', $filterFields))
                            <div class="col-md-2">
                                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                            </div>
                        @endif
                        @if (in_array('to', $filterFields))
                            <div class="col-md-2">
                                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                            </div>
                        @endif
                        @if (in_array('branch_id', $filterFields))
                            <div class="col-md-2">
                                <select name="branch_id" class="form-control">
                                    <option value="">{{ __('All branches') }}</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}" {{ (string) ($filters['branch_id'] ?? '') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if (in_array('warehouse_id', $filterFields))
                            <div class="col-md-2">
                                <select name="warehouse_id" class="form-control">
                                    <option value="">{{ __('All warehouses') }}</option>
                                    @foreach ($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}" {{ (string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id ? 'selected' : '' }}>{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if (in_array('section', $filterFields))
                            <div class="col-md-2">
                                <select name="section" class="form-control">
                                    <option value="">{{ __('All sections') }}</option>
                                    @foreach ($sectionOptions as $section)
                                        <option value="{{ $section }}" {{ ($filters['section'] ?? '') === $section ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $section)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if (in_array('category', $filterFields))
                            <div class="col-md-2">
                                <select name="category" class="form-control">
                                    <option value="">{{ __('All categories') }}</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category }}" {{ ($filters['category'] ?? '') === $category ? 'selected' : '' }}>{{ \App\Services\KitchenReportService::categoryLabel($category) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if (in_array('status', $filterFields) && ! empty($statuses))
                            <div class="col-md-2">
                                <select name="status" class="form-control">
                                    <option value="">{{ __('All statuses') }}</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if (in_array('search', $filterFields))
                            <div class="col-md-2">
                                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="{{ __('Search') }}">
                            </div>
                        @endif
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    @if (! empty($summary))
                        <h6>{{ __('Summary') }}</h6>
                        <div class="table-responsive mb-3">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Group') }}</th>
                                    <th>{{ __('Count') }}</th>
                                    <th>{{ __('Quantity') }}</th>
                                    <th>{{ __('Cost') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($summary as $item)
                                    <tr>
                                        <td>{{ $item['label'] }}</td>
                                        <td>{{ $item['count'] }}</td>
                                        <td>{{ $item['quantity'] }}</td>
                                        <td>{{ $item['cost'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                @foreach ($headings as $heading)
                                    <th>{{ $heading }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($headings) }}">{{ __('No rows in this period.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if (! empty($totals))
                        <div class="mt-2">
                            @foreach ($totals as $label => $value)
                                <span class="badge bg-light text-dark me-2">{{ $label }}: {{ $value }}</span>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($sections as $section)
                        <h6 class="mt-4">{{ $section['title'] }}</h6>
                        <div class="table-responsive">
                            <table class="table datatable">
                                <thead>
                                <tr>
                                    @foreach ($section['headings'] as $heading)
                                        <th>{{ $heading }}</th>
                                    @endforeach
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($section['rows'] as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($section['headings']) }}">{{ __('No rows.') }}</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endforeach

                    @if (! empty($detail))
                        <h6 class="mt-4">{{ __('Batch detail') }}: {{ $detail['batch']->batch_number }}</h6>
                        <p class="text-muted">
                            {{ __('Produced') }}: {{ number_format((float) $detail['reconciliation']['produced'], 2) }} ·
                            {{ __('Sold') }}: {{ number_format((float) $detail['reconciliation']['sold'], 2) }} ·
                            {{ __('Lost') }}: {{ number_format((float) $detail['reconciliation']['lost'], 2) }} ·
                            {{ __('Remaining') }}: {{ number_format((float) $detail['reconciliation']['remaining'], 2) }} ·
                            {{ __('Balanced') }}: {{ $detail['reconciliation']['balanced'] ? __('Yes') : __('No') }}
                        </p>
                        <h6>{{ __('Ingredient lots used') }}</h6>
                        <div class="table-responsive mb-3">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Ingredient') }}</th>
                                    <th>{{ __('Lot') }}</th>
                                    <th>{{ __('Expiry') }}</th>
                                    <th>{{ __('Quantity') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($detail['lotRows'] as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="4">{{ __('No lot usage recorded for this batch.') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <h6>{{ __('POS sales from this batch') }}</h6>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('POS line') }}</th>
                                    <th>{{ __('Quantity') }}</th>
                                    <th>{{ __('Restored') }}</th>
                                    <th>{{ __('Outstanding') }}</th>
                                    <th>{{ __('Date') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($detail['consumptionRows'] as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td>{{ $cell }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="5">{{ __('Nothing sold from this batch yet.') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if ($key === 'traceability' && ! empty($traceabilityBatches))
                        <form method="GET" action="{{ route('kitchen.reports.show', $key) }}" class="row g-2 mt-3">
                            <div class="col-md-4">
                                <select name="batch_id" class="form-control">
                                    <option value="">{{ __('Batch detail: choose a batch') }}</option>
                                    @foreach ($traceabilityBatches as $batchOption)
                                        <option value="{{ $batchOption->id }}" {{ (string) (request('batch_id') ?? '') === (string) $batchOption->id ? 'selected' : '' }}>{{ $batchOption->batch_number }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">{{ __('Show detail') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
