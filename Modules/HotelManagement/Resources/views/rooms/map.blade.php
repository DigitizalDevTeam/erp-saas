@extends('layouts.admin')
@section('page-title')
    {{ __('Room map') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.rooms.index') }}">{{ __('Rooms') }}</a></li>
    <li class="breadcrumb-item">{{ __('Room map') }}</li>
@endsection
@section('content')
    <div class="card shadow-sm border-0">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-0">{{ __('Room map') }}</h5>
                <small class="text-muted">{{ __('Click an occupied room to post minibar or extras; available rooms open a new booking.') }}</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('hotel.rooms.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-list me-1"></i>{{ __('Show list') }}
                </a>
                @if($canManage ?? false)
                <a href="{{ route('hotel.reservations.create') }}" class="btn btn-sm btn-primary">
                    <i class="ti ti-plus me-1"></i>{{ __('New reservation') }}
                </a>
                @endif
            </div>
        </div>
        <div class="card-body">
            @if($canManage ?? false)
            <div class="border rounded p-3 mb-3 bg-light" id="room-bulk-toolbar">
                <div class="d-flex flex-wrap align-items-end gap-2">
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-bulk-toggle">
                            <i class="ti ti-checkbox me-1"></i>{{ __('Bulk update') }}
                        </button>
                    </div>
                    <div class="bulk-actions d-none flex-wrap align-items-end gap-2">
                        <div>
                            <label class="form-label small mb-1">{{ __('Set status') }}</label>
                            <select class="form-select form-select-sm" id="bulk-status-select">
                                @foreach($statusLabels ?? [] as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" form="room-bulk-form" class="btn btn-sm btn-primary">
                            {{ __('Apply to selected') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bulk-select-all">{{ __('Select all') }}</button>
                        <button type="button" class="btn btn-sm btn-link" id="btn-bulk-cancel">{{ __('Cancel') }}</button>
                    </div>
                </div>
                <form method="post" action="{{ route('hotel.rooms.bulk-status') }}" id="room-bulk-form" class="d-none mt-2">
                    @csrf
                    <input type="hidden" name="status" id="bulk-status-hidden" value="available">
                    <div id="bulk-room-ids"></div>
                </form>
            </div>
            @endif

            @include('hotelmanagement::partials.floor_grid', [
                'rooms' => $rooms,
                'interactive' => $canManage ?? false,
                'compact' => false,
                'bulkSelect' => $canManage ?? false,
                'canManage' => $canManage ?? false,
            ])
        </div>
    </div>
@endsection

@if($canManage ?? false)
@push('script-page')
<script>
(function () {
    var bulkMode = false;
    var toggleBtn = document.getElementById('btn-bulk-toggle');
    var bulkActions = document.querySelector('.bulk-actions');
    var bulkForm = document.getElementById('room-bulk-form');
    var bulkIds = document.getElementById('bulk-room-ids');
    var statusSelect = document.getElementById('bulk-status-select');
    var statusHidden = document.getElementById('bulk-status-hidden');
    var grid = document.querySelector('.hotel-floor-grid');

    function setBulkMode(on) {
        bulkMode = on;
        if (bulkActions) bulkActions.classList.toggle('d-none', !on);
        if (bulkForm) bulkForm.classList.toggle('d-none', !on);
        if (grid) grid.classList.toggle('bulk-select-mode', on);
        document.querySelectorAll('.room-bulk-checkbox').forEach(function (cb) {
            cb.classList.toggle('d-none', !on);
        });
        if (!on) {
            document.querySelectorAll('.room-bulk-input').forEach(function (cb) { cb.checked = false; });
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () { setBulkMode(!bulkMode); });
    }
    var cancelBtn = document.getElementById('btn-bulk-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', function () { setBulkMode(false); });

    var selectAllBtn = document.getElementById('btn-bulk-select-all');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            document.querySelectorAll('.room-bulk-input').forEach(function (cb) { cb.checked = true; });
        });
    }

    if (bulkForm) {
        bulkForm.addEventListener('submit', function (e) {
            if (!bulkIds || !statusHidden || !statusSelect) return;
            bulkIds.innerHTML = '';
            var checked = document.querySelectorAll('.room-bulk-input:checked');
            if (!checked.length) {
                e.preventDefault();
                alert('{{ __("Select at least one room.") }}');
                return;
            }
            statusHidden.value = statusSelect.value;
            checked.forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'room_ids[]';
                input.value = cb.value;
                bulkIds.appendChild(input);
            });
        });
    }

    if (grid) {
        grid.addEventListener('click', function (e) {
            if (!bulkMode) return;
            var roomEl = e.target.closest('.floor-room');
            if (!roomEl || e.target.closest('a') && !e.target.closest('.room-bulk-checkbox')) {
                if (roomEl && !e.target.closest('.room-bulk-checkbox')) {
                    e.preventDefault();
                    var cb = roomEl.querySelector('.room-bulk-input');
                    if (cb) cb.checked = !cb.checked;
                }
            }
        });
    }
})();
</script>
@endpush
@endif
