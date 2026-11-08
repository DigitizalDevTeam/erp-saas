@extends('layouts.admin')
@section('page-title')
    {{ __('Assignment') }} — {{ $assignment->asset ? $assignment->asset->code : '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-assignments.index') }}">{{ __('Assignments') }}</a></li>
    <li class="breadcrumb-item">{{ $assignment->asset ? $assignment->asset->code : '' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $assignment->asset ? $assignment->asset->name : __('Assignment') }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $assignment->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Asset') }}</dt>
                        <dd class="col-sm-8">
                            @if ($assignment->asset)
                                <a href="{{ route('fixed-assets.show', $assignment->asset->id) }}"><strong>{{ $assignment->asset->code }}</strong></a> — {{ $assignment->asset->name }}
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Custodian') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->custodian ? $assignment->custodian->name : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Location') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->location ? $assignment->location->code.' — '.$assignment->location->name : __('Keep the current location') }}</dd>
                        <dt class="col-sm-4">{{ __('Cost Centre') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->costCentre ? $assignment->costCentre->code.' — '.$assignment->costCentre->name : __('Keep the current cost centre') }}</dd>
                        <dt class="col-sm-4">{{ __('Condition') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->condition ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Accessories') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->accessories ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Requested') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->requested_at ? $assignment->requested_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Approved') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->approved_at ? $assignment->approved_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Handed Over') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->handed_over_at ? $assignment->handed_over_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Accepted') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->accepted_at ? $assignment->accepted_at->format('Y-m-d H:i') : '—' }}</dd>
                        @if ($assignment->refusal_reason)
                            <dt class="col-sm-4">{{ __('Refusal Reason') }}</dt>
                            <dd class="col-sm-8">{{ $assignment->refusal_reason }}</dd>
                        @endif
                        @if ($assignment->return_reason)
                            <dt class="col-sm-4">{{ __('Return Reason') }}</dt>
                            <dd class="col-sm-8">{{ $assignment->return_reason }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Notes') }}</dt>
                        <dd class="col-sm-8">{{ $assignment->notes ?? '—' }}</dd>
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
                    @if ($assignment->status === \App\Models\FixedAssetAssignment::STATUS_REQUESTED)
                        <form method="POST" action="{{ route('fixed-asset-assignments.decide', $assignment->id) }}" class="mb-2">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="decision_reason">{{ __('Decision Reason') }}</label>
                                <textarea name="reason" id="decision_reason" class="form-control" rows="2"></textarea>
                            </div>
                            <button type="submit" name="decision" value="approved" class="btn btn-success w-100 mb-2">{{ __('Approve') }}</button>
                            <button type="submit" name="decision" value="returned" class="btn btn-warning w-100 mb-2">{{ __('Return') }}</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn-danger w-100">{{ __('Reject') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-assignments.cancel', $assignment->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Assignment') }}</button>
                        </form>
                    @elseif ($assignment->status === \App\Models\FixedAssetAssignment::STATUS_APPROVED)
                        <form method="POST" action="{{ route('fixed-asset-assignments.handover', $assignment->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Hand Over') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-assignments.cancel', $assignment->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Assignment') }}</button>
                        </form>
                    @elseif ($assignment->status === \App\Models\FixedAssetAssignment::STATUS_HANDED_OVER)
                        <form method="POST" action="{{ route('fixed-asset-assignments.accept', $assignment->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">{{ __('Accept (Custodian)') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-assignments.refuse', $assignment->id) }}">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="refuse_reason">{{ __('Refusal Reason') }} *</label>
                                <textarea name="reason" id="refuse_reason" class="form-control" rows="2" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">{{ __('Refuse (Custodian)') }}</button>
                        </form>
                    @elseif ($assignment->status === \App\Models\FixedAssetAssignment::STATUS_ACCEPTED)
                        <form method="POST" action="{{ route('fixed-asset-assignments.return', $assignment->id) }}">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="return_reason">{{ __('Return Reason') }}</label>
                                <textarea name="reason" id="return_reason" class="form-control" rows="2"></textarea>
                            </div>
                            <button type="submit" class="btn btn-warning w-100">{{ __('Return Asset') }}</button>
                        </form>
                    @else
                        <p class="text-muted">{{ __('This assignment is :status.', ['status' => str_replace('_', ' ', $assignment->status)]) }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
