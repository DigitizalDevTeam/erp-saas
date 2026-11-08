@extends('layouts.admin')
@section('page-title')
    {{ __('Asset Assignments') }} — {{ $asset->code }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('asset-cost-centre-assignments.index') }}">{{ __('Asset Assignments') }}</a></li>
    <li class="breadcrumb-item">{{ $asset->code }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage asset cost centre assignments')
            <a href="{{ route('asset-cost-centre-assignments.create', ['asset_id' => $asset->id]) }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Assignment') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h5>{{ $asset->code }} — {{ $asset->name }}</h5>
                    <p class="text-muted">{{ __('Status') }}: {{ ucfirst(str_replace('_', ' ', $asset->status)) }} · {{ __('Asset cost centre') }}: {{ $asset->costCentre?->code ?? '—' }} ({{ __('used when no split assignment covers the date') }})</p>

                    <h6>{{ __('Split in force today') }}</h6>
                    @if ($current === [])
                        <p class="text-muted">{{ __('No split assignment covers today — depreciation falls back to the asset\'s own cost centre.') }}</p>
                    @else
                        <ul>
                            @foreach ($current as $split)
                                @php $centre = \App\Models\CostCentre::find($split['cost_centre_id']); @endphp
                                <li><strong>{{ $centre?->code }}</strong> — {{ $centre?->name }}: {{ number_format((float) $split['percentage'], 2) }}%</li>
                            @endforeach
                        </ul>
                    @endif

                    @can('manage asset cost centre assignments')
                        @if ($current !== [])
                            <form method="POST" action="{{ route('asset-cost-centre-assignments.end', $asset->id) }}" class="row g-2 mb-3">
                                @csrf
                                <div class="col-md-3">
                                    <label class="form-label">{{ __('End the open assignment on') }}</label>
                                    <input type="date" name="valid_to" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-outline-danger">{{ __('End Assignment') }}</button>
                                </div>
                            </form>
                        @endif
                    @endcan

                    <h6>{{ __('Assignment history') }}</h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Cost centre') }}</th>
                                    <th class="text-end">{{ __('Share') }}</th>
                                    <th>{{ __('Valid from') }}</th>
                                    <th>{{ __('Valid to') }}</th>
                                    <th>{{ __('Created') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $row)
                                    <tr>
                                        <td>{{ $row->costCentre?->code }} — {{ $row->costCentre?->name }}</td>
                                        <td class="text-end">{{ number_format((float) $row->percentage, 2) }}%</td>
                                        <td>{{ $row->valid_from?->format('Y-m-d') }}</td>
                                        <td>{{ $row->valid_to?->format('Y-m-d') ?? __('Open') }}</td>
                                        <td>{{ $row->created_at?->format('Y-m-d') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No assignments for this asset yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
