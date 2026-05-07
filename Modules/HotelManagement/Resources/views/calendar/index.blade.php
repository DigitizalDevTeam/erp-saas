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
</style>
@endpush

@php
    $calendarUpdateUrlTpl = str_replace(
        '999999999',
        '__RID__',
        route('hotel.reservations.calendar-update', ['reservation' => 999999999])
    );
@endphp

@section('content')
<div class="card shadow-sm"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <p class="text-muted small mb-0">{{ __('Drag or resize events to change dates. Checked-in/out stays cannot be moved.') }}</p>
        </div>
        <div class="d-flex flex-wrap gap-3 small">
            <span><span class="badge" style="background:#94A3B8">&nbsp;</span> {{ __('Pending') }}</span>
            <span><span class="badge" style="background:#3B82F6">&nbsp;</span> {{ __('Confirmed') }}</span>
            <span><span class="badge" style="background:#10B981">&nbsp;</span> {{ __('Checked-in') }}</span>
            <span><span class="badge" style="background:#22C55E">&nbsp;</span> {{ __('Checked-out') }}</span>
        </div>
    </div>
    <div id="hotel-booking-calendar"></div>
</div></div>
@endsection

@push('script-page')
<script>
    (function () {
        function bootHotelCalendar() {
            var el = document.getElementById('hotel-booking-calendar');
            if (!el) return;
            if (typeof FullCalendar === 'undefined') {
                el.innerHTML = '<div class="alert alert-warning mb-0">{{ __("FullCalendar library is not loaded. Try a hard reload.") }}</div>';
                return;
            }

            var tokenMeta = document.querySelector('meta[name="csrf-token"]');
            var csrf = tokenMeta ? tokenMeta.getAttribute('content') : '';
            var updateUrlTpl = @json($calendarUpdateUrlTpl);
            var eventsUrl = @json(route('hotel.calendar.events'));

            var calendar = new FullCalendar.Calendar(el, {
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
                    var rid = info.event.extendedProps.reservation_id || info.event.id;
                    if (rid) {
                        window.location.href = '{{ url('hotel/reservations') }}/' + rid + '/edit';
                    }
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
                        check_in_at: start.toISOString(),
                        check_out_at: end.toISOString()
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) {
                        alert(data.error);
                        info.revert();
                    }
                }).catch(function () {
                    info.revert();
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootHotelCalendar);
        } else {
            bootHotelCalendar();
        }
    })();
</script>
@endpush
