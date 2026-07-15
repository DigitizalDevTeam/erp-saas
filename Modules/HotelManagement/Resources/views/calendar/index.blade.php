@extends('layouts.admin')
@section('page-title'){{ __('Booking calendar') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Calendar') }}</li>
@endsection

@push('css-page')
<style>
    #hotel-booking-calendar { min-height: 600px; }
    #hotel-booking-calendar .fc-event { cursor: pointer; }
    #hotel-booking-calendar .fc-toolbar-title { font-size: 1.05rem; }
    #hotel-room-grid-wrap { display: none; }
    #hotel-room-grid-wrap.is-active { display: block; }
    #hotel-booking-calendar.is-hidden { display: none; }
    .hotel-room-grid { overflow: auto; max-height: 70vh; border: 1px solid #e5e7eb; border-radius: 0.35rem; }
    .hotel-room-grid table { min-width: 900px; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
    .hotel-room-grid th, .hotel-room-grid td { white-space: nowrap; vertical-align: middle; }
    .hotel-room-grid .room-col { position: sticky; left: 0; background: #fff; z-index: 2; min-width: 140px; border-right: 1px solid #e5e7eb; }
    .hotel-room-grid thead th { position: sticky; top: 0; background: #f8fafc; z-index: 3; }
    .hotel-room-grid thead th.room-col { z-index: 4; }
    .hotel-room-grid .day-cell { min-width: 110px; height: 44px; position: relative; padding: 2px; }
    .hotel-booking-chip {
        display: block; border-radius: 4px; padding: 4px 6px; font-size: 11px; color: #fff;
        cursor: pointer; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;
    }
    .hotel-booking-chip.status-pending { background: #94A3B8; }
    .hotel-booking-chip.status-confirmed { background: #3B82F6; }
    .hotel-booking-chip.status-checked_in { background: #10B981; }
    .hotel-booking-chip.status-checked_out { background: #22C55E; }
    .room-status-pill { font-size: 10px; }
</style>
@endpush

@php
    $calendarUpdateUrlTpl = str_replace(
        '999999999',
        '__RID__',
        route('hotel.reservations.calendar-update', ['reservation' => 999999999])
    );
    $extendUrlTpl = str_replace('999999999', '__RID__', route('hotel.calendar.extend-stay', ['reservation' => 999999999]));
    $changeRoomUrlTpl = str_replace('999999999', '__RID__', route('hotel.calendar.change-room', ['reservation' => 999999999]));
    $roomStatusUrlTpl = str_replace('999999999', '__RID__', route('hotel.calendar.room-status', ['room' => 999999999]));
@endphp

@section('content')
<div class="card shadow-sm"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <p class="text-muted small mb-0">{{ __('Use Rooms view to see every room (including vacant ones). Click a booking for stay extend, change room, or room status.') }}</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary active" id="cal-view-month">{{ __('Month') }}</button>
                <button type="button" class="btn btn-outline-primary" id="cal-view-rooms">{{ __('Rooms') }}</button>
            </div>
            <div class="d-flex flex-wrap gap-3 small">
                <span><span class="badge" style="background:#94A3B8">&nbsp;</span> {{ __('Pending') }}</span>
                <span><span class="badge" style="background:#3B82F6">&nbsp;</span> {{ __('Confirmed') }}</span>
                <span><span class="badge" style="background:#10B981">&nbsp;</span> {{ __('Checked-in') }}</span>
                <span><span class="badge" style="background:#22C55E">&nbsp;</span> {{ __('Checked-out') }}</span>
            </div>
        </div>
    </div>

    <div id="hotel-booking-calendar"></div>

    <div id="hotel-room-grid-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" id="room-grid-prev"><i class="ti ti-chevron-left"></i></button>
                <button type="button" class="btn btn-outline-secondary" id="room-grid-today">{{ __('Today') }}</button>
                <button type="button" class="btn btn-outline-secondary" id="room-grid-next"><i class="ti ti-chevron-right"></i></button>
            </div>
            <strong id="room-grid-range-label" class="small"></strong>
        </div>
        <div class="hotel-room-grid">
            <table class="table table-bordered table-sm mb-0" id="hotel-room-grid-table">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div></div>

{{-- Booking actions modal --}}
<div class="modal fade" id="hotelCalActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="hotelCalActionTitle">{{ __('Reservation') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3" id="hotelCalActionMeta"></p>
                <div id="hotelCalActionAlert" class="alert alert-danger d-none py-2"></div>

                @if($canManage ?? false)
                <div class="mb-3" id="hotelCalExtendBlock">
                    <label class="form-label">{{ __('Extend stay (new check-out)') }}</label>
                    <div class="input-group input-group-sm">
                        <input type="date" class="form-control" id="hotelCalExtendDate">
                        <button type="button" class="btn btn-success" id="hotelCalExtendBtn">{{ __('Extend') }}</button>
                    </div>
                </div>
                <div class="mb-3" id="hotelCalChangeRoomBlock">
                    <label class="form-label">{{ __('Change room') }}</label>
                    <div class="input-group input-group-sm">
                        <select class="form-select" id="hotelCalChangeRoomSelect">
                            @foreach($availableRooms as $id => $roomNo)
                                <option value="{{ $id }}">{{ $roomNo }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-primary" id="hotelCalChangeRoomBtn">{{ __('Change') }}</button>
                    </div>
                </div>
                @endif

                <div class="mb-0">
                    <label class="form-label">{{ __('Change room status') }}</label>
                    <div class="input-group input-group-sm">
                        <select class="form-select" id="hotelCalRoomStatusSelect" @disabled(!($canManage ?? false))>
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if($canManage ?? false)
                        <button type="button" class="btn btn-warning" id="hotelCalRoomStatusBtn">{{ __('Update status') }}</button>
                        @endif
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-outline-secondary btn-sm" id="hotelCalEditLink">{{ __('Open reservation') }}</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script-page')
<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var updateUrlTpl = @json($calendarUpdateUrlTpl);
    var eventsUrl = @json(route('hotel.calendar.events'));
    var roomBoardUrl = @json(route('hotel.calendar.room-board'));
    var extendUrlTpl = @json($extendUrlTpl);
    var changeRoomUrlTpl = @json($changeRoomUrlTpl);
    var roomStatusUrlTpl = @json($roomStatusUrlTpl);
    var reservationEditBase = @json(url('hotel/reservations'));
    var canManage = @json((bool) ($canManage ?? false));

    var calendar = null;
    var gridStart = null;
    var currentBooking = null;

    function formatDate(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function startOfWeek(d) {
        var date = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var day = date.getDay();
        var diff = day === 0 ? -6 : 1 - day;
        date.setDate(date.getDate() + diff);
        return date;
    }

    function addDays(d, n) {
        var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        x.setDate(x.getDate() + n);
        return x;
    }

    function showAlert(msg, isError) {
        var el = document.getElementById('hotelCalActionAlert');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('d-none', !msg);
        el.classList.toggle('alert-danger', !!isError);
        el.classList.toggle('alert-success', !isError && !!msg);
    }

    function openBookingModal(payload) {
        currentBooking = payload || null;
        if (!currentBooking) return;

        document.getElementById('hotelCalActionTitle').textContent = currentBooking.guest_name || '{{ __("Reservation") }}';
        document.getElementById('hotelCalActionMeta').textContent =
            (currentBooking.room_no ? ('# ' + currentBooking.room_no + ' · ') : '') +
            (currentBooking.status || '') +
            (currentBooking.check_out_date ? (' · {{ __("Check-out") }} ' + currentBooking.check_out_date) : '');

        document.getElementById('hotelCalEditLink').href = reservationEditBase + '/' + currentBooking.reservation_id + '/edit';
        showAlert('', false);

        var extendBlock = document.getElementById('hotelCalExtendBlock');
        var changeBlock = document.getElementById('hotelCalChangeRoomBlock');
        var isCheckedIn = currentBooking.status === 'checked_in';
        if (extendBlock) extendBlock.style.display = isCheckedIn && canManage ? '' : 'none';
        if (changeBlock) changeBlock.style.display = isCheckedIn && canManage ? '' : 'none';

        var extendInput = document.getElementById('hotelCalExtendDate');
        if (extendInput && currentBooking.check_out_date) {
            extendInput.value = currentBooking.check_out_date;
            extendInput.min = currentBooking.check_out_date;
        }
        if (currentBooking.hotel_room_id) {
            var roomSelect = document.getElementById('hotelCalChangeRoomSelect');
            if (roomSelect) roomSelect.value = String(currentBooking.hotel_room_id);
            var statusSelect = document.getElementById('hotelCalRoomStatusSelect');
            if (statusSelect && currentBooking.room_status) statusSelect.value = currentBooking.room_status;
        }

        var modal = document.getElementById('hotelCalActionModal');
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        });
    }

    function bootHotelCalendar() {
        var el = document.getElementById('hotel-booking-calendar');
        if (!el) return;
        if (typeof FullCalendar === 'undefined') {
            el.innerHTML = '<div class="alert alert-warning mb-0">{{ __("FullCalendar library is not loaded. Try a hard reload.") }}</div>';
            return;
        }

        calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            buttonText: {
                today: '{{ __("Today") }}',
                month: '{{ __("Month") }}',
                week: '{{ __("Week") }}',
                list: '{{ __("List") }}'
            },
            navLinks: true,
            editable: true,
            eventDurationEditable: true,
            handleWindowResize: true,
            dayMaxEvents: true,
            events: eventsUrl,
            eventDrop: function (info) { persist(info); },
            eventResize: function (info) { persist(info); },
            eventClick: function (info) {
                var p = info.event.extendedProps || {};
                openBookingModal({
                    reservation_id: p.reservation_id || info.event.id,
                    guest_name: p.guest_name || info.event.title,
                    status: p.status,
                    hotel_room_id: p.hotel_room_id,
                    room_no: p.room_no,
                    check_out_date: p.check_out_date,
                    room_status: null
                });
            }
        });
        calendar.render();

        function persist(info) {
            var ev = info.event;
            var id = String(ev.extendedProps.reservation_id || ev.id);
            var start = ev.start;
            var end = ev.end || start;
            fetch(updateUrlTpl.replace('__RID__', id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    check_in_date: formatDate(start),
                    check_out_date: formatDate(end)
                })
            }).then(function (res) {
                if (!res.ok) {
                    info.revert();
                }
            }).catch(function () {
                info.revert();
            });
        }
    }

    function loadRoomGrid() {
        if (!gridStart) {
            gridStart = startOfWeek(new Date());
        }
        var start = gridStart;
        var end = addDays(start, 6);
        var url = roomBoardUrl + '?start=' + encodeURIComponent(formatDate(start)) + '&end=' + encodeURIComponent(formatDate(end));

        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderRoomGrid(data);
            });
    }

    function renderRoomGrid(data) {
        var days = data.days || [];
        var rooms = data.rooms || [];
        var reservations = data.reservations || [];
        var thead = document.querySelector('#hotel-room-grid-table thead');
        var tbody = document.querySelector('#hotel-room-grid-table tbody');
        var label = document.getElementById('room-grid-range-label');
        if (!thead || !tbody) return;

        if (label) {
            label.textContent = (data.start || '') + ' — ' + (data.end || '') + ' · ' + rooms.length + ' {{ __("rooms") }}';
        }

        var headHtml = '<tr><th class="room-col">{{ __("Room") }}</th>';
        days.forEach(function (day) {
            var d = new Date(day + 'T00:00:00');
            headHtml += '<th class="text-center">' + d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + '</th>';
        });
        headHtml += '</tr>';
        thead.innerHTML = headHtml;

        var byRoom = {};
        reservations.forEach(function (res) {
            var rid = res.hotel_room_id;
            if (!rid) return;
            if (!byRoom[rid]) byRoom[rid] = [];
            byRoom[rid].push(res);
        });

        var bodyHtml = '';
        if (!rooms.length) {
            bodyHtml = '<tr><td colspan="' + (days.length + 1) + '" class="text-center text-muted py-4">{{ __("No rooms found.") }}</td></tr>';
        } else {
            rooms.forEach(function (room) {
                bodyHtml += '<tr>';
                bodyHtml += '<td class="room-col"><div class="fw-semibold">' + escapeHtml(room.room_no) + '</div>';
                bodyHtml += '<button type="button" class="badge room-status-pill bg-secondary border-0 hotel-room-status-btn" data-room-id="' + room.id + '" data-status="' + escapeHtml(room.status) + '">' + escapeHtml(room.status) + '</button>';
                if (room.floor) bodyHtml += '<div class="text-muted" style="font-size:10px">' + escapeHtml(room.floor) + '</div>';
                bodyHtml += '</td>';

                days.forEach(function (day) {
                    var chip = '';
                    (byRoom[room.id] || []).forEach(function (res) {
                        if (day >= res.check_in_date && day < res.check_out_date) {
                            chip = '<span class="hotel-booking-chip status-' + escapeHtml(res.status) + '" data-booking=\'' + escapeHtml(JSON.stringify({
                                reservation_id: res.id,
                                guest_name: res.guest_name,
                                status: res.status,
                                hotel_room_id: room.id,
                                room_no: room.room_no,
                                room_status: room.status,
                                check_out_date: res.check_out_date
                            })) + '\'>' + escapeHtml(res.guest_name) + '</span>';
                        } else if (day === res.check_in_date && res.check_in_date === res.check_out_date) {
                            chip = '<span class="hotel-booking-chip status-' + escapeHtml(res.status) + '" data-booking=\'' + escapeHtml(JSON.stringify({
                                reservation_id: res.id,
                                guest_name: res.guest_name,
                                status: res.status,
                                hotel_room_id: room.id,
                                room_no: room.room_no,
                                room_status: room.status,
                                check_out_date: res.check_out_date
                            })) + '\'>' + escapeHtml(res.guest_name) + '</span>';
                        }
                    });
                    bodyHtml += '<td class="day-cell">' + chip + '</td>';
                });
                bodyHtml += '</tr>';
            });
        }
        tbody.innerHTML = bodyHtml;
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setView(mode) {
        var monthBtn = document.getElementById('cal-view-month');
        var roomsBtn = document.getElementById('cal-view-rooms');
        var calEl = document.getElementById('hotel-booking-calendar');
        var gridEl = document.getElementById('hotel-room-grid-wrap');
        if (mode === 'rooms') {
            monthBtn.classList.remove('active');
            roomsBtn.classList.add('active');
            calEl.classList.add('is-hidden');
            gridEl.classList.add('is-active');
            loadRoomGrid();
        } else {
            roomsBtn.classList.remove('active');
            monthBtn.classList.add('active');
            calEl.classList.remove('is-hidden');
            gridEl.classList.remove('is-active');
            if (calendar) calendar.updateSize();
        }
    }

    document.getElementById('cal-view-month').addEventListener('click', function () { setView('month'); });
    document.getElementById('cal-view-rooms').addEventListener('click', function () { setView('rooms'); });
    document.getElementById('room-grid-prev').addEventListener('click', function () {
        gridStart = addDays(gridStart || startOfWeek(new Date()), -7);
        loadRoomGrid();
    });
    document.getElementById('room-grid-next').addEventListener('click', function () {
        gridStart = addDays(gridStart || startOfWeek(new Date()), 7);
        loadRoomGrid();
    });
    document.getElementById('room-grid-today').addEventListener('click', function () {
        gridStart = startOfWeek(new Date());
        loadRoomGrid();
    });

    document.addEventListener('click', function (e) {
        var chip = e.target.closest('.hotel-booking-chip');
        if (chip && chip.getAttribute('data-booking')) {
            try {
                openBookingModal(JSON.parse(chip.getAttribute('data-booking')));
            } catch (err) {}
            return;
        }
        var statusBtn = e.target.closest('.hotel-room-status-btn');
        if (statusBtn) {
            openBookingModal({
                reservation_id: null,
                guest_name: '{{ __("Room") }} ' + (statusBtn.closest('tr').querySelector('.fw-semibold') || {}).textContent,
                status: null,
                hotel_room_id: statusBtn.getAttribute('data-room-id'),
                room_no: (statusBtn.closest('tr').querySelector('.fw-semibold') || {}).textContent,
                room_status: statusBtn.getAttribute('data-status'),
                check_out_date: null
            });
            var extendBlock = document.getElementById('hotelCalExtendBlock');
            var changeBlock = document.getElementById('hotelCalChangeRoomBlock');
            if (extendBlock) extendBlock.style.display = 'none';
            if (changeBlock) changeBlock.style.display = 'none';
            document.getElementById('hotelCalEditLink').style.display = 'none';
        }
    });

    var extendBtn = document.getElementById('hotelCalExtendBtn');
    if (extendBtn) {
        extendBtn.addEventListener('click', function () {
            if (!currentBooking || !currentBooking.reservation_id) return;
            var date = document.getElementById('hotelCalExtendDate').value;
            postJson(extendUrlTpl.replace('__RID__', currentBooking.reservation_id), { check_out_at: date })
                .then(function (res) {
                    if (!res.ok) {
                        showAlert((res.data && res.data.error) || '{{ __("Failed") }}', true);
                        return;
                    }
                    showAlert(res.data.message || '{{ __("Saved") }}', false);
                    if (calendar) calendar.refetchEvents();
                    if (document.getElementById('hotel-room-grid-wrap').classList.contains('is-active')) loadRoomGrid();
                });
        });
    }

    var changeRoomBtn = document.getElementById('hotelCalChangeRoomBtn');
    if (changeRoomBtn) {
        changeRoomBtn.addEventListener('click', function () {
            if (!currentBooking || !currentBooking.reservation_id) return;
            var roomId = document.getElementById('hotelCalChangeRoomSelect').value;
            postJson(changeRoomUrlTpl.replace('__RID__', currentBooking.reservation_id), { hotel_room_id: roomId })
                .then(function (res) {
                    if (!res.ok) {
                        showAlert((res.data && res.data.error) || '{{ __("Failed") }}', true);
                        return;
                    }
                    showAlert(res.data.message || '{{ __("Saved") }}', false);
                    if (calendar) calendar.refetchEvents();
                    if (document.getElementById('hotel-room-grid-wrap').classList.contains('is-active')) loadRoomGrid();
                });
        });
    }

    var statusBtn = document.getElementById('hotelCalRoomStatusBtn');
    if (statusBtn) {
        statusBtn.addEventListener('click', function () {
            if (!currentBooking || !currentBooking.hotel_room_id) {
                showAlert('{{ __("Select a room.") }}', true);
                return;
            }
            var status = document.getElementById('hotelCalRoomStatusSelect').value;
            postJson(roomStatusUrlTpl.replace('__RID__', currentBooking.hotel_room_id), { status: status })
                .then(function (res) {
                    if (!res.ok) {
                        showAlert((res.data && res.data.error) || '{{ __("Failed") }}', true);
                        return;
                    }
                    showAlert(res.data.message || '{{ __("Saved") }}', false);
                    if (document.getElementById('hotel-room-grid-wrap').classList.contains('is-active')) loadRoomGrid();
                });
        });
    }

    // Reset edit link visibility when opening booking again
    var actionModal = document.getElementById('hotelCalActionModal');
    if (actionModal) {
        actionModal.addEventListener('show.bs.modal', function () {
            var link = document.getElementById('hotelCalEditLink');
            if (link) link.style.display = currentBooking && currentBooking.reservation_id ? '' : 'none';
        });
    }

    bootHotelCalendar();
})();
</script>
@endpush
