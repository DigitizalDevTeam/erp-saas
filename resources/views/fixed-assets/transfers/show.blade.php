@extends('layouts.admin')
@section('page-title')
    {{ __('Transfer') }} — {{ $transfer->asset ? $transfer->asset->code : '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-transfers.index') }}">{{ __('Transfers') }}</a></li>
    <li class="breadcrumb-item">{{ $transfer->asset ? $transfer->asset->code : '' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $transfer->asset ? $transfer->asset->name : __('Transfer') }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $transfer->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Asset') }}</dt>
                        <dd class="col-sm-8">
                            @if ($transfer->asset)
                                <a href="{{ route('fixed-assets.show', $transfer->asset->id) }}"><strong>{{ $transfer->asset->code }}</strong></a> — {{ $transfer->asset->name }}
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('From Location') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->fromLocation ? $transfer->fromLocation->code.' — '.$transfer->fromLocation->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('To Location') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->toLocation ? $transfer->toLocation->code.' — '.$transfer->toLocation->name : __('Unchanged') }}</dd>
                        <dt class="col-sm-4">{{ __('From Custodian') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->fromCustodian ? $transfer->fromCustodian->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('To Custodian') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->toCustodian ? $transfer->toCustodian->name : __('Unchanged') }}</dd>
                        <dt class="col-sm-4">{{ __('From Cost Centre') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->fromCostCentre ? $transfer->fromCostCentre->code.' — '.$transfer->fromCostCentre->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('To Cost Centre') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->toCostCentre ? $transfer->toCostCentre->code.' — '.$transfer->toCostCentre->name : __('Unchanged') }}</dd>
                        <dt class="col-sm-4">{{ __('Requested') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->requested_at ? $transfer->requested_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Approved') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->approved_at ? $transfer->approved_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Handed Over') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->handed_over_at ? $transfer->handed_over_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Received') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->received_at ? $transfer->received_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Reason') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->reason ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Notes') }}</dt>
                        <dd class="col-sm-8">{{ $transfer->notes ?? '—' }}</dd>
                    </dl>

                    @if ($approvals->isNotEmpty())
                        <h6 class="mt-3">{{ __('Approvals') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Level') }}</th>
                                        <th>{{ __('Decision') }}</th>
                                        <th>{{ __('By') }}</th>
                                        <th>{{ __('Comment') }}</th>
                                        <th>{{ __('Decided') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($approvals as $approval)
                                        <tr>
                                            <td>{{ $approval->level }}</td>
                                            <td>{{ ucfirst($approval->decision) }}</td>
                                            <td>{{ $approval->approver ? $approval->approver->name : '—' }}</td>
                                            <td>{{ $approval->comment ?? '—' }}</td>
                                            <td>{{ $approval->decided_at ? $approval->decided_at->format('Y-m-d H:i') : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><h5>{{ __('Workflow') }}</h5></div>
                <div class="card-body">
                    @if ($transfer->status === \App\Models\FixedAssetTransfer::STATUS_REQUESTED)
                        <form method="POST" action="{{ route('fixed-asset-transfers.decide', $transfer->id) }}" class="mb-2">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="decision_reason">{{ __('Decision Reason') }}</label>
                                <textarea name="reason" id="decision_reason" class="form-control" rows="2"></textarea>
                            </div>
                            <button type="submit" name="decision" value="approved" class="btn btn-success w-100 mb-2">{{ __('Approve') }}</button>
                            <button type="submit" name="decision" value="returned" class="btn btn-warning w-100 mb-2">{{ __('Return') }}</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn-danger w-100">{{ __('Reject') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-transfers.cancel', $transfer->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Transfer') }}</button>
                        </form>
                    @elseif ($transfer->status === \App\Models\FixedAssetTransfer::STATUS_APPROVED)
                        <form method="POST" action="{{ route('fixed-asset-transfers.handover', $transfer->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Hand Over') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-transfers.cancel', $transfer->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Transfer') }}</button>
                        </form>
                    @elseif ($transfer->status === \App\Models\FixedAssetTransfer::STATUS_HANDED_OVER)
                        <form method="POST" action="{{ route('fixed-asset-transfers.receive', $transfer->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">{{ __('Receive at Destination') }}</button>
                        </form>
                        <p class="text-muted">{{ __('A handed-over transfer can only be received — the asset is already on its way.') }}</p>
                    @else
                        <p class="text-muted">{{ __('This transfer is :status.', ['status' => str_replace('_', ' ', $transfer->status)]) }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
