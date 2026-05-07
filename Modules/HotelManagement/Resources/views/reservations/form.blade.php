@php
    $guest = $reservation?->guest;
    $selectedRoomType = $reservation?->hotel_room_type_id ?? null;
    $selectedRoom = $reservation?->hotel_room_id ?? null;
    $checkInAtValue = old('check_in_at', isset($reservation) && !empty($reservation?->check_in_at) ? \Carbon\Carbon::parse($reservation->check_in_at)->format('Y-m-d\TH:i') : null);
    $checkOutAtValue = old('check_out_at', isset($reservation) && !empty($reservation?->check_out_at) ? \Carbon\Carbon::parse($reservation->check_out_at)->format('Y-m-d\TH:i') : null);
    $roomTypeBasePrices = $roomTypeBasePrices ?? collect();
    $roomTypesArray = is_object($roomTypes ?? null) && method_exists($roomTypes, 'toArray') ? $roomTypes->toArray() : (array) ($roomTypes ?? []);
    $roomsArray = is_object($rooms ?? null) && method_exists($rooms, 'toArray') ? $rooms->toArray() : (array) ($rooms ?? []);
@endphp
<div class="row g-3">
    <div class="col-lg-9">
        <input type="hidden" name="hotel_guest_id" id="hotel_guest_id" value="{{ old('hotel_guest_id', $guest?->id) }}">

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-2"><i class="ti ti-user-search me-1"></i>{{ __('Find existing guest') }}</h6>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="guest_search_q" placeholder="{{ __('Name, phone, email or document…') }}" autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary" id="guest_search_btn">{{ __('Search') }}</button>
                </div>
                <div id="guest_search_results" class="list-group mt-1" style="max-height:200px;overflow:auto;display:none;"></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-3 text-uppercase text-muted small fw-bold">{{ __('Guest Details') }}</h6>
                <div class="row g-3">
                    <div class="col-md-4">{!! Form::label('first_name', __('First Name'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('first_name', $guest?->first_name, ['class' => 'form-control', 'required', 'id' => 'first_name']) !!}</div>
                    <div class="col-md-4">{!! Form::label('last_name', __('Last Name'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('last_name', $guest?->last_name, ['class' => 'form-control', 'id' => 'last_name']) !!}</div>
                    <div class="col-md-4">{!! Form::label('phone', __('Phone'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('phone', $guest?->phone, ['class' => 'form-control', 'id' => 'phone']) !!}</div>

                    <div class="col-md-4">{!! Form::label('email', __('Email'), ['class' => 'form-label small mb-1']) !!}{!! Form::email('email', $guest?->email, ['class' => 'form-control', 'id' => 'email']) !!}</div>
                    <div class="col-md-4">{!! Form::label('birth_date', __('Birth date'), ['class' => 'form-label small mb-1']) !!}{!! Form::date('birth_date', $guest?->birth_date, ['class' => 'form-control', 'id' => 'birth_date']) !!}</div>
                    <div class="col-md-4">{!! Form::label('nationality', __('Nationality'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('nationality', $guest?->nationality, ['class' => 'form-control', 'id' => 'nationality']) !!}</div>

                    <div class="col-md-4">{!! Form::label('id_type', __('Document type'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('id_type', ['' => '—', 'id' => 'ID', 'passport' => __('Passport'), 'letter' => __('Letter')], $guest?->id_type, ['class' => 'form-control', 'id' => 'id_type']) !!}</div>
                    <div class="col-md-4">{!! Form::label('id_number', __('Document number'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('id_number', $guest?->id_number, ['class' => 'form-control', 'id' => 'id_number']) !!}</div>
                    <div class="col-md-4">
                        {!! Form::label('company_name', __('Company'), ['class' => 'form-label small mb-1']) !!}
                        <div class="input-group">
                            {!! Form::text('company_name', $guest?->company_name, ['class' => 'form-control', 'id' => 'company_name']) !!}
                            <span class="input-group-text bg-white px-2">
                                <div class="form-check m-0">
                                    {!! Form::checkbox('is_vip', 1, $guest?->is_vip, ['class' => 'form-check-input me-1', 'id' => 'is_vip']) !!}
                                    <label for="is_vip" class="form-check-label small mb-0">VIP</label>
                                </div>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <a class="text-decoration-none d-block mb-2" data-bs-toggle="collapse" href="#guest_prefs_section" role="button" aria-expanded="false">
                    <h6 class="mb-0 text-uppercase text-muted small fw-bold"><i class="ti ti-chevron-down me-1"></i>{{ __('Guest Preferences (optional)') }}</h6>
                </a>
                <div class="collapse {{ $guest && ($guest->preferred_room_type_id || $guest->preferred_floor || $guest->preferred_payment_method || $guest->credit_limit || $guest->notes) ? 'show' : '' }}" id="guest_prefs_section">
                    <div class="row g-3">
                        <div class="col-md-4">{!! Form::label('preferred_room_type_id', __('Preferred room type'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('preferred_room_type_id', ['' => '—'] + $roomTypesArray, $guest?->preferred_room_type_id, ['class' => 'form-control', 'id' => 'preferred_room_type_id']) !!}</div>
                        <div class="col-md-4">{!! Form::label('preferred_floor', __('Preferred floor'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('preferred_floor', $guest?->preferred_floor, ['class' => 'form-control', 'id' => 'preferred_floor']) !!}</div>
                        <div class="col-md-4">{!! Form::label('preferred_payment_method', __('Preferred payment'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('preferred_payment_method', ['' => '—', 'cash' => __('Cash'), 'mpesa' => 'M-Pesa', 'card' => __('Card'), 'bank_transfer' => __('Bank transfer')], $guest?->preferred_payment_method, ['class' => 'form-control', 'id' => 'preferred_payment_method']) !!}</div>

                        <div class="col-md-4">{!! Form::label('credit_limit', __('Credit limit'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('credit_limit', $guest?->credit_limit ?? 0, ['class' => 'form-control', 'step' => '0.01', 'id' => 'credit_limit']) !!}</div>
                        <div class="col-md-8">{!! Form::label('guest_notes', __('Guest notes (allergies, preferences)'), ['class' => 'form-label small mb-1']) !!}{!! Form::textarea('guest_notes', $guest?->notes, ['class' => 'form-control', 'rows' => 1, 'id' => 'guest_notes']) !!}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-3 text-uppercase text-muted small fw-bold">{{ __('Stay & Room') }}</h6>
                <div class="row g-3">
                    <div class="col-md-4">{!! Form::label('hotel_room_type_id', __('Room Type'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('hotel_room_type_id', $roomTypes, $selectedRoomType, ['class' => 'form-control', 'id' => 'hotel_room_type_id', 'required']) !!}</div>
                    <div class="col-md-4">
                        {!! Form::label('hotel_room_id', __('Room'), ['class' => 'form-label small mb-1']) !!}
                        <div class="input-group">
                            {!! Form::select('hotel_room_id', ['' => __('Select Room')] + $roomsArray, $selectedRoom, ['class' => 'form-control', 'id' => 'hotel_room_id']) !!}
                            <button type="button" class="btn btn-outline-primary" id="btn_suggest_room" title="{{ __('Suggest available room') }}"><i class="ti ti-wand"></i></button>
                        </div>
                    </div>
                    <div class="col-md-4">{!! Form::label('source', __('Source'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('source', ['manual' => 'Manual', 'walkin' => 'Walk-in', 'online' => 'Online', 'ota' => 'OTA'], $reservation?->source ?? 'manual', ['class' => 'form-control']) !!}</div>

                    <div class="col-md-4">{!! Form::label('check_in_at', __('Check-in'), ['class' => 'form-label small mb-1']) !!}<input type="datetime-local" name="check_in_at" id="check_in_at" value="{{ $checkInAtValue }}" class="form-control" required></div>
                    <div class="col-md-4">{!! Form::label('check_out_at', __('Check-out'), ['class' => 'form-label small mb-1']) !!}<input type="datetime-local" name="check_out_at" id="check_out_at" value="{{ $checkOutAtValue }}" class="form-control" required></div>
                    <div class="col-md-4">{!! Form::label('status', __('Status'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('status', ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked In', 'checked_out' => 'Checked Out', 'cancelled' => 'Cancelled'], $reservation?->status ?? 'pending', ['class' => 'form-control', 'id' => 'reservation_status']) !!}</div>

                    <div class="col-md-3">{!! Form::label('adults', __('Adults'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('adults', $reservation?->adults ?? 1, ['class' => 'form-control', 'min' => 1]) !!}</div>
                    <div class="col-md-3">{!! Form::label('children', __('Children'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('children', $reservation?->children ?? 0, ['class' => 'form-control', 'min' => 0]) !!}</div>
                    <div class="col-md-3">{!! Form::label('rate_per_night', __('Rate/Night'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('rate_per_night', $reservation?->rate_per_night ?? null, ['class' => 'form-control', 'step' => '0.01', 'required', 'id' => 'rate_per_night']) !!}</div>
                    <div class="col-md-3">{!! Form::label('discount_amount', __('Discount'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('discount_amount', $reservation?->discount_amount ?? 0, ['class' => 'form-control', 'step' => '0.01', 'id' => 'discount_amount']) !!}</div>

                    <div class="col-12">{!! Form::label('notes', __('Reservation notes'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('notes', $reservation?->notes ?? null, ['class' => 'form-control', 'placeholder' => __('Special requests, late arrival, etc.')]) !!}</div>
                </div>
                @if(isset($reservation) && $reservation->hotel_group_id)
                    <input type="hidden" name="hotel_group_id" value="{{ $reservation->hotel_group_id }}">
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card border shadow-sm sticky-top" style="top:1rem;">
            <div class="card-header py-2"><strong class="small">{{ __('Live Summary') }}</strong></div>
            <div class="card-body small" id="reservation_summary">
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ __('Nights') }}</span><span id="sum_nights">—</span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ __('Rate / night') }}</span><span id="sum_rate">—</span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ __('Subtotal') }}</span><span id="sum_sub">—</span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Discount') }}</span><span id="sum_disc">—</span></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between"><strong>{{ __('Estimated total') }}</strong><strong class="text-primary" id="sum_total">—</strong></div>
                <p class="mb-0 small mt-2 text-muted" id="sum_status_msg">{{ __('Fill dates & room type to calculate.') }}</p>
            </div>
        </div>
    </div>
</div>

@push('script-page')
<script>
    (function () {
        var roomsByType = @json($roomsByType ?? []);
        var roomSelect = document.getElementById('hotel_room_id');
        var typeSelect = document.getElementById('hotel_room_type_id');
        var selectedRoomId = @json((string) ($selectedRoom ?? ''));
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var csrfVal = csrf ? csrf.getAttribute('content') : '';

        function renderRoomOptions() {
            if (!roomSelect || !typeSelect) return;
            var typeId = String(typeSelect.value || '');
            var list = roomsByType[typeId] || [];
            var html = '<option value="">{{ __("Select Room") }}</option>';
            list.forEach(function (room) {
                var selected = selectedRoomId && String(room.id) === String(selectedRoomId) ? ' selected' : '';
                var st = room.status ? ' [' + room.status + ']' : '';
                html += '<option value="' + room.id + '"' + selected + '>Room ' + room.room_no + st + '</option>';
            });
            roomSelect.innerHTML = html;
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                selectedRoomId = '';
                renderRoomOptions();
                refreshQuote();
            });
        }
        renderRoomOptions();

        function refreshQuote() {
            var params = new URLSearchParams({
                hotel_room_type_id: typeSelect ? typeSelect.value : '',
                hotel_room_id: roomSelect ? roomSelect.value : '',
                check_in_at: document.getElementById('check_in_at') ? document.getElementById('check_in_at').value : '',
                check_out_at: document.getElementById('check_out_at') ? document.getElementById('check_out_at').value : ''
            });
            fetch('{{ route('hotel.reservations.rate-quote') }}?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.rate_per_night != null) {
                        var rp = document.getElementById('rate_per_night');
                        if (rp && !rp.dataset.userEdited) rp.value = data.rate_per_night;
                    }
                    var disc = parseFloat(document.getElementById('discount_amount') ? document.getElementById('discount_amount').value : 0) || 0;
                    var rate = parseFloat(document.getElementById('rate_per_night') ? document.getElementById('rate_per_night').value : 0) || 0;
                    var nights = data.nights || 1;
                    var sub = rate * nights;
                    document.getElementById('sum_nights').textContent = nights;
                    document.getElementById('sum_rate').textContent = rate.toFixed(2);
                    document.getElementById('sum_sub').textContent = sub.toFixed(2);
                    document.getElementById('sum_disc').textContent = disc.toFixed(2);
                    document.getElementById('sum_total').textContent = Math.max(0, sub - disc).toFixed(2);
                    document.getElementById('sum_status_msg').textContent = '{{ __("Ready") }}';
                    document.getElementById('sum_status_msg').className = 'mb-0 small mt-2 text-success';
                }).catch(function () {});
        }

        ['check_in_at', 'check_out_at', 'discount_amount', 'rate_per_night'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', refreshQuote);
            if (el) el.addEventListener('input', function () { if (id === 'rate_per_night') el.dataset.userEdited = '1'; refreshQuote(); });
        });
        if (roomSelect) roomSelect.addEventListener('change', function () { refreshQuote(); });

        document.getElementById('btn_suggest_room') && document.getElementById('btn_suggest_room').addEventListener('click', function () {
            var params = new URLSearchParams({
                hotel_room_type_id: typeSelect ? typeSelect.value : '',
                check_in_at: document.getElementById('check_in_at') ? document.getElementById('check_in_at').value : '',
                check_out_at: document.getElementById('check_out_at') ? document.getElementById('check_out_at').value : ''
            });
            fetch('{{ route('hotel.reservations.suggest-room') }}?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.room_id && roomSelect) {
                        roomSelect.value = String(data.room_id);
                        selectedRoomId = String(data.room_id);
                        refreshQuote();
                    } else {
                        alert('{{ __("No available room found for this type and period.") }}');
                    }
                });
        });

        function bindGuestRow(g) {
            document.getElementById('hotel_guest_id').value = g.id;
            document.getElementById('first_name').value = g.first_name || '';
            document.getElementById('last_name').value = g.last_name || '';
            document.getElementById('phone').value = g.phone || '';
            document.getElementById('email').value = g.email || '';
            document.getElementById('birth_date').value = g.birth_date || '';
            document.getElementById('nationality').value = g.nationality || '';
            document.getElementById('id_type').value = g.id_type || '';
            document.getElementById('id_number').value = g.id_number || '';
            document.getElementById('company_name').value = g.company_name || '';
            document.getElementById('is_vip').checked = !!g.is_vip;
            document.getElementById('preferred_room_type_id').value = g.preferred_room_type_id || '';
            document.getElementById('preferred_floor').value = g.preferred_floor || '';
            document.getElementById('preferred_payment_method').value = g.preferred_payment_method || '';
            document.getElementById('credit_limit').value = g.credit_limit || 0;
            document.getElementById('guest_notes').value = g.notes || '';
            if (g.preferred_room_type_id && typeSelect) {
                typeSelect.value = String(g.preferred_room_type_id);
                renderRoomOptions();
            }
            document.getElementById('guest_search_results').style.display = 'none';
            refreshQuote();
        }

        document.getElementById('guest_search_btn') && document.getElementById('guest_search_btn').addEventListener('click', function () {
            var q = document.getElementById('guest_search_q').value;
            if (q.length < 2) return;
            fetch('{{ route('hotel.reservations.guest-search') }}?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    var box = document.getElementById('guest_search_results');
                    box.innerHTML = '';
                    if (!list.length) { box.style.display = 'none'; return; }
                    list.forEach(function (g) {
                        var a = document.createElement('button');
                        a.type = 'button';
                        a.className = 'list-group-item list-group-item-action';
                        a.textContent = (g.first_name || '') + ' ' + (g.last_name || '') + ' — ' + (g.phone || g.email || '');
                        a.onclick = function () { bindGuestRow(g); };
                        box.appendChild(a);
                    });
                    box.style.display = 'block';
                });
        });

        var qInput = document.getElementById('guest_search_q');
        if (qInput) {
            qInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('guest_search_btn').click();
                }
            });
        }

        setTimeout(refreshQuote, 300);
    })();
</script>
@endpush
