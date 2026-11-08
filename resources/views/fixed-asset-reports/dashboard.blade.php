@extends('layouts.admin')
@section('page-title')
    {{ __('Fixed Assets Dashboard') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Dashboard') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('fixed-asset-reports.index') }}" class="btn btn-sm btn-secondary">
            {{ __('Reports') }}
        </a>
        <a href="{{ route('fixed-assets.index') }}" class="btn btn-sm btn-primary">
            {{ __('Asset Register') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Fixed assets overview for your company. All money is base currency (:currency). Dashboard totals are the register report totals — both read the same figures, so they can never disagree.', ['currency' => $data['base_currency']]) }}</small></p>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Total assets') }}</h6>
                                    <h3>{{ $data['totals']['count'] }}</h3>
                                    <p class="text-muted mb-0"><small>{{ __('Live: :count', ['count' => $data['totals']['live_count']]) }}</small></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Total cost') }}</h6>
                                    <h3>{{ $data['base_currency'] }} {{ number_format((float) $data['totals']['total_cost'], 2) }}</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Accumulated depreciation') }}</h6>
                                    <h3>{{ $data['base_currency'] }} {{ number_format((float) $data['totals']['total_accum_dep'], 2) }}</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Net book value') }}</h6>
                                    <h3>{{ $data['base_currency'] }} {{ number_format((float) $data['totals']['total_book_value'], 2) }}</h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Depreciation this period (:period)', ['period' => $data['period_label']]) }}</h6>
                                    <h5>{{ $data['base_currency'] }} {{ number_format((float) $data['depreciation_this_period'], 2) }}</h5>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Disposals this period') }}</h6>
                                    <h5>{{ $data['disposals_this_period']['count'] }} — {{ $data['base_currency'] }} {{ number_format((float) $data['disposals_this_period']['proceeds'], 2) }}</h5>
                                    <p class="text-muted mb-0"><small>{{ __('Gain: :gain — Loss: :loss', ['gain' => number_format((float) $data['disposals_this_period']['gain'], 2), 'loss' => number_format((float) $data['disposals_this_period']['loss'], 2)]) }}</small></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Pending approvals') }}</h6>
                                    <h5>{{ __('Acquisitions: :a — Disposals: :d — Depreciation runs: :r', ['a' => $data['pending_approvals']['acquisitions'], 'd' => $data['pending_approvals']['disposals'], 'r' => $data['pending_approvals']['depreciation_runs']]) }}</h5>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Unassigned / Fully depreciated / In maintenance') }}</h6>
                                    <h5>{{ $data['unassigned'] }} / {{ $data['fully_depreciated'] }} / {{ $data['under_maintenance'] }}</h5>
                                    <p class="text-muted mb-0"><small>{{ __('Open maintenance requests: :count', ['count' => $data['open_maintenance_requests']]) }} — {{ __('Maintenance cost this period: :currency :amount', ['currency' => $data['base_currency'], 'amount' => number_format((float) $data['maintenance_cost_this_period'], 2)]) }}</small></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6>{{ __('Verification') }}</h6>
                                    <p class="mb-0">{{ __('Open campaigns: :open (:overdue overdue)', ['open' => $data['verification']['open_campaigns'], 'overdue' => $data['verification']['overdue_campaigns']]) }}</p>
                                    <p class="mb-0">{{ __('Missing assets: :count', ['count' => $data['verification']['missing_assets']]) }}</p>
                                    <p class="mb-0">{{ __('Checked in open campaigns: :checked / :total', ['checked' => $data['verification']['checked'], 'total' => $data['verification']['total']]) }}</p>
                                    <p class="mb-0">{{ __('Proposed for disposal: :count', ['count' => $data['proposed_for_disposal']]) }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            @if (count($data['lost_still_depreciating']) > 0)
                                <div class="alert alert-warning">
                                    <strong>{{ __('Lost / stolen / damaged assets still depreciating:') }}</strong>
                                    {{ __('These assets keep depreciating until they are written off. They are listed here so the depreciation is visible, not silent.') }}
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Code') }}</th>
                                                <th>{{ __('Asset') }}</th>
                                                <th>{{ __('Status') }}</th>
                                                <th>{{ __('Book Value') }}</th>
                                                <th>{{ __('Planned Months Left') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($data['lost_still_depreciating'] as $asset)
                                                <tr>
                                                    <td>{{ $asset['code'] }}</td>
                                                    <td>{{ $asset['name'] }}</td>
                                                    <td>{{ ucfirst(str_replace('_', ' ', $asset['status'])) }}</td>
                                                    <td>{{ number_format((float) $asset['book_value'], 2) }}</td>
                                                    <td>{{ $asset['planned_remaining'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted">{{ __('No lost, stolen or damaged asset is still depreciating.') }}</p>
                            @endif
                        </div>
                    </div>

                    @if (count($data['warranty_expiring']) > 0)
                        <h5 class="mt-4">{{ __('Warranty expiring in :days days', ['days' => $days]) }}</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Code') }}</th>
                                        <th>{{ __('Asset') }}</th>
                                        <th>{{ __('Warranty End') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data['warranty_expiring'] as $asset)
                                        <tr>
                                            <td>{{ $asset['code'] }}</td>
                                            <td>{{ $asset['name'] }}</td>
                                            <td>{{ $asset['warranty_end'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (count($data['high_value']) > 0)
                        <h5 class="mt-4">{{ __('High-value assets (cost at or above the approval threshold :currency :threshold)', ['currency' => $data['base_currency'], 'threshold' => number_format((float) $data['approval_threshold'], 2)]) }}</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Code') }}</th>
                                        <th>{{ __('Asset') }}</th>
                                        <th>{{ __('Cost') }}</th>
                                        <th>{{ __('Book Value') }}</th>
                                        <th>{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data['high_value'] as $asset)
                                        <tr>
                                            <td>{{ $asset['code'] }}</td>
                                            <td>{{ $asset['name'] }}</td>
                                            <td>{{ number_format((float) $asset['cost'], 2) }}</td>
                                            <td>{{ number_format((float) $asset['book_value'], 2) }}</td>
                                            <td>{{ ucfirst(str_replace('_', ' ', $asset['status'])) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="row mt-4">
                        <div class="col-md-4">
                            <h5>{{ __('By category') }}</h5>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Category') }}</th>
                                            <th>{{ __('Count') }}</th>
                                            <th>{{ __('Cost') }}</th>
                                            <th>{{ __('Book Value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($data['by_category'] as $row)
                                            <tr>
                                                <td>{{ $row['label'] }}</td>
                                                <td>{{ $row['count'] }}</td>
                                                <td>{{ number_format((float) $row['cost'], 2) }}</td>
                                                <td>{{ number_format((float) $row['book_value'], 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h5>{{ __('By location') }}</h5>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Location') }}</th>
                                            <th>{{ __('Count') }}</th>
                                            <th>{{ __('Cost') }}</th>
                                            <th>{{ __('Book Value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($data['by_location'] as $row)
                                            <tr>
                                                <td>{{ $row['label'] }}</td>
                                                <td>{{ $row['count'] }}</td>
                                                <td>{{ number_format((float) $row['cost'], 2) }}</td>
                                                <td>{{ number_format((float) $row['book_value'], 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h5>{{ __('By status') }}</h5>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Status') }}</th>
                                            <th>{{ __('Count') }}</th>
                                            <th>{{ __('Cost') }}</th>
                                            <th>{{ __('Book Value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($data['by_status'] as $row)
                                            <tr>
                                                <td>{{ ucfirst(str_replace('_', ' ', $row['label'])) }}</td>
                                                <td>{{ $row['count'] }}</td>
                                                <td>{{ number_format((float) $row['cost'], 2) }}</td>
                                                <td>{{ number_format((float) $row['book_value'], 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    @if (count($data['recent_events']) > 0)
                        <h5 class="mt-4">{{ __('Recent transactions') }}</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Time') }}</th>
                                        <th>{{ __('Actor') }}</th>
                                        <th>{{ __('Asset') }}</th>
                                        <th>{{ __('Entity') }}</th>
                                        <th>{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data['recent_events'] as $row)
                                        <tr>
                                            @foreach (array_slice($row, 0, 5) as $cell)
                                                <td>{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
