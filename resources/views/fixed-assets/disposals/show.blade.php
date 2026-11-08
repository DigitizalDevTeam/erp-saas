@extends('layouts.admin')
@section('page-title')
    {{ __('Disposal') }} — {{ $disposal->asset ? $disposal->asset->code : '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-disposals.index') }}">{{ __('Disposals') }}</a></li>
    <li class="breadcrumb-item">{{ $disposal->asset ? $disposal->asset->code : '' }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $disposal->asset ? $disposal->asset->name : __('Disposal') }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $disposal->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Asset') }}</dt>
                        <dd class="col-sm-8">
                            @if ($disposal->asset)
                                <a href="{{ route('fixed-assets.show', $disposal->asset->id) }}"><strong>{{ $disposal->asset->code }}</strong></a> — {{ $disposal->asset->name }}
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Method') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst(str_replace('_', ' ', $disposal->method)) }}</dd>
                        <dt class="col-sm-4">{{ __('Reason') }}</dt>
                        <dd class="col-sm-8">{{ $disposal->reason }}</dd>
                        @if ($disposal->buyer_name)
                            <dt class="col-sm-4">{{ __('Buyer') }}</dt>
                            <dd class="col-sm-8">{{ $disposal->buyer_name }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Expected Proceeds') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $disposal->expected_proceeds, 2) }}</dd>
                        <dt class="col-sm-4">{{ __('Actual Proceeds') }}</dt>
                        <dd class="col-sm-8">{{ $disposal->actual_proceeds !== null ? number_format((float) $disposal->actual_proceeds, 2) : '—' }}</dd>
                        @if ($disposal->actual_proceeds !== null)
                            <dt class="col-sm-4">{{ __('Proceeds Received') }}</dt>
                            <dd class="col-sm-8">{{ $disposal->proceeds_received ? __('Yes — cash / bank') : __('No — on credit') }}</dd>
                        @endif
                        @if ($disposal->book_value_at_disposal !== null)
                            <dt class="col-sm-4">{{ __('Book Value at Disposal') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $disposal->book_value_at_disposal, 2) }}</dd>
                            <dt class="col-sm-4">{{ __('Accumulated Depreciation') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $disposal->accumulated_depreciation_at_disposal, 2) }}</dd>
                            <dt class="col-sm-4">{{ __('Gain / Loss') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $disposal->gain_loss_amount, 2) }}</dd>
                        @endif
                        @if ($disposal->journal)
                            <dt class="col-sm-4">{{ __('Journal') }}</dt>
                            <dd class="col-sm-8">{{ $disposal->journal->reference }} ({{ $disposal->journal->date }})</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Requested') }}</dt>
                        <dd class="col-sm-8">{{ $disposal->requested_at ? $disposal->requested_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Approved') }}</dt>
                        <dd class="col-sm-8">{{ $disposal->approved_at ? $disposal->approved_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Executed') }}</dt>
                        <dd class="col-sm-8">{{ $disposal->executed_at ? $disposal->executed_at->format('Y-m-d H:i') : '—' }}</dd>
                        @if ($disposal->disposal_date)
                            <dt class="col-sm-4">{{ __('Disposal Date') }}</dt>
                            <dd class="col-sm-8">{{ $disposal->disposal_date->format('Y-m-d') }}</dd>
                        @endif
                        @if ($disposal->notes)
                            <dt class="col-sm-4">{{ __('Notes') }}</dt>
                            <dd class="col-sm-8">{{ $disposal->notes }}</dd>
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
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><h5>{{ __('Actions') }}</h5></div>
                <div class="card-body">
                    @if ($disposal->status === 'requested')
                        @can('approve fixed asset disposals')
                            <form method="POST" action="{{ route('fixed-asset-disposals.decide', $disposal->id) }}" class="mb-2">
                                @csrf
                                <input type="hidden" name="decision" value="approved">
                                <button type="submit" class="btn btn-success w-100">{{ __('Approve') }}</button>
                            </form>
                            <form method="POST" action="{{ route('fixed-asset-disposals.decide', $disposal->id) }}">
                                @csrf
                                <input type="hidden" name="decision" value="rejected">
                                <div class="form-group">
                                    <input type="text" name="reason" class="form-control" placeholder="{{ __('Rejection reason') }}" maxlength="500">
                                </div>
                                <button type="submit" class="btn btn-danger w-100">{{ __('Reject') }}</button>
                            </form>
                        @endcan
                        <form method="POST" action="{{ route('fixed-asset-disposals.cancel', $disposal->id) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Request') }}</button>
                        </form>
                    @endif
                    @if ($disposal->status === 'approved')
                        <a href="{{ route('fixed-asset-disposals.execute-form', $disposal->id) }}" class="btn btn-primary w-100">{{ __('Execute Disposal') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
