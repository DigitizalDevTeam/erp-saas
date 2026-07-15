@extends('layouts.admin')
@section('page-title'){{ __('Housekeeping') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Housekeeping') }}</li>
@endsection
@section('action-btn')
<a href="{{ route('hotel.housekeeping.minibar') }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-fridge"></i> {{ __('Daily minibar') }}</a>
@if($canManage ?? false)
<a href="{{ route('hotel.housekeeping.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Task') }}</a>
@endif
@endsection
@section('content')
@php
    $roomStatusBadges = [
        'dirty' => 'bg-warning text-dark',
        'cleaning' => 'bg-info',
        'available' => 'bg-success',
        'occupied' => 'bg-danger',
        'maintenance' => 'bg-secondary',
        'blocked' => 'bg-dark',
    ];
    $taskStatusBadges = [
        'pending' => 'bg-warning text-dark',
        'in_progress' => 'bg-primary',
        'done' => 'bg-success',
    ];
    $sourceLabels = [
        'night_audit' => __('Night audit'),
        'checkout' => __('Checkout'),
        'manual' => __('Manual'),
    ];
@endphp

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">{{ __('Pending') }}</div>
                <div class="h4 mb-0">{{ $stats['pending'] ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">{{ __('In progress') }}</div>
                <div class="h4 mb-0">{{ $stats['in_progress'] ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">{{ __('Dirty rooms') }}</div>
                <div class="h4 mb-0">{{ $stats['dirty_rooms'] ?? 0 }}</div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-muted small mb-0">{{ __('When Show all is off, only occupied / in-house rooms appear. Turn it on to see every hotel room.') }}</p>
    <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="hk-show-all" @checked($showAll ?? false) onchange="window.location='{{ route('hotel.housekeeping.index') }}' + (this.checked ? '?show_all=1' : '')">
        <label class="form-check-label small" for="hk-show-all">{{ __('Show all') }}</label>
    </div>
</div>

<div class="card">
    <div class="card-body table-border-style">
        <div class="table-responsive">
            <table class="table datatable">
                <thead>
                    <tr>
                        <th>{{ __('Room') }}</th>
                        <th>{{ __('Floor') }}</th>
                        <th>{{ __('Room status') }}</th>
                        <th>{{ __('Task') }}</th>
                        <th>{{ __('Source') }}</th>
                        <th>{{ __('Assigned to') }}</th>
                        <th>{{ __('Notes') }}</th>
                        <th width="220">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    @php
                        $room = $row->room;
                        $task = $row->task;
                        $roomStatus = $room->status ?? '';
                        $roomBadge = $roomStatusBadges[$roomStatus] ?? 'bg-light text-dark';
                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $room->room_no ?? '-' }}</td>
                        <td>{{ $room->floor->name ?? '—' }}</td>
                        <td><span class="badge {{ $roomBadge }}">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $roomStatus))) }}</span></td>
                        <td>
                            @if($task)
                                <span class="badge {{ $taskStatusBadges[$task->status] ?? 'bg-light text-dark' }}">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $task->status))) }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($task)
                                {{ $sourceLabels[$task->source] ?? ($task->source ? ucfirst($task->source) : '—') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $task->assignee->name ?? ($task ? __('Unassigned') : '—') }}</td>
                        <td class="small text-muted">{{ $task ? \Illuminate\Support\Str::limit($task->notes, 80) : '—' }}</td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                @if($canManage ?? false)
                                    @if($task && $task->status === 'pending')
                                        {!! Form::open(['route' => ['hotel.housekeeping.action', $task->id], 'method' => 'POST', 'class' => 'd-inline']) !!}
                                            <input type="hidden" name="action" value="start">
                                            <button type="submit" class="btn btn-sm btn-success" title="{{ __('Start cleaning') }}"><i class="ti ti-player-play"></i></button>
                                        {!! Form::close() !!}
                                        <button type="button" class="btn btn-sm btn-warning hk-maint-btn" data-task-id="{{ $task->id }}" title="{{ __('Send to maintenance') }}"><i class="ti ti-tool"></i></button>
                                        <a class="btn btn-sm btn-info" href="{{ route('hotel.housekeeping.edit', $task->id) }}" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                    @elseif($task && $task->status === 'in_progress')
                                        {!! Form::open(['route' => ['hotel.housekeeping.action', $task->id], 'method' => 'POST', 'class' => 'd-inline']) !!}
                                            <input type="hidden" name="action" value="complete">
                                            <button type="submit" class="btn btn-sm btn-success" title="{{ __('Mark clean & ready') }}"><i class="ti ti-check"></i></button>
                                        {!! Form::close() !!}
                                        <button type="button" class="btn btn-sm btn-warning hk-maint-btn" data-task-id="{{ $task->id }}" title="{{ __('Send to maintenance') }}"><i class="ti ti-tool"></i></button>
                                        <a class="btn btn-sm btn-info" href="{{ route('hotel.housekeeping.edit', $task->id) }}" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                    @elseif($task && $task->status === 'done')
                                        <span class="btn btn-sm btn-outline-secondary disabled" title="{{ __('Completed') }}"><i class="ti ti-eye"></i></span>
                                        @if($task->completed_at)
                                            <span class="small text-muted align-self-center">{{ $task->completed_at->format('Y-m-d H:i') }}</span>
                                        @endif
                                    @else
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('hotel.housekeeping.create', ['hotel_room_id' => $room->id]) }}" title="{{ __('Queue cleaning') }}"><i class="ti ti-plus"></i></a>
                                    @endif
                                @else
                                    <span class="text-muted small">{{ __('View only') }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            @if($showAll ?? false)
                                {{ __('No rooms found.') }}
                            @else
                                {{ __('No occupied / in-house rooms. Turn on Show all to see every hotel room.') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="hkMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            {!! Form::open(['route' => ['hotel.housekeeping.action', 0], 'method' => 'POST', 'id' => 'hk-maintenance-form']) !!}
            <input type="hidden" name="action" value="maintenance">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Send room to maintenance') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">{{ __('Issue / notes') }}</label>
                <textarea class="form-control" name="notes" rows="3" placeholder="{{ __('Describe the maintenance issue...') }}"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-warning">{{ __('Block for maintenance') }}</button>
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>
@endsection

@push('script-page')
<script>
(function () {
    var actionBase = @json(url('hotel/housekeeping'));

    function showMaintenanceModal(taskId) {
        var form = document.getElementById('hk-maintenance-form');
        var modal = document.getElementById('hkMaintenanceModal');
        if (!form || !modal || !taskId) {
            return;
        }

        form.action = actionBase + '/' + taskId + '/action';

        var notes = form.querySelector('textarea[name="notes"]');
        if (notes) {
            notes.value = '';
        }

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        } else if (typeof $ !== 'undefined') {
            $(modal).modal('show');
        }
    }

    // Delegated handler: simpleDatatables rebuilds the table and drops direct listeners.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.hk-maint-btn');
        if (!btn) {
            return;
        }

        event.preventDefault();
        showMaintenanceModal(btn.getAttribute('data-task-id'));
    });
})();
</script>
@endpush
