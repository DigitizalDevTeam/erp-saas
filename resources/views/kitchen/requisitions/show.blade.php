@extends('layouts.admin')
@section('page-title')
    {{ __('Requisition') }} {{ $requisition->requisition_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.requisitions.index') }}">{{ __('Requisitions') }}</a></li>
    <li class="breadcrumb-item">{{ $requisition->requisition_number }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('approve kitchen requisitions')
            @if ($requisition->isRequested())
                <form method="POST" action="{{ route('kitchen.requisitions.approve', $requisition->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#reject-requisition-modal">{{ __('Reject') }}</button>
            @endif
        @endcan
        @can('manage kitchen requisitions')
            @if ($requisition->isApproved() || $requisition->isPartiallyIssued())
                <a href="{{ route('kitchen.requisitions.issue', $requisition->id) }}" class="btn btn-sm btn-success">{{ __('Issue Stock') }}</a>
            @endif
            @if ($requisition->isRequested())
                <a href="{{ route('kitchen.requisitions.edit', $requisition->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
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
                        <div class="col-md-3"><strong>{{ __('Requisition No') }}</strong><br>{{ $requisition->requisition_number }}</div>
                        <div class="col-md-3"><strong>{{ __('Status') }}</strong><br>
                            @php
                                $badge = [
                                    'requested' => 'bg-warning',
                                    'approved' => 'bg-info',
                                    'issued' => 'bg-success',
                                    'partially_issued' => 'bg-primary',
                                    'rejected' => 'bg-danger',
                                ][$requisition->status] ?? 'bg-secondary';
                            @endphp
                            <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $requisition->status)) }}</span>
                        </div>
                        <div class="col-md-3"><strong>{{ __('Source Warehouse') }}</strong><br>{{ $requisition->sourceWarehouse ? $requisition->sourceWarehouse->name : '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Kitchen Warehouse') }}</strong><br>{{ $requisition->kitchenWarehouse ? $requisition->kitchenWarehouse->name : '-' }}</div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-3"><strong>{{ __('From Plan') }}</strong><br>
                            @if ($requisition->plan)
                                <a href="{{ route('kitchen.production-plans.show', $requisition->plan->id) }}">{{ $requisition->plan->plan_number }}</a>
                            @else
                                -
                            @endif
                        </div>
                        <div class="col-md-3"><strong>{{ __('From Order') }}</strong><br>
                            @if ($requisition->order)
                                <a href="{{ route('kitchen.production-orders.show', $requisition->order->id) }}">{{ $requisition->order->order_number ?? ('#'.$requisition->order->id) }}</a>
                            @else
                                -
                            @endif
                        </div>
                        <div class="col-md-3"><strong>{{ __('Branch') }}</strong><br>{{ $requisition->branch ? $requisition->branch->name : '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Notes') }}</strong><br>{{ $requisition->notes ?: '-' }}</div>
                    </div>

                    @if ($requisition->isRejected() && $requisition->rejection_note)
                        <div class="alert alert-danger mt-3">{{ __('Rejected') }}: {{ $requisition->rejection_note }}</div>
                    @endif

                    <h6 class="mt-4">{{ __('Requested Ingredients') }}</h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Required') }}</th>
                                <th>{{ __('Available in Kitchen') }}</th>
                                <th>{{ __('Shortage') }}</th>
                                <th>{{ __('Issued') }}</th>
                                <th>{{ __('Remaining') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($requisition->lines as $line)
                                <tr>
                                    <td>{{ $line->ingredient ? $line->ingredient->name : ('#'.$line->ingredient_product_id) }}</td>
                                    <td>{{ number_format((float) $line->required_quantity, 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>{{ number_format((float) $line->available_quantity, 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>{{ number_format((float) $line->shortage_quantity, 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>{{ number_format((float) $line->issued_quantity, 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>{{ number_format($line->remainingQuantity(), 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center">{{ __('No ingredients requested.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reject-requisition-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('kitchen.requisitions.reject', $requisition->id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Reject Requisition') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">{{ __('Reason (shown to the kitchen)') }}</label>
                        <textarea name="rejection_note" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-danger">{{ __('Reject Requisition') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
