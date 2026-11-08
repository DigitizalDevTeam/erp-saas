@extends('layouts.admin')
@section('page-title')
    {{ $asset->code }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-assets.index') }}">{{ __('Asset Register') }}</a></li>
    <li class="breadcrumb-item">{{ $asset->code }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('fixed-assets.label', $asset->id) }}" class="btn btn-sm btn-secondary" target="_blank">
            <i class="ti ti-printer"></i> {{ __('Print Label') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $asset->name }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $asset->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Code') }}</dt>
                        <dd class="col-sm-8"><strong>{{ $asset->code }}</strong></dd>
                        <dt class="col-sm-4">{{ __('Category') }}</dt>
                        <dd class="col-sm-8">{{ $asset->category ? $asset->category->code.' — '.$asset->category->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Serial Number') }}</dt>
                        <dd class="col-sm-8">{{ $asset->serial_number ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Brand / Model') }}</dt>
                        <dd class="col-sm-8">{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Acquisition Method') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst(str_replace('_', ' ', $asset->acquisition_method)) }}</dd>
                        <dt class="col-sm-4">{{ __('Purchase Cost') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $asset->purchase_cost, 2) }} {{ $asset->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Base Value') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $asset->amount_base, 2) }} {{ \App\Services\FixedAssetSettings::baseCurrency((int) $asset->created_by) }} ({{ __('rate') }} {{ $asset->exchange_rate }})</dd>
                        <dt class="col-sm-4">{{ __('Capitalized Cost') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $asset->capitalized_cost, 2) }} {{ $asset->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Current Book Value') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $asset->current_book_value, 2) }} {{ $asset->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Useful Life') }}</dt>
                        <dd class="col-sm-8">{{ $asset->useful_life ? $asset->useful_life.' '.__(($asset->life_unit).($asset->useful_life > 1 ? 's' : '')) : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Depreciation Start') }}</dt>
                        <dd class="col-sm-8">{{ $asset->depreciation_start_date ? $asset->depreciation_start_date->format('Y-m-d') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Location') }}</dt>
                        <dd class="col-sm-8">{{ $asset->location ? $asset->location->code.' — '.$asset->location->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Cost Centre') }}</dt>
                        <dd class="col-sm-8">{{ $asset->costCentre ? $asset->costCentre->code.' — '.$asset->costCentre->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Custodian') }}</dt>
                        <dd class="col-sm-8">{{ $asset->custodian->name ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Description') }}</dt>
                        <dd class="col-sm-8">{{ $asset->description ?? '—' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5>{{ __('Status History') }}</h5></div>
                <div class="card-body">
                    @forelse ($asset->statusHistory as $history)
                        <p class="mb-1">
                            <strong>{{ $history->changed_at ? $history->changed_at->format('Y-m-d H:i') : '' }}</strong>
                            — {{ $history->from_status ? ucfirst(str_replace('_', ' ', $history->from_status)).' → ' : '' }}{{ ucfirst(str_replace('_', ' ', $history->to_status)) }}
                            @if ($history->reason) <span class="text-muted">({{ $history->reason }})</span> @endif
                        </p>
                    @empty
                        <p class="text-muted">{{ __('No status changes recorded.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><h5>{{ __('Asset Label') }}</h5></div>
                <div class="card-body text-center">
                    {!! DNS2D::getBarcodeHTML(route('fixed-assets.show', $asset->id), 'QRCODE', 3, 3) !!}
                    <p class="mt-2 mb-0"><strong>{{ $asset->code }}</strong></p>
                    <p class="text-muted">{{ $asset->name }}</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5>{{ __('Approvals') }}</h5></div>
                <div class="card-body">
                    @forelse ($asset->approvals as $approval)
                        <p class="mb-1">
                            <strong>{{ ucfirst($approval->transaction_type) }}</strong>
                            — {{ ucfirst($approval->decision) }}
                            @if ($approval->decided_at) <span class="text-muted">({{ $approval->decided_at->format('Y-m-d') }})</span> @endif
                        </p>
                    @empty
                        <p class="text-muted">{{ __('No approvals recorded.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5>{{ __('Documents') }}</h5></div>
                <div class="card-body">
                    @forelse ($asset->documents as $document)
                        <p class="mb-1">
                            <strong>{{ ucfirst(str_replace('_', ' ', $document->type)) }}</strong>
                            — {{ $document->file_name }} <span class="text-muted">(v{{ $document->version }})</span>
                        </p>
                    @empty
                        <p class="text-muted">{{ __('No documents attached.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
