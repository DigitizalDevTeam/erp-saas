@extends('layouts.admin')
@section('page-title')
    {{ __('Depreciation by Asset and Cost Centre') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Depreciation by Asset and Cost Centre') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ $basis_label }} {{ __('Currency') }}: {{ $currency_code }}.</p>

                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.expense') }}">{{ __('Expense by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.cash') }}">{{ __('Cash outflow by centre') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.reconciliation') }}">{{ __('Reconciliation') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.unassigned') }}">{{ __('Unassigned / invalid') }}</a></li>
                        <li class="nav-item"><a class="nav-link active" href="{{ route('cost-centre-reports.depreciation') }}">{{ __('Depreciation by asset') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('cost-centre-reports.maintenance') }}">{{ __('Maintenance by asset') }}</a></li>
                    </ul>

                    @if ($matches)
                        <div class="alert alert-success">{{ __('Reconciliation matches: posted depreciation') }} {{ number_format((float) $posted_total, 2) }} = {{ __('dimensioned') }} {{ number_format((float) $dimension_total, 2) }} ({{ $currency_code }}).</div>
                    @else
                        <div class="alert alert-danger">{{ __('RECONCILIATION BREAK: posted depreciation') }} {{ number_format((float) $posted_total, 2) }} ≠ {{ __('dimensioned') }} {{ number_format((float) $dimension_total, 2) }} ({{ $currency_code }}). {{ __('The engine\'s dimensions do not add up to the money it posted — investigate before relying on this report.') }}</div>
                    @endif

                    <form method="GET" action="{{ route('cost-centre-reports.depreciation') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" class="form-control" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" class="form-control" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Cost centre') }}</label>
                            <select class="form-control" name="cost_centre_id">
                                <option value="">{{ __('All centres') }}</option>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (int) $selectedCentreId === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Asset') }}</label>
                            <select class="form-control" name="asset_id">
                                <option value="">{{ __('All assets') }}</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}" {{ (int) $asset_id === (int) $asset->id ? 'selected' : '' }}>{{ $asset->asset_code }} — {{ $asset->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Search') }}</label>
                            <input type="text" class="form-control" name="search" value="{{ $search }}" placeholder="{{ __('Asset code or name') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Status') }}</label>
                            <select class="form-control" name="status">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\FixedAsset::STATUSES as $assetStatus)
                                    <option value="{{ $assetStatus }}" {{ $status === $assetStatus ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $assetStatus)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Category') }}</label>
                            <select class="form-control" name="category_id">
                                <option value="">{{ __('All categories') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" {{ (int) $category_id === (int) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Location') }}</label>
                            <select class="form-control" name="location_id">
                                <option value="">{{ __('All locations') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" {{ (int) $location_id === (int) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('cost-centre-reports.depreciation', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-secondary">{{ __('Export CSV') }}</a>
                            <a href="{{ route('cost-centre-reports.depreciation', array_merge(request()->query(), ['export' => 'xlsx'])) }}" class="btn btn-outline-secondary">{{ __('Export XLSX') }}</a>
                            <a href="{{ route('cost-centre-reports.depreciation', array_merge(request()->query(), ['export' => 'pdf'])) }}" class="btn btn-outline-secondary">{{ __('Export PDF') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Asset name') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th class="text-end">{{ __('Lines') }}</th>
                                    <th class="text-end">{{ __('Total') }} ({{ $currency_code }})</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        <td><strong>{{ $row['asset_code'] }}</strong></td>
                                        <td>{{ $row['asset_name'] }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $row['asset_status'])) }}</td>
                                        <td>{{ $row['cost_centre_code'] ? $row['cost_centre_code'].' — '.$row['cost_centre_name'] : __('Unassigned') }}</td>
                                        <td class="text-end">{{ $row['rows'] }}</td>
                                        <td class="text-end">{{ number_format((float) $row['total'], 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">{{ __('No depreciation in this period.') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="5">{{ __('Total') }}</th>
                                    <th class="text-end">{{ number_format((float) $total, 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
