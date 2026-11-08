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
        @if ($asset->status === \App\Models\FixedAsset::STATUS_DRAFT)
            @can('edit fixed assets')
                <a href="{{ route('fixed-assets.edit', $asset->id) }}" class="btn btn-sm btn-primary">
                    <i class="ti ti-pencil"></i> {{ __('Edit') }}
                </a>
            @endcan
        @endif
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

            {{-- Cost Centre Phase 2: Cost Centre Allocation tab — the
                 split in force today + the assignment history. The
                 depreciation engine splits the period by the
                 assignment in force for that period. --}}
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Cost Centre Allocation') }}</h5>
                    @can('manage asset cost centre assignments')
                        <a href="{{ route('asset-cost-centre-assignments.create', ['asset_id' => $asset->id]) }}" class="btn btn-sm btn-primary float-end">{{ __('New Assignment') }}</a>
                    @endcan
                </div>
                <div class="card-body">
                    <h6>{{ __('Split in force today') }}</h6>
                    @if (empty($currentCostCentreSplit))
                        <p class="text-muted">{{ __('No split assignment covers today — depreciation falls back to the asset\'s own cost centre') }} ({{ $asset->costCentre ? $asset->costCentre->code : '—' }}).</p>
                    @else
                        <ul>
                            @foreach ($currentCostCentreSplit as $split)
                                @php $splitCentre = \App\Models\CostCentre::find($split['cost_centre_id']); @endphp
                                <li><strong>{{ $splitCentre?->code }}</strong> — {{ $splitCentre?->name }}: {{ number_format((float) $split['percentage'], 2) }}%</li>
                            @endforeach
                        </ul>
                    @endif

                    <h6>{{ __('Assignment history') }}</h6>
                    @if ($costCentreAssignments->isEmpty())
                        <p class="text-muted">{{ __('No cost-centre assignments for this asset yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Cost centre') }}</th>
                                        <th class="text-end">{{ __('Share') }}</th>
                                        <th>{{ __('Valid from') }}</th>
                                        <th>{{ __('Valid to') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($costCentreAssignments as $assignmentRow)
                                        <tr>
                                            <td>{{ $assignmentRow->costCentre?->code }} — {{ $assignmentRow->costCentre?->name }}</td>
                                            <td class="text-end">{{ number_format((float) $assignmentRow->percentage, 2) }}%</td>
                                            <td>{{ $assignmentRow->valid_from?->format('Y-m-d') }}</td>
                                            <td>{{ $assignmentRow->valid_to?->format('Y-m-d') ?? __('Open') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <a href="{{ route('asset-cost-centre-assignments.show', $asset->id) }}">{{ __('Full assignment history') }}</a>
                </div>
            </div>

            @if ($acquisition)
                <div class="card">
                    <div class="card-header">
                        <h5>{{ __('Acquisition & Capitalization') }}</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">{{ __('Source') }}</dt>
                            <dd class="col-sm-8">{{ ucfirst(str_replace('_', ' ', $acquisition->source_type)) }}@if ($acquisition->source_reference) — {{ $acquisition->source_reference }}@endif</dd>
                            <dt class="col-sm-4">{{ __('Status') }}</dt>
                            <dd class="col-sm-8">{{ ucfirst(str_replace('_', ' ', $acquisition->status)) }}</dd>
                            <dt class="col-sm-4">{{ __('Total Cost (base)') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $acquisition->total_cost_base, 2) }}</dd>
                            <dt class="col-sm-4">{{ __('Capitalization') }}</dt>
                            <dd class="col-sm-8">
                                @if ($capitalization)
                                    {{ __('Capitalized on :date', ['date' => $capitalization->capitalization_date ? $capitalization->capitalization_date->format('Y-m-d') : '—']) }}
                                    @if ($capitalization->journal_id)
                                        — <a href="{{ route('journal-entry.show', $capitalization->journal_id) }}">{{ __('Journal #:id', ['id' => $capitalization->journal_id]) }}</a>
                                    @endif
                                @else
                                    {{ __('Not capitalized yet.') }}
                                @endif
                            </dd>
                        </dl>

                        <div class="mt-2">
                            <a href="{{ route('fixed-asset-acquisitions.show', $acquisition->id) }}" class="btn btn-sm btn-primary">{{ __('Open Acquisition Workflow') }}</a>
                            @if ($acquisition->status === \App\Models\FixedAssetAcquisition::STATUS_APPROVED)
                                <form method="POST" action="{{ route('fixed-asset-acquisitions.capitalize', $acquisition->id) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">{{ __('Capitalize Asset') }}</button>
                                </form>
                            @endif
                        </div>

                        @if ($schedule->isNotEmpty())
                            <h6 class="mt-4">{{ __('Depreciation Schedule') }}</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Period') }}</th>
                                            <th>{{ __('Opening Book Value') }}</th>
                                            <th>{{ __('Planned Depreciation') }}</th>
                                            <th>{{ __('Posted') }}</th>
                                            <th>{{ __('Accumulated') }}</th>
                                            <th>{{ __('Closing Book Value') }}</th>
                                            <th>{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($schedule as $row)
                                            <tr>
                                                <td>{{ $row->period }}</td>
                                                <td>{{ number_format((float) $row->opening_book_value, 2) }}</td>
                                                <td>{{ number_format((float) $row->period_depreciation, 4) }}</td>
                                                <td>{{ isset($depreciationEntries[$row->id]) && $depreciationEntries[$row->id]->posted_amount !== null ? number_format((float) $depreciationEntries[$row->id]->posted_amount, 2) : '—' }}</td>
                                                <td>{{ number_format((float) $row->accumulated_depreciation, 2) }}</td>
                                                <td>{{ number_format((float) $row->closing_book_value, 2) }}</td>
                                                <td>{{ ucfirst($row->status) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($accountingEntries->isNotEmpty())
                            <h6 class="mt-4">{{ __('Accounting Entries') }}</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Type') }}</th>
                                            <th>{{ __('Date') }}</th>
                                            <th>{{ __('Amount') }}</th>
                                            <th>{{ __('Journal') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($accountingEntries as $entry)
                                            <tr>
                                                <td>{{ ucfirst(str_replace('_', ' ', $entry->transaction_type)) }}</td>
                                                <td>{{ $accountingJournalTotals[$entry->id]['date'] ?? '—' }}</td>
                                                <td>{{ number_format((float) ($accountingJournalTotals[$entry->id]['amount'] ?? 0), 2) }}</td>
                                                <td>
                                                    @if ($entry->journal_id)
                                                        <a href="{{ route('journal-entry.show', $entry->journal_id) }}">{{ __('Journal #:id', ['id' => $entry->journal_id]) }}</a>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

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

            <div class="card">
                <div class="card-header"><h5>{{ __('History Timeline') }}</h5></div>
                <div class="card-body">
                    @forelse ($timeline as $entry)
                        <p class="mb-1">
                            <strong>{{ $entry['at'] ? $entry['at']->format('Y-m-d H:i') : '' }}</strong>
                            — {{ $entry['title'] }} <span class="text-muted">{{ $entry['detail'] }}</span>
                        </p>
                    @empty
                        <p class="text-muted">{{ __('No history recorded yet.') }}</p>
                    @endforelse
                    <div class="mt-3">
                        @can('manage fixed assets')
                            <a href="{{ route('fixed-asset-assignments.index', ['search' => $asset->code]) }}" class="btn btn-sm btn-outline-primary">{{ __('Assignments') }}</a>
                            <a href="{{ route('fixed-asset-transfers.index', ['search' => $asset->code]) }}" class="btn btn-sm btn-outline-primary">{{ __('Transfers') }}</a>
                            <a href="{{ route('fixed-asset-disposals.index', ['search' => $asset->code]) }}" class="btn btn-sm btn-outline-primary">{{ __('Disposals') }}</a>
                        @endcan
                    </div>
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
