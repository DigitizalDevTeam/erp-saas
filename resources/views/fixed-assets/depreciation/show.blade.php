@extends('layouts.admin')
@section('page-title')
    {{ __('Depreciation Run — :period', ['period' => $run->period ? $run->period->format('Y-m') : '']) }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-depreciation.index') }}">{{ __('Depreciation') }}</a></li>
    <li class="breadcrumb-item">{{ $run->period ? $run->period->format('Y-m') : '' }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage fixed assets')
            @if ($run->isCalculated())
                <form method="POST" action="{{ route('fixed-asset-depreciation.submit-review', $run->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Mark Reviewed') }}</button>
                </form>
            @endif
            @if ($run->isReviewed())
                <form method="POST" action="{{ route('fixed-asset-depreciation.submit-approval', $run->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Submit for Approval') }}</button>
                </form>
            @endif
            @if ($run->isCalculated() || $run->isReviewed())
                <form method="POST" action="{{ route('fixed-asset-depreciation.cancel', $run->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">{{ __('Cancel Run') }}</button>
                </form>
            @endif
        @endcan
        @can('post fixed asset depreciation')
            @if ($run->isReviewed())
                <form method="POST" action="{{ route('fixed-asset-depreciation.decide', $run->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                </form>
            @endif
            @if ($run->isApproved())
                <form method="POST" action="{{ route('fixed-asset-depreciation.post', $run->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Post Journal') }}</button>
                </form>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Run :period', ['period' => $run->period ? $run->period->format('Y-m') : '']) }} — {{ ucfirst(str_replace('_', ' ', $run->status)) }}</h5>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">{{ __('Period') }}</dt>
                        <dd class="col-sm-9">{{ $run->period ? $run->period->format('Y-m') : '—' }}</dd>
                        <dt class="col-sm-3">{{ __('Assets') }}</dt>
                        <dd class="col-sm-9">{{ $run->asset_count }}</dd>
                        <dt class="col-sm-3">{{ __('Total') }}</dt>
                        <dd class="col-sm-9">{{ number_format((float) $run->total_amount, 2) }}</dd>
                        <dt class="col-sm-3">{{ __('Journal') }}</dt>
                        <dd class="col-sm-9">
                            @if ($run->journal_id)
                                <a href="{{ route('journal-entry.show', $run->journal_id) }}">{{ __('Journal #:id', ['id' => $run->journal_id]) }}</a>
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-sm-3">{{ __('Posted') }}</dt>
                        <dd class="col-sm-9">{{ $run->posted_at ? $run->posted_at->format('Y-m-d H:i') : '—' }}</dd>
                    </dl>

                    <h6 class="mt-4">{{ __('Entries') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Planned') }}</th>
                                    <th>{{ __('Posted') }}</th>
                                    <th>{{ __('Cost Centre') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($run->entries as $entry)
                                    <tr>
                                        <td>
                                            @if ($entry->asset)
                                                <a href="{{ route('fixed-assets.show', $entry->asset->id) }}"><strong>{{ $entry->asset->code }}</strong></a><br>
                                                {{ $entry->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ number_format((float) $entry->planned_amount, 4) }}</td>
                                        <td>{{ $entry->posted_amount !== null ? number_format((float) $entry->posted_amount, 2) : '—' }}</td>
                                        <td>{{ $entry->costCentre ? $entry->costCentre->code : '—' }}</td>
                                        <td>{{ ucfirst($entry->status) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center">{{ __('No entries.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($approvals->isNotEmpty())
                        <h6 class="mt-4">{{ __('Approvals') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Asset') }}</th>
                                        <th>{{ __('Requested By') }}</th>
                                        <th>{{ __('Decision') }}</th>
                                        <th>{{ __('Approver') }}</th>
                                        <th>{{ __('Comment') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($approvals as $approval)
                                        <tr>
                                            <td>{{ $approval->asset ? $approval->asset->code : '—' }}</td>
                                            <td>{{ $approval->requested_by }}</td>
                                            <td>{{ ucfirst($approval->decision) }}</td>
                                            <td>{{ $approval->approver_id ?? '—' }}</td>
                                            <td>{{ $approval->comment ?? '—' }}</td>
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
