@extends('layouts.admin')
@section('page-title')
    {{ __('Acquisition') }} — {{ $asset ? $asset->code : '#'.$acquisition->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-acquisitions.index') }}">{{ __('Acquisitions') }}</a></li>
    <li class="breadcrumb-item">{{ $asset ? $asset->code : '#'.$acquisition->id }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @if ($asset)
            <a href="{{ route('fixed-assets.show', $asset->id) }}" class="btn btn-sm btn-secondary">{{ __('View Asset') }}</a>
            <a href="{{ route('fixed-assets.label', $asset->id) }}" class="btn btn-sm btn-secondary" target="_blank">{{ __('Print Label') }}</a>
        @endif
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $asset ? $asset->name : __('Acquisition') }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $acquisition->status)) }}</span>
                    <span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $acquisition->source_type)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Source Reference') }}</dt>
                        <dd class="col-sm-8">{{ $acquisition->source_reference ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Supplier') }}</dt>
                        <dd class="col-sm-8">{{ $acquisition->supplier ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Purchase Date') }}</dt>
                        <dd class="col-sm-8">{{ $acquisition->purchase_date ? $acquisition->purchase_date->format('Y-m-d') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Receipt Date') }}</dt>
                        <dd class="col-sm-8">{{ $acquisition->receipt_date ? $acquisition->receipt_date->format('Y-m-d') : '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Currency / Rate') }}</dt>
                        <dd class="col-sm-8">{{ $acquisition->currency }} @ {{ $acquisition->exchange_rate }}</dd>
                        <dt class="col-sm-4">{{ __('Total Cost (original)') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $acquisition->total_cost, 2) }} {{ $acquisition->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Total Cost (base)') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $acquisition->total_cost_base, 2) }}</dd>
                        <dt class="col-sm-4">{{ __('Capitalization') }}</dt>
                        <dd class="col-sm-8">
                            @if ($acquisition->capitalization)
                                {{ __('Capitalized on :date', ['date' => $acquisition->capitalization->capitalization_date ? $acquisition->capitalization->capitalization_date->format('Y-m-d') : '—']) }}
                                @if ($acquisition->capitalization->journal)
                                    — <a href="{{ route('journal-entry.show', $acquisition->capitalization->journal->id) }}">{{ __('Journal #:id', ['id' => $acquisition->capitalization->journal->id]) }}</a>
                                @endif
                            @else
                                {{ __('Not capitalized yet.') }}
                            @endif
                        </dd>
                        <dt class="col-sm-4">{{ __('Notes') }}</dt>
                        <dd class="col-sm-8">{{ $acquisition->notes ?? '—' }}</dd>
                    </dl>

                    <h6 class="mt-3">{{ __('Cost Components') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Component') }}</th>
                                    <th>{{ __('Gross') }}</th>
                                    <th>{{ __('VAT') }}</th>
                                    <th>{{ __('VAT Recoverable') }}</th>
                                    <th>{{ __('Contribution') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($acquisition->costs as $cost)
                                    <tr>
                                        <td>{{ ucfirst(str_replace('_', ' ', $cost->component_type)) }}</td>
                                        <td>{{ number_format((float) $cost->gross_amount, 2) }}</td>
                                        <td>{{ number_format((float) $cost->vat_amount, 2) }}</td>
                                        <td>{{ $cost->vat_recoverable ? __('Yes') : __('No') }}</td>
                                        <td>{{ number_format((float) $cost->contribution, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center">{{ __('No cost components recorded.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($schedule->isNotEmpty())
                        <h6 class="mt-3">{{ __('Depreciation Schedule') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Period') }}</th>
                                        <th>{{ __('Depreciation') }}</th>
                                        <th>{{ __('Closing Book Value') }}</th>
                                        <th>{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($schedule as $row)
                                        <tr>
                                            <td>{{ $row->period }}</td>
                                            <td>{{ number_format((float) $row->depreciation_amount, 2) }}</td>
                                            <td>{{ number_format((float) $row->closing_book_value, 2) }}</td>
                                            <td>{{ ucfirst($row->status) }}</td>
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
                    @if ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_DRAFT)
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.submit-review', $acquisition->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Submit for Review') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.cancel', $acquisition->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Acquisition') }}</button>
                        </form>
                    @elseif ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_PENDING_REVIEW)
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.submit-approval', $acquisition->id) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Submit for Approval') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.return', $acquisition->id) }}">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="return_reason">{{ __('Return Reason') }} *</label>
                                <textarea name="reason" id="return_reason" class="form-control" rows="2" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-warning w-100">{{ __('Return for Correction') }}</button>
                        </form>
                    @elseif ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_PENDING_APPROVAL)
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.decide', $acquisition->id) }}" class="mb-2">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="decision_reason">{{ __('Decision Reason') }}</label>
                                <textarea name="reason" id="decision_reason" class="form-control" rows="2"></textarea>
                            </div>
                            <button type="submit" name="decision" value="approved" class="btn btn-success w-100 mb-2">{{ __('Approve') }}</button>
                            <button type="submit" name="decision" value="returned" class="btn btn-warning w-100 mb-2">{{ __('Return') }}</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn-danger w-100">{{ __('Reject') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.reject', $acquisition->id) }}">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="reject_reason">{{ __('Rejection Reason') }} *</label>
                                <textarea name="reason" id="reject_reason" class="form-control" rows="2" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-danger w-100">{{ __('Reject Acquisition') }}</button>
                        </form>
                    @elseif ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_APPROVED)
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.capitalize', $acquisition->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Capitalize Asset') }}</button>
                        </form>
                        <p class="text-muted mt-2">{{ __('Capitalization posts the journal (Dr asset / Cr clearing account or supplier) and activates the depreciation schedule.') }}</p>
                    @elseif ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_CAPITALIZED)
                        <p class="text-success">{{ __('This acquisition is capitalized.') }}</p>
                    @elseif ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_RETURNED)
                        <form method="POST" action="{{ route('fixed-asset-acquisitions.submit-review', $acquisition->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('Resubmit for Review') }}</button>
                        </form>
                    @else
                        <p class="text-muted">{{ __('No actions available for this status.') }}</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5>{{ __('Approvals') }}</h5></div>
                <div class="card-body">
                    @forelse ($acquisition->approvals as $approval)
                        <p class="mb-1">
                            <strong>{{ __('Level :level', ['level' => $approval->level]) }}</strong>
                            — {{ ucfirst($approval->decision) }}
                            @if ($approval->decided_at) <span class="text-muted">({{ $approval->decided_at->format('Y-m-d H:i') }})</span> @endif
                            @if ($approval->reason) <br><span class="text-muted">{{ $approval->reason }}</span> @endif
                        </p>
                    @empty
                        <p class="text-muted">{{ __('No approvals recorded.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
