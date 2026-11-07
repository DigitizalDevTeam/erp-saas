@extends('layouts.admin')
@section('page-title')
    {{ __('Production Plan') }} {{ $plan->plan_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.production-plans.index') }}">{{ __('Production Plans') }}</a></li>
    <li class="breadcrumb-item">{{ $plan->plan_number }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen production')
            @if ($plan->isDraft())
                <form method="POST" action="{{ route('kitchen.production-plans.submit', $plan->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Submit for Approval') }}</button>
                </form>
                <form method="POST" action="{{ route('kitchen.production-plans.refresh-occupancy', $plan->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Refresh Occupancy') }}</button>
                </form>
                <a href="{{ route('kitchen.production-plans.edit', $plan->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
            @endif
            @if ($plan->isApproved())
                <form method="POST" action="{{ route('kitchen.production-plans.create-orders', $plan->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Create Production Orders') }}</button>
                </form>
            @endif
            @if ($plan->isInProduction())
                <form method="POST" action="{{ route('kitchen.production-plans.complete', $plan->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Complete Plan') }}</button>
                </form>
            @endif
        @endcan
        @can('approve kitchen production plans')
            @if ($plan->isPendingApproval())
                <form method="POST" action="{{ route('kitchen.production-plans.approve', $plan->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#reject-plan-modal">{{ __('Reject') }}</button>
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
                        <div class="col-md-3"><strong>{{ __('Plan No') }}</strong><br>{{ $plan->plan_number }}</div>
                        <div class="col-md-3"><strong>{{ __('Date') }}</strong><br>{{ $plan->plan_date ? $plan->plan_date->format('Y-m-d') : '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Meal Service') }}</strong><br>{{ ucfirst($plan->meal_service) }}</div>
                        <div class="col-md-3"><strong>{{ __('Status') }}</strong><br>
                            @php
                                $badge = [
                                    'draft' => 'bg-secondary',
                                    'pending_approval' => 'bg-warning',
                                    'approved' => 'bg-info',
                                    'in_production' => 'bg-primary',
                                    'completed' => 'bg-success',
                                    'rejected' => 'bg-danger',
                                ][$plan->status] ?? 'bg-secondary';
                            @endphp
                            <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_', ' ', $plan->status)) }}</span>
                            @if ($plan->isPendingApproval() && $plan->approval_step)
                                <small class="text-muted d-block">{{ __('Waiting on') }}: {{ ucfirst(str_replace('_', ' ', $plan->approval_step)) }}</small>
                            @endif
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-3"><strong>{{ __('Kitchen Warehouse') }}</strong><br>{{ $plan->warehouse ? $plan->warehouse->name : '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Branch') }}</strong><br>{{ $plan->branch ? $plan->branch->name : __('All branches') }}</div>
                        <div class="col-md-3"><strong>{{ __('Section') }}</strong><br>{{ $plan->section ? ucfirst(str_replace('_', ' ', $plan->section)) : '-' }}</div>
                        <div class="col-md-3"><strong>{{ __('Notes') }}</strong><br>{{ $plan->notes ?: '-' }}</div>
                    </div>

                    @if ($plan->isRejected() && $plan->rejection_note)
                        <div class="alert alert-danger mt-3">{{ __('Rejected') }}: {{ $plan->rejection_note }}</div>
                    @endif

                    <h6 class="mt-4">{{ __('Approval') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                            <tr>
                                <th>{{ __('Step') }}</th>
                                <th>{{ __('Approved By') }}</th>
                                <th>{{ __('At') }}</th>
                                <th>{{ __('State') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @php
                                $steps = [
                                    ['key' => 'head_chef', 'label' => __('Head Chef'), 'by' => $plan->head_chef_approved_by, 'at' => $plan->head_chef_approved_at],
                                    ['key' => 'fb_manager', 'label' => __('F&B Manager'), 'by' => $plan->fb_manager_approved_by, 'at' => $plan->fb_manager_approved_at],
                                    ['key' => 'general_manager', 'label' => __('General Manager'), 'by' => $plan->gm_approved_by, 'at' => $plan->gm_approved_at],
                                ];
                            @endphp
                            @foreach ($steps as $step)
                                <tr>
                                    <td>{{ $step['label'] }}</td>
                                    <td>{{ $step['by'] ? (\App\Models\User::find($step['by'])->name ?? '#'.$step['by']) : '-' }}</td>
                                    <td>{{ $step['at'] ? $step['at']->format('Y-m-d H:i') : '-' }}</td>
                                    <td>
                                        @if ($step['by'])
                                            <span class="badge bg-success">{{ __('Approved') }}</span>
                                        @elseif ($step['key'] === 'fb_manager' && ! $fbManagerRequired)
                                            <span class="badge bg-secondary">{{ __('Skipped — no F&B Manager for this company') }}</span>
                                        @elseif ($plan->isPendingApproval() && $plan->approval_step === $step['key'])
                                            <span class="badge bg-warning">{{ __('Waiting') }}</span>
                                        @else
                                            <span class="badge bg-light text-dark">{{ __('Pending') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <h6 class="mt-4">{{ __('Occupancy') }}</h6>
                    @php $snapshot = $plan->occupancy_snapshot; @endphp
                    @if (is_array($snapshot))
                        <p>
                            <strong>{{ __('Guests in-house') }}:</strong> {{ $snapshot['total_guests'] ?? 0 }}
                            ({{ __('breakfast') }}: {{ $snapshot['breakfast_covers'] ?? 0 }},
                            {{ __('lunch') }}: {{ $snapshot['lunch_covers'] ?? 0 }},
                            {{ __('dinner') }}: {{ $snapshot['dinner_covers'] ?? 0 }})
                            — {{ $snapshot['total_reservations'] ?? 0 }} {{ __('reservation(s) on') }} {{ $snapshot['date'] ?? $plan->plan_date->format('Y-m-d') }}.
                        </p>
                        @if (! empty($snapshot['reservations']))
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                    <tr>
                                        <th>{{ __('Reservation') }}</th>
                                        <th>{{ __('Guests') }}</th>
                                        <th>{{ __('Infants') }}</th>
                                        <th>{{ __('Meal Plan') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Stay') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($snapshot['reservations'] as $reservation)
                                        <tr>
                                            <td>{{ $reservation['reservation_no'] ?? ('#'.$reservation['id']) }}</td>
                                            <td>{{ $reservation['guests'] }}</td>
                                            <td>{{ $reservation['infants'] }}</td>
                                            <td>{{ $reservation['meal_plan'] ? ucfirst(str_replace('_', ' ', $reservation['meal_plan'])) : __('Room only') }}</td>
                                            <td>{{ ucfirst(str_replace('_', ' ', $reservation['status'])) }}</td>
                                            <td>{{ $reservation['check_in_date'] }} → {{ $reservation['check_out_date'] }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">{{ __('No guests in-house on this date.') }}</p>
                        @endif
                    @else
                        <p class="text-muted">{{ __('No occupancy snapshot for this plan.') }}</p>
                    @endif

                    <h6 class="mt-4">{{ __('Planned Dishes') }}</h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Dish') }}</th>
                                <th>{{ __('Planned Quantity') }}</th>
                                <th>{{ __('Based On') }}</th>
                                <th>{{ __('Notes') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($plan->lines as $line)
                                <tr>
                                    <td>{{ $line->recipe && $line->recipe->product ? $line->recipe->product->name : ($line->recipe ? $line->recipe->code : '-') }} <small class="text-muted">(v{{ $line->recipe_version }})</small></td>
                                    <td>{{ number_format((float) $line->planned_quantity, 2) }} {{ $line->plannedUnit ? $line->plannedUnit->name : __('portions') }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $line->source)) }}</td>
                                    <td>{{ $line->notes ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center">{{ __('No dishes planned.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h6 class="mt-4">{{ __('Ingredient Requirements & Shortages') }}</h6>
                    @if ($requirementsError)
                        <div class="alert alert-warning">{{ $requirementsError }}</div>
                    @elseif (! empty($requirements['lines']))
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Ingredient') }}</th>
                                    <th>{{ __('Needed') }}</th>
                                    <th>{{ __('In Kitchen Warehouse') }}</th>
                                    <th>{{ __('Shortage') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($requirements['lines'] as $row)
                                    <tr>
                                        <td>{{ $row['ingredient_name'] }}</td>
                                        <td>{{ number_format((float) $row['needed'], 2) }} {{ $row['unit_name'] }}</td>
                                        <td>{{ number_format((float) $row['available'], 2) }} {{ $row['unit_name'] }}</td>
                                        <td>
                                            @if ((float) $row['shortage'] > 0)
                                                <span class="badge bg-danger">{{ number_format((float) $row['shortage'], 2) }} {{ $row['unit_name'] }}</span>
                                            @else
                                                <span class="badge bg-success">{{ __('Covered') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted">{{ __('Estimated ingredient cost') }}: {{ number_format((float) ($requirements['total_cost'] ?? 0), 2) }}</p>
                        @can('manage kitchen requisitions')
                            @if (collect($requirements['lines'])->sum('shortage') > 0)
                                <form method="POST" action="{{ route('kitchen.requisitions.from-plan', $plan->id) }}" class="row g-2 align-items-end">
                                    @csrf
                                    <div class="col-md-4">
                                        <label class="form-label">{{ __('Source Warehouse') }}</label>
                                        <select name="source_warehouse_id" class="form-control" required>
                                            <option value="">{{ __('Select source warehouse') }}</option>
                                            @foreach ($sourceWarehouses as $w)
                                                <option value="{{ $w->id }}">{{ $w->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-primary">{{ __('Create Requisition from Shortages') }}</button>
                                    </div>
                                </form>
                            @endif
                        @endcan
                    @else
                        <p class="text-muted">{{ __('No requirements computed yet.') }}</p>
                    @endif

                    @if ($plan->orders->count() > 0)
                        <h6 class="mt-4">{{ __('Production Orders from this Plan') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                <tr>
                                    <th>{{ __('Order No') }}</th>
                                    <th>{{ __('Recipe') }}</th>
                                    <th>{{ __('Planned Qty') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($plan->orders as $order)
                                    <tr>
                                        <td>{{ $order->order_number ?? __('Draft') }}</td>
                                        <td>{{ $order->recipe && $order->recipe->product ? $order->recipe->product->name : '-' }}</td>
                                        <td>{{ number_format((float) $order->planned_quantity, 2) }}</td>
                                        <td><span class="badge bg-secondary">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></td>
                                        <td><a href="{{ route('kitchen.production-orders.show', $order->id) }}" class="btn btn-sm btn-info"><i class="ti ti-eye"></i></a></td>
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

    <div class="modal fade" id="reject-plan-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('kitchen.production-plans.reject', $plan->id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Reject Plan') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">{{ __('Reason (shown to the chef)') }}</label>
                        <textarea name="rejection_note" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-danger">{{ __('Reject Plan') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
