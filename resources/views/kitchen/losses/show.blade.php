@extends('layouts.admin')
@section('page-title')
    {{ __('Loss') }} {{ $loss->loss_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.losses.index') }}">{{ __('Kitchen Losses') }}</a></li>
    <li class="breadcrumb-item">{{ $loss->loss_number }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('approve kitchen losses')
            @if ($loss->isRecorded())
                <form method="POST" action="{{ route('kitchen.losses.approve', $loss->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#reject-loss-modal">{{ __('Reject') }}</button>
            @endif
        @endcan
        @can('manage kitchen losses')
            @if ($loss->isApproved())
                <form method="POST" action="{{ route('kitchen.losses.post', $loss->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Post Loss') }}</button>
                </form>
            @endif
            @if ($loss->isRecorded() || ($loss->isApproved() && ! $loss->needsApproval()))
                <a href="{{ route('kitchen.losses.edit', $loss->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('kitchen.losses.destroy', $loss->id) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete this loss?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger">{{ __('Delete') }}</button>
                </form>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3"><strong>{{ __('Loss No') }}</strong><br>{{ $loss->loss_number }}</div>
                        <div class="col-md-3"><strong>{{ __('Status') }}</strong><br>
                            @php
                                $badge = [
                                    'recorded' => 'bg-warning',
                                    'approved' => 'bg-info',
                                    'posted' => 'bg-success',
                                    'rejected' => 'bg-danger',
                                ][$loss->status] ?? 'bg-secondary';
                            @endphp
                            <span class="badge {{ $badge }}">{{ ucfirst($loss->status) }}</span>
                        </div>
                        <div class="col-md-3"><strong>{{ __('Category') }}</strong><br>{{ ucfirst(str_replace('_', ' ', $loss->category)) }}</div>
                        <div class="col-md-3"><strong>{{ __('Product') }}</strong><br>{{ $loss->product ? $loss->product->name : ('#'.$loss->product_id) }}</div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-3"><strong>{{ __('Quantity') }}</strong><br>{{ number_format((float) $loss->quantity, 2) }} {{ $loss->unit ? $loss->unit->name : '' }}</div>
                        <div class="col-md-3"><strong>{{ __('Unit Cost') }}</strong><br>{{ \Auth::user()->priceFormat((float) $loss->unit_cost) }}</div>
                        <div class="col-md-3"><strong>{{ __('Total Cost') }}</strong><br>{{ \Auth::user()->priceFormat((float) $loss->total_cost) }}</div>
                        <div class="col-md-3"><strong>{{ __('Warehouse') }}</strong><br>{{ $loss->warehouse ? $loss->warehouse->name : '-' }}</div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-3"><strong>{{ __('Batch') }}</strong><br>
                            @if ($loss->batch)
                                <a href="{{ route('kitchen.stock.show', $loss->batch->id) }}">{{ $loss->batch->batch_number }}</a>
                            @else
                                -
                            @endif
                        </div>
                        <div class="col-md-3"><strong>{{ __('Production Order') }}</strong><br>
                            @if ($loss->order)
                                <a href="{{ route('kitchen.production-orders.show', $loss->order->id) }}">{{ $loss->order->order_number ?? ('#'.$loss->order->id) }}</a>
                            @else
                                -
                            @endif
                        </div>
                        <div class="col-md-3"><strong>{{ __('POS Order') }}</strong><br>
                            @if ($loss->pos)
                                {{ $loss->pos->pos_id ?? ('#'.$loss->pos->id) }}
                            @else
                                -
                            @endif
                        </div>
                        <div class="col-md-3"><strong>{{ __('Write-off') }}</strong><br>
                            @if ($loss->writeOff)
                                {{ $loss->writeOff->write_off_no }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6"><strong>{{ __('Reason') }}</strong><br>{{ $loss->reason ?: '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Destination Warehouse') }}</strong><br>{{ $loss->destinationWarehouse ? $loss->destinationWarehouse->name : '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Recorded By') }}</strong><br>{{ $loss->recordedBy ? $loss->recordedBy->name : '-' }}<br><small class="text-muted">{{ $loss->created_at }}</small></div>
                    </div>

                    @if ($loss->reuse_flag)
                        <div class="row mt-3">
                            <div class="col-md-3"><strong>{{ __('Reusable') }}</strong><br><span class="badge bg-primary">{{ __('Yes') }}</span></div>
                            <div class="col-md-3"><strong>{{ __('Reuse Destination') }}</strong><br>{{ ucfirst(str_replace('_', ' ', $loss->reuse_destination ?? '')) }}</div>
                            <div class="col-md-6"><strong>{{ __('Food-Safety Note') }}</strong><br>{{ $loss->food_safety_note ?: '-' }}</div>
                        </div>
                    @endif

                    <div class="row mt-3">
                        <div class="col-md-4"><strong>{{ __('Approved By') }}</strong><br>{{ $loss->approvedBy ? $loss->approvedBy->name : '-' }}<br><small class="text-muted">{{ $loss->approved_at ?: '' }}</small></div>
                        <div class="col-md-4"><strong>{{ __('Posted By') }}</strong><br>{{ $loss->postedBy ? $loss->postedBy->name : '-' }}<br><small class="text-muted">{{ $loss->posted_at ?: '' }}</small></div>
                        <div class="col-md-4"><strong>{{ __('Rejected By') }}</strong><br>{{ $loss->rejectedBy ? $loss->rejectedBy->name : '-' }}<br><small class="text-muted">{{ $loss->rejected_at ?: '' }}</small></div>
                    </div>

                    @if ($loss->isRejected() && $loss->rejection_note)
                        <div class="alert alert-danger mt-3">{{ __('Rejected') }}: {{ $loss->rejection_note }}</div>
                    @endif

                    @if ($reconciliation)
                        <h6 class="mt-4">{{ __('Batch Reconciliation') }} — {{ $loss->batch->batch_number }}</h6>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Produced') }}</th>
                                    <th>{{ __('Sold') }}</th>
                                    <th>{{ __('Lost') }}</th>
                                    <th>{{ __('Remaining') }}</th>
                                    <th>{{ __('Balanced') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>{{ number_format($reconciliation['produced'], 2) }}</td>
                                    <td>{{ number_format($reconciliation['sold'], 2) }}</td>
                                    <td>{{ number_format($reconciliation['lost'], 2) }}</td>
                                    <td>{{ number_format($reconciliation['remaining'], 2) }}</td>
                                    <td>
                                        @if ($reconciliation['balanced'])
                                            <span class="badge bg-success">{{ __('Yes') }}</span>
                                        @else
                                            <span class="badge bg-danger">{{ __('No — difference :diff', ['diff' => $reconciliation['difference']]) }}</span>
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reject-loss-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('kitchen.losses.reject', $loss->id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Reject Loss') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">{{ __('Reason (shown to the kitchen)') }}</label>
                        <textarea name="rejection_note" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-danger">{{ __('Reject Loss') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
