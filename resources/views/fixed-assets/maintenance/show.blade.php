@extends('layouts.admin')
@section('page-title')
    {{ __('Maintenance') }} — {{ $maintenance->asset ? $maintenance->asset->code : '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-maintenance.index') }}">{{ __('Maintenance') }}</a></li>
    <li class="breadcrumb-item">{{ $maintenance->asset ? $maintenance->asset->code : '' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $maintenance->asset ? $maintenance->asset->name : __('Maintenance') }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $maintenance->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Asset') }}</dt>
                        <dd class="col-sm-8">
                            @if ($maintenance->asset)
                                <a href="{{ route('fixed-assets.show', $maintenance->asset->id) }}"><strong>{{ $maintenance->asset->code }}</strong></a> — {{ $maintenance->asset->name }}
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Type') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst($maintenance->type) }}</dd>
                        <dt class="col-sm-4">{{ __('Priority') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst($maintenance->priority) }}</dd>
                        <dt class="col-sm-4">{{ __('Description') }}</dt>
                        <dd class="col-sm-8">{{ $maintenance->description }}</dd>
                        <dt class="col-sm-4">{{ __('Expected Cost') }}</dt>
                        <dd class="col-sm-8">{{ $maintenance->expected_cost !== null ? number_format((float) $maintenance->expected_cost, 2) : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Total Actual Cost') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $maintenance->totalCost(), 2) }}</dd>
                        <dt class="col-sm-4">{{ __('Requested') }}</dt>
                        <dd class="col-sm-8">{{ $maintenance->requested_at ? $maintenance->requested_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Approved') }}</dt>
                        <dd class="col-sm-8">{{ $maintenance->approved_at ? $maintenance->approved_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Started') }}</dt>
                        <dd class="col-sm-8">{{ $maintenance->started_at ? $maintenance->started_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Completed') }}</dt>
                        <dd class="col-sm-8">{{ $maintenance->completed_at ? $maintenance->completed_at->format('Y-m-d H:i') : '—' }}</dd>
                        @if ($maintenance->completion_notes)
                            <dt class="col-sm-4">{{ __('Completion Notes') }}</dt>
                            <dd class="col-sm-8">{{ $maintenance->completion_notes }}</dd>
                        @endif
                        @if ($maintenance->rejection_reason)
                            <dt class="col-sm-4">{{ __('Rejection Reason') }}</dt>
                            <dd class="col-sm-8">{{ $maintenance->rejection_reason }}</dd>
                        @endif
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

                    <h6 class="mt-3">{{ __('Costs') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Vendor') }}</th>
                                    <th>{{ __('Recorded') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($maintenance->costs as $cost)
                                    <tr>
                                        <td>{{ ucfirst($cost->cost_type) }}</td>
                                        <td>{{ $cost->description ?? '—' }}</td>
                                        <td>{{ number_format((float) $cost->amount, 2) }}</td>
                                        <td>{{ $cost->vendor ?? '—' }}</td>
                                        <td>{{ $cost->recorded_at ? $cost->recorded_at->format('Y-m-d H:i') : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center">{{ __('No costs recorded yet. Costs post as expenses and never change the asset value.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if (in_array($maintenance->status, ['approved', 'in_progress', 'completed'], true))
                        <h6 class="mt-3">{{ __('Record a Cost') }}</h6>
                        <form method="POST" action="{{ route('fixed-asset-maintenance.costs', $maintenance->id) }}">
                            @csrf
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <select name="cost_type" class="form-control" required>
                                        @foreach (\App\Models\FixedAssetMaintenanceCost::COST_TYPES as $costType)
                                            <option value="{{ $costType }}">{{ ucfirst($costType) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="{{ __('Amount') }}" required>
                                </div>
                                <div class="col-md-3">
                                    <input type="text" name="vendor" class="form-control" placeholder="{{ __('Vendor (optional)') }}" maxlength="255">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary w-100">{{ __('Record Cost') }}</button>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-12">
                                    <input type="text" name="description" class="form-control" placeholder="{{ __('Cost description (optional)') }}" maxlength="1000">
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><h5>{{ __('Actions') }}</h5></div>
                <div class="card-body">
                    @if ($maintenance->status === 'requested')
                        <form method="POST" action="{{ route('fixed-asset-maintenance.decide', $maintenance->id) }}" class="mb-2">
                            @csrf
                            <input type="hidden" name="decision" value="approved">
                            <button type="submit" class="btn btn-success w-100">{{ __('Approve') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-maintenance.decide', $maintenance->id) }}">
                            @csrf
                            <input type="hidden" name="decision" value="rejected">
                            <div class="form-group">
                                <input type="text" name="reason" class="form-control" placeholder="{{ __('Rejection reason') }}" maxlength="500">
                            </div>
                            <button type="submit" class="btn btn-danger w-100">{{ __('Reject') }}</button>
                        </form>
                    @endif
                    @if ($maintenance->status === 'approved')
                        <form method="POST" action="{{ route('fixed-asset-maintenance.start', $maintenance->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Start Work') }}</button>
                        </form>
                    @endif
                    @if ($maintenance->status === 'in_progress')
                        <form method="POST" action="{{ route('fixed-asset-maintenance.complete', $maintenance->id) }}" class="mb-2">
                            @csrf
                            <div class="form-group">
                                <textarea name="notes" class="form-control" rows="2" placeholder="{{ __('Completion notes (optional)') }}" maxlength="2000"></textarea>
                            </div>
                            <button type="submit" class="btn btn-success w-100">{{ __('Complete') }}</button>
                        </form>
                    @endif
                    @if ($maintenance->status === 'completed')
                        <form method="POST" action="{{ route('fixed-asset-maintenance.verify', $maintenance->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Verify') }}</button>
                        </form>
                    @endif
                    @if (in_array($maintenance->status, ['requested', 'approved', 'in_progress'], true))
                        <form method="POST" action="{{ route('fixed-asset-maintenance.cancel', $maintenance->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Request') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
