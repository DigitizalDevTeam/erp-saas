@php
    $guest = $reservation?->guest;
    $selectedRoomType = old('hotel_room_type_id', $reservation?->hotel_room_type_id);
    $selectedRoom = old('hotel_room_id', $reservation?->hotel_room_id);
    $selectedBillingMode = old('billing_mode', $reservation?->billing_mode ?? 'nightly');
    $checkInAtValue = old('check_in_at', isset($reservation) && !empty($reservation?->check_in_at) ? \Carbon\Carbon::parse($reservation->check_in_at)->format('Y-m-d\TH:i') : null);
    $checkOutAtValue = old('check_out_at', isset($reservation) && !empty($reservation?->check_out_at) ? \Carbon\Carbon::parse($reservation->check_out_at)->format('Y-m-d\TH:i') : null);
    $etaValue = old('estimated_arrival_time', $reservation?->estimated_arrival_time ? \Carbon\Carbon::parse($reservation->estimated_arrival_time)->format('H:i') : null);
    $roomTypesArray = is_object($roomTypes ?? null) && method_exists($roomTypes, 'toArray') ? $roomTypes->toArray() : (array) ($roomTypes ?? []);
    $roomsArray = is_object($rooms ?? null) && method_exists($rooms, 'toArray') ? $rooms->toArray() : (array) ($rooms ?? []);
    $floorsArray = is_object($floors ?? null) && method_exists($floors, 'toArray') ? $floors->toArray() : (array) ($floors ?? []);
    $nationalityArray = is_object($nationalityOptions ?? null) && method_exists($nationalityOptions, 'toArray') ? $nationalityOptions->toArray() : (array) ($nationalityOptions ?? []);
    $addonsCatalog = $addonsCatalog ?? collect();
    $selectedAddonRows = old('addons');
    if ($selectedAddonRows === null && isset($reservation)) {
        $selectedAddonRows = $reservation->reservationAddons
            ?->filter(fn ($row) => ! $row->isPosted())
            ->map(fn ($row) => ['addon_id' => $row->hotel_addon_id, 'qty' => $row->qty])
            ->values()
            ->all() ?? [];
    }
    if (! is_array($selectedAddonRows) || $selectedAddonRows === []) {
        $selectedAddonRows = [['addon_id' => '', 'qty' => 1]];
    }
    $postedAddons = isset($reservation) ? $reservation->reservationAddons?->filter(fn ($row) => $row->isPosted()) : collect();
@endphp
<div class="row g-3">
    <div class="col-lg-9">
        <input type="hidden" name="hotel_guest_id" id="hotel_guest_id" value="{{ old('hotel_guest_id', $guest?->id) }}">

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-2"><i class="ti ti-user-search me-1"></i>{{ __('Find existing guest') }}</h6>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="guest_search_q" placeholder="{{ __('Name, phone, email or document...') }}" autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary" id="guest_search_btn">{{ __('Search') }}</button>
                </div>
                <div id="guest_search_results" class="list-group mt-1" style="max-height:200px;overflow:auto;display:none;"></div>
            </div>
        </div>

        <div class="alert alert-warning d-none" id="guest_alert_box"></div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-3 text-uppercase text-muted small fw-bold">{{ __('Guest Details') }}</h6>
                <div class="row g-3">
                    <div class="col-md-4">{!! Form::label('first_name', __('First Name'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('first_name', old('first_name', $guest?->first_name), ['class' => 'form-control', 'required', 'id' => 'first_name']) !!}</div>
                    <div class="col-md-4">{!! Form::label('last_name', __('Last Name'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('last_name', old('last_name', $guest?->last_name), ['class' => 'form-control', 'id' => 'last_name']) !!}</div>
                    <div class="col-md-4">{!! Form::label('phone', __('Phone'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('phone', old('phone', $guest?->phone), ['class' => 'form-control', 'id' => 'phone']) !!}</div>

                    <div class="col-md-4">{!! Form::label('email', __('Email'), ['class' => 'form-label small mb-1']) !!}{!! Form::email('email', old('email', $guest?->email), ['class' => 'form-control', 'id' => 'email']) !!}<small class="text-muted">{{ __('Booking confirmation email is sent automatically when email exists.') }}</small></div>
                    <div class="col-md-4">{!! Form::label('birth_date', __('Birth date'), ['class' => 'form-label small mb-1']) !!}{!! Form::date('birth_date', old('birth_date', $guest?->birth_date), ['class' => 'form-control', 'id' => 'birth_date']) !!}</div>
                    <div class="col-md-4">
                        {!! Form::label('nationality', __('Nationality'), ['class' => 'form-label small mb-1']) !!}
                        {!! Form::text('nationality', old('nationality', $guest?->nationality), ['class' => 'form-control', 'id' => 'nationality', 'list' => 'hotel_nationality_list', 'placeholder' => __('Start typing nationality')]) !!}
                        <datalist id="hotel_nationality_list">
                            @foreach($nationalityArray as $nationality)
                                <option value="{{ $nationality }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <div class="col-md-4">{!! Form::label('id_type', __('Document type'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('id_type', ['' => '—', 'id' => 'ID', 'passport' => __('Passport'), 'letter' => __('Letter')], old('id_type', $guest?->id_type), ['class' => 'form-control', 'id' => 'id_type']) !!}</div>
                    <div class="col-md-4">{!! Form::label('id_number', __('Document number'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('id_number', old('id_number', $guest?->id_number), ['class' => 'form-control', 'id' => 'id_number']) !!}</div>
                    <div class="col-md-4">{!! Form::label('passport_number', __('Passport number'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('passport_number', old('passport_number', $guest?->passport_number), ['class' => 'form-control', 'id' => 'passport_number']) !!}</div>
                    <div class="col-md-4">{!! Form::label('visa_number', __('Visa number'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('visa_number', old('visa_number', $guest?->visa_number), ['class' => 'form-control', 'id' => 'visa_number']) !!}</div>
                    <div class="col-md-4">
                        {!! Form::label('country', __('Country'), ['class' => 'form-label small mb-1']) !!}
                        {!! Form::text('country', old('country', $guest?->country), ['class' => 'form-control', 'id' => 'country', 'list' => 'hotel_country_list', 'placeholder' => __('Start typing country')]) !!}
                        <datalist id="hotel_country_list">
                            @foreach($nationalityArray as $nationality)
                                <option value="{{ $nationality }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-md-4">
                        {!! Form::label('company_name', __('Company'), ['class' => 'form-label small mb-1']) !!}
                        <div class="input-group">
                            {!! Form::text('company_name', old('company_name', $guest?->company_name), ['class' => 'form-control', 'id' => 'company_name']) !!}
                            <span class="input-group-text bg-white px-2">
                                <div class="form-check m-0">
                                    {!! Form::checkbox('is_vip', 1, old('is_vip', $guest?->is_vip), ['class' => 'form-check-input me-1', 'id' => 'is_vip']) !!}
                                    <label for="is_vip" class="form-check-label small mb-0">{{ __('VIP') }}</label>
                                </div>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-3 text-uppercase text-muted small fw-bold">{{ __('Guest Preferences & Alerts') }}</h6>
                <div class="row g-3">
                    <div class="col-md-4">{!! Form::label('preferred_room_type_id', __('Preferred room type'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('preferred_room_type_id', ['' => '—'] + $roomTypesArray, old('preferred_room_type_id', $guest?->preferred_room_type_id), ['class' => 'form-control', 'id' => 'preferred_room_type_id']) !!}</div>
                    <div class="col-md-4">{!! Form::label('preferred_hotel_floor_id', __('Preferred floor'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('preferred_hotel_floor_id', ['' => __('Select floor')] + $floorsArray, old('preferred_hotel_floor_id', $guest?->preferred_hotel_floor_id), ['class' => 'form-control', 'id' => 'preferred_hotel_floor_id']) !!}</div>
                    <div class="col-md-4">{!! Form::label('preferred_payment_method', __('Preferred payment'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('preferred_payment_method', ['' => '—', 'cash' => __('Cash'), 'mpesa' => 'M-Pesa', 'card' => __('Card'), 'bank_transfer' => __('Bank transfer')], old('preferred_payment_method', $guest?->preferred_payment_method), ['class' => 'form-control', 'id' => 'preferred_payment_method']) !!}</div>

                    <div class="col-md-4">{!! Form::label('credit_limit', __('Credit limit'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('credit_limit', old('credit_limit', $guest?->credit_limit ?? 0), ['class' => 'form-control', 'step' => '0.01', 'id' => 'credit_limit']) !!}</div>
                    <div class="col-md-4">{!! Form::label('allergies', __('Allergies'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('allergies', old('allergies', $guest?->allergies), ['class' => 'form-control', 'id' => 'allergies', 'placeholder' => __('e.g. nuts, seafood')]) !!}</div>
                    <div class="col-md-4">{!! Form::label('drink_preferences', __('Drink preferences'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('drink_preferences', old('drink_preferences', $guest?->drink_preferences), ['class' => 'form-control', 'id' => 'drink_preferences']) !!}</div>

                    <div class="col-md-6">{!! Form::label('preferences', __('Other preferences'), ['class' => 'form-label small mb-1']) !!}{!! Form::textarea('preferences', old('preferences', $guest?->preferences), ['class' => 'form-control', 'rows' => 2, 'id' => 'preferences', 'placeholder' => __('Pillow, smoking, quiet room, etc.')]) !!}</div>
                    <div class="col-md-6">{!! Form::label('guest_notes', __('Guest notes'), ['class' => 'form-label small mb-1']) !!}{!! Form::textarea('guest_notes', old('guest_notes', $guest?->notes), ['class' => 'form-control', 'rows' => 2, 'id' => 'guest_notes']) !!}</div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <h6 class="mb-3 text-uppercase text-muted small fw-bold">{{ __('Stay & Unit') }}</h6>
                <div class="row g-3">
                    <div class="col-md-4">{!! Form::label('hotel_room_type_id', __('Room Type'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('hotel_room_type_id', $roomTypes, $selectedRoomType, ['class' => 'form-control', 'id' => 'hotel_room_type_id', 'required']) !!}</div>
                    <div class="col-md-4">
                        {!! Form::label('hotel_room_id', __('Room / Apartment'), ['class' => 'form-label small mb-1']) !!}
                        <div class="input-group">
                            {!! Form::select('hotel_room_id', ['' => __('Select Unit')] + $roomsArray, $selectedRoom, ['class' => 'form-control', 'id' => 'hotel_room_id']) !!}
                            <button type="button" class="btn btn-outline-primary" id="btn_suggest_room" title="{{ __('Suggest available room') }}"><i class="ti ti-wand"></i></button>
                        </div>
                    </div>
                    <div class="col-md-4">{!! Form::label('source', __('Source'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('source', ['manual' => __('Manual'), 'walkin' => __('Walk-in'), 'online' => __('Online'), 'ota' => __('OTA')], old('source', $reservation?->source ?? 'manual'), ['class' => 'form-control']) !!}</div>

                    <div class="col-md-3">{!! Form::label('billing_mode', __('Billing mode'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('billing_mode', ['nightly' => __('Nightly'), 'monthly' => __('Monthly')], $selectedBillingMode, ['class' => 'form-control', 'id' => 'billing_mode']) !!}</div>
                    <div class="col-md-3">{!! Form::label('check_in_at', __('Check-in'), ['class' => 'form-label small mb-1']) !!}<input type="datetime-local" name="check_in_at" id="check_in_at" value="{{ $checkInAtValue }}" class="form-control" required></div>
                    <div class="col-md-3">{!! Form::label('check_out_at', __('Check-out'), ['class' => 'form-label small mb-1']) !!}<input type="datetime-local" name="check_out_at" id="check_out_at" value="{{ $checkOutAtValue }}" class="form-control" required></div>
                    <div class="col-md-3">{!! Form::label('estimated_arrival_time', __('ETA'), ['class' => 'form-label small mb-1']) !!}<input type="time" name="estimated_arrival_time" id="estimated_arrival_time" value="{{ $etaValue }}" class="form-control"></div>

                    <div class="col-md-3">{!! Form::label('status', __('Status'), ['class' => 'form-label small mb-1']) !!}{!! Form::select('status', ['pending' => __('Pending'), 'confirmed' => __('Confirmed'), 'checked_in' => __('Checked In'), 'checked_out' => __('Checked Out'), 'cancelled' => __('Cancelled')], old('status', $reservation?->status ?? 'pending'), ['class' => 'form-control', 'id' => 'reservation_status']) !!}</div>
                    <div class="col-md-3">{!! Form::label('adults', __('Adults'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('adults', old('adults', $reservation?->adults ?? 1), ['class' => 'form-control', 'min' => 1]) !!}</div>
                    <div class="col-md-3">{!! Form::label('children', __('Children'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('children', old('children', $reservation?->children ?? 0), ['class' => 'form-control', 'min' => 0]) !!}</div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check">
                            {!! Form::checkbox('parking_requested', 1, old('parking_requested', $reservation?->parking_requested), ['class' => 'form-check-input', 'id' => 'parking_requested']) !!}
                            {!! Form::label('parking_requested', __('Parking requested'), ['class' => 'form-check-label']) !!}
                        </div>
                    </div>

                    <div class="col-md-4">{!! Form::label('rate_per_night', __('Derived Rate'), ['class' => 'form-label small mb-1', 'id' => 'rate_label']) !!}{!! Form::number('rate_per_night', old('rate_per_night', $reservation?->rate_per_night ?? null), ['class' => 'form-control', 'step' => '0.01', 'required', 'id' => 'rate_per_night', 'readonly']) !!}<small class="text-muted">{{ __('Rate comes from room/apartment setup and active rate plans.') }}</small></div>
                    <div class="col-md-4">{!! Form::label('discount_amount', __('Discount amount'), ['class' => 'form-label small mb-1']) !!}{!! Form::number('discount_amount', old('discount_amount', $reservation?->discount_amount ?? 0), ['class' => 'form-control', 'step' => '0.01', 'id' => 'discount_amount']) !!}<small class="text-muted">{{ __('Your limit: :percent%', ['percent' => rtrim(rtrim(number_format($maxDiscountPercent ?? 0, 2, '.', ''), '0'), '.')]) }}</small></div>
                    <div class="col-md-4">{!! Form::label('discount_reason', __('Discount justification'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('discount_reason', old('discount_reason', $reservation?->discount_reason), ['class' => 'form-control', 'id' => 'discount_reason', 'placeholder' => __('Required when discount is entered')]) !!}</div>

                    <div class="col-12">{!! Form::label('notes', __('Reservation notes'), ['class' => 'form-label small mb-1']) !!}{!! Form::text('notes', old('notes', $reservation?->notes ?? null), ['class' => 'form-control', 'placeholder' => __('Special requests, late arrival, parking details, etc.')]) !!}</div>
                </div>
                @if(isset($reservation) && $reservation->hotel_group_id)
                    <input type="hidden" name="hotel_group_id" value="{{ $reservation->hotel_group_id }}">
                @endif
            </div>
        </div>

        @if($addonsCatalog->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 text-uppercase text-muted small fw-bold">{{ __('Addons & extras') }}</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn_add_addon_row"><i class="ti ti-plus me-1"></i>{{ __('Add row') }}</button>
                </div>
                <p class="text-muted small">{{ __('Extra bed is billed per night. Other addons follow their catalog billing mode.') }}</p>
                <div id="addon_rows">
                    @foreach($selectedAddonRows as $index => $row)
                        <div class="row g-2 align-items-end addon-row mb-2">
                            <div class="col-md-7">
                                <label class="form-label small mb-1">{{ __('Addon') }}</label>
                                <select name="addons[{{ $index }}][addon_id]" class="form-control addon-select">
                                    <option value="">{{ __('Select addon') }}</option>
                                    @foreach($addonsCatalog as $addon)
                                        <option value="{{ $addon->id }}" data-price="{{ $addon->price }}" data-billing="{{ $addon->billing_mode }}" data-type="{{ $addon->type }}" @selected((string) ($row['addon_id'] ?? '') === (string) $addon->id)>
                                            {{ $addon->name }} — {{ Auth::user()->priceFormat($addon->price) }}
                                            ({{ \Modules\HotelManagement\Entities\HotelAddon::billingModeOptions()[$addon->billing_mode] ?? $addon->billing_mode }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">{{ __('Qty') }}</label>
                                <input type="number" name="addons[{{ $index }}][qty]" class="form-control addon-qty" min="1" value="{{ $row['qty'] ?? 1 }}">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-danger w-100 btn-remove-addon" title="{{ __('Remove') }}"><i class="ti ti-trash"></i></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($postedAddons && $postedAddons->isNotEmpty())
                    <div class="mt-3 small">
                        <strong>{{ __('Posted to room bill') }}:</strong>
                        <ul class="mb-0 ps-3">
                            @foreach($postedAddons as $posted)
                                <li>{{ $posted->addon?->name }} × {{ $posted->qty }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    <div class="col-lg-3">
        <div class="card border shadow-sm sticky-top" style="top:1rem;">
            <div class="card-header py-2"><strong class="small">{{ __('Live Summary') }}</strong></div>
            <div class="card-body small" id="reservation_summary">
                <div class="d-flex justify-content-between mb-1"><span class="text-muted" id="sum_units_label">{{ __('Nights') }}</span><span id="sum_nights">—</span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted" id="sum_rate_label">{{ __('Rate / night') }}</span><span id="sum_rate">—</span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">{{ __('Subtotal') }}</span><span id="sum_sub">—</span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Discount') }}</span><span id="sum_disc">—</span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Rate source') }}</span><span id="sum_source">—</span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Addons & extras') }}</span><span id="sum_addons">—</span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Max guests') }}</span><span id="sum_capacity">—</span></div>
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
        var roomTypesMeta = @json($roomTypesMeta ?? []);
        var selectedRoomId = @json((string) ($selectedRoom ?? ''));
        var roomSelect = document.getElementById('hotel_room_id');
        var typeSelect = document.getElementById('hotel_room_type_id');
        var billingModeSelect = document.getElementById('billing_mode');
        var guestAlertBox = document.getElementById('guest_alert_box');
        var autoBillingMode = true;

        function setSummaryText(id, value) {
            var el = document.getElementById(id);
            if (el) el.textContent = value;
        }

        function setFieldValue(id, value, triggerChange) {
            var el = document.getElementById(id);
            if (!el) return;
            el.value = value || '';
            if (triggerChange) {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function extraBedCount() {
            var count = 0;
            document.querySelectorAll('.addon-row').forEach(function (row) {
                var select = row.querySelector('.addon-select');
                var qtyInput = row.querySelector('.addon-qty');
                if (!select || !select.value) return;
                var opt = select.options[select.selectedIndex];
                if ((opt.getAttribute('data-type') || '') !== 'extra_bed') return;
                count += parseInt(qtyInput && qtyInput.value ? qtyInput.value : 1, 10) || 1;
            });
            return count;
        }

        function guestCount() {
            var adults = parseInt(document.getElementById('adults') ? document.getElementById('adults').value : 1, 10) || 1;
            var children = parseInt(document.getElementById('children') ? document.getElementById('children').value : 0, 10) || 0;
            return Math.max(1, adults) + Math.max(0, children);
        }

        function maxGuestCapacity() {
            if (!typeSelect) return null;
            var meta = roomTypesMeta[String(typeSelect.value || '')] || {};
            var base = parseInt(meta.max_occupancy || 2, 10) || 2;
            return base + extraBedCount();
        }

        function updateCapacitySummary() {
            var cap = maxGuestCapacity();
            var guests = guestCount();
            var el = document.getElementById('sum_capacity');
            if (!el || cap == null) return;
            el.textContent = guests + ' / ' + cap;
            el.className = guests > cap ? 'text-danger fw-bold' : '';
        }

        function renderRoomOptions() {
            if (!roomSelect || !typeSelect) return;
            var typeId = String(typeSelect.value || '');
            var list = roomsByType[typeId] || [];
            var html = '<option value="">{{ __("Select Unit") }}</option>';
            list.forEach(function (room) {
                var isSelected = selectedRoomId && String(room.id) === String(selectedRoomId);
                if (!isSelected && room.status && room.status !== 'available') {
                    return;
                }
                var selected = isSelected ? ' selected' : '';
                var status = room.status && room.status !== 'available' ? ' [' + room.status + ']' : '';
                var floor = room.floor_name ? ' - ' + room.floor_name : '';
                html += '<option value="' + room.id + '"' + selected + '>' + room.room_no + floor + status + '</option>';
            });
            roomSelect.innerHTML = html;
            updateCapacitySummary();
        }

        function updateRateLabels(mode) {
            var unitLabel = mode === 'monthly' ? '{{ __("Months") }}' : '{{ __("Nights") }}';
            var rateLabel = mode === 'monthly' ? '{{ __("Derived Rate / month") }}' : '{{ __("Derived Rate / night") }}';
            var summaryRateLabel = mode === 'monthly' ? '{{ __("Rate / month") }}' : '{{ __("Rate / night") }}';
            var rateFieldLabel = document.getElementById('rate_label');
            if (rateFieldLabel) rateFieldLabel.textContent = rateLabel;
            setSummaryText('sum_units_label', unitLabel);
            setSummaryText('sum_rate_label', summaryRateLabel);
        }

        function calcAddonTotal(units) {
            var total = 0;
            document.querySelectorAll('.addon-row').forEach(function (row) {
                var select = row.querySelector('.addon-select');
                var qtyInput = row.querySelector('.addon-qty');
                if (!select || !select.value) return;
                var opt = select.options[select.selectedIndex];
                var price = parseFloat(opt.getAttribute('data-price') || 0) || 0;
                var billing = opt.getAttribute('data-billing') || 'per_stay';
                var qty = parseInt(qtyInput && qtyInput.value ? qtyInput.value : 1, 10) || 1;
                var multiplier = billing === 'per_night' ? Math.max(1, units || 1) : 1;
                total += price * qty * multiplier;
            });
            return total;
        }

        function refreshQuote() {
            var params = new URLSearchParams({
                hotel_room_type_id: typeSelect ? typeSelect.value : '',
                hotel_room_id: roomSelect ? roomSelect.value : '',
                billing_mode: billingModeSelect ? billingModeSelect.value : 'nightly',
                check_in_at: document.getElementById('check_in_at') ? document.getElementById('check_in_at').value : '',
                check_out_at: document.getElementById('check_out_at') ? document.getElementById('check_out_at').value : ''
            });

            updateRateLabels(billingModeSelect ? billingModeSelect.value : 'nightly');

            fetch('{{ route('hotel.reservations.rate-quote') }}?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var rateField = document.getElementById('rate_per_night');
                    if (rateField && data.rate_amount != null) {
                        rateField.value = data.rate_amount;
                    }
                    var discount = parseFloat(document.getElementById('discount_amount') ? document.getElementById('discount_amount').value : 0) || 0;
                    var subtotal = parseFloat(data.subtotal || 0) || 0;
                    var roomTotal = Math.max(0, subtotal - discount);
                    var addonTotal = calcAddonTotal(parseInt(data.units || 1, 10));
                    var total = roomTotal + addonTotal;
                    setSummaryText('sum_nights', String(data.units || 1));
                    setSummaryText('sum_rate', (parseFloat(data.rate_amount || 0) || 0).toFixed(2));
                    setSummaryText('sum_sub', subtotal.toFixed(2));
                    setSummaryText('sum_disc', discount.toFixed(2));
                    setSummaryText('sum_addons', addonTotal.toFixed(2));
                    setSummaryText('sum_total', total.toFixed(2));
                    setSummaryText('sum_source', String(data.rate_plan_name || data.source || '{{ __("setup") }}'));

                    var statusMsg = document.getElementById('sum_status_msg');
                    updateCapacitySummary();
                    var cap = maxGuestCapacity();
                    var guests = guestCount();
                    if (statusMsg) {
                        if (cap != null && guests > cap) {
                            statusMsg.textContent = '{{ __("Guest count exceeds room capacity.") }}';
                            statusMsg.className = 'mb-0 small mt-2 text-danger';
                        } else {
                            statusMsg.textContent = '{{ __("Ready") }}';
                            statusMsg.className = 'mb-0 small mt-2 text-success';
                        }
                    }
                })
                .catch(function () {});
        }

        function showGuestAlerts(guest, silent) {
            if (!guestAlertBox) return;
            var lines = [];
            if (guest.allergies) lines.push('{{ __("Allergies") }}: ' + guest.allergies);
            if (guest.preferences) lines.push('{{ __("Preferences") }}: ' + guest.preferences);
            if (guest.drink_preferences) lines.push('{{ __("Drink preferences") }}: ' + guest.drink_preferences);
            if (guest.is_vip) lines.push('{{ __("VIP guest") }}');

            if (!lines.length) {
                guestAlertBox.classList.add('d-none');
                guestAlertBox.innerHTML = '';
                return;
            }

            guestAlertBox.classList.remove('d-none');
            guestAlertBox.innerHTML = '<strong>{{ __("Guest alert") }}:</strong> ' + lines.join(' | ');
            if (!silent) {
                alert('{{ __("Guest alert") }}\n' + lines.join('\n'));
            }
        }

        function bindGuestRow(g) {
            document.getElementById('hotel_guest_id').value = g.id;
            document.getElementById('first_name').value = g.first_name || '';
            document.getElementById('last_name').value = g.last_name || '';
            document.getElementById('phone').value = g.phone || '';
            document.getElementById('email').value = g.email || '';
            document.getElementById('birth_date').value = g.birth_date || '';
            setFieldValue('nationality', g.nationality, true);
            setFieldValue('id_type', g.id_type, true);
            document.getElementById('id_number').value = g.id_number || '';
            document.getElementById('passport_number').value = g.passport_number || '';
            document.getElementById('visa_number').value = g.visa_number || '';
            document.getElementById('country').value = g.country || '';
            document.getElementById('company_name').value = g.company_name || '';
            document.getElementById('is_vip').checked = !!g.is_vip;
            setFieldValue('preferred_room_type_id', g.preferred_room_type_id, true);
            setFieldValue('preferred_hotel_floor_id', g.preferred_hotel_floor_id, true);
            setFieldValue('preferred_payment_method', g.preferred_payment_method, true);
            document.getElementById('credit_limit').value = g.credit_limit || 0;
            document.getElementById('guest_notes').value = g.notes || '';
            document.getElementById('allergies').value = g.allergies || '';
            document.getElementById('preferences').value = g.preferences || '';
            document.getElementById('drink_preferences').value = g.drink_preferences || '';

            if (g.preferred_room_type_id && typeSelect) {
                typeSelect.value = String(g.preferred_room_type_id);
                renderRoomOptions();
            }

            document.getElementById('guest_search_results').style.display = 'none';
            showGuestAlerts(g, false);
            refreshQuote();
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                selectedRoomId = '';
                renderRoomOptions();
                if (autoBillingMode) {
                    var meta = roomTypesMeta[String(typeSelect.value || '')];
                    if (meta && billingModeSelect) {
                        billingModeSelect.value = meta.default_billing_mode || 'nightly';
                    }
                }
                refreshQuote();
            });
        }

        if (billingModeSelect) {
            billingModeSelect.addEventListener('change', function () {
                autoBillingMode = false;
                refreshQuote();
            });
        }

        renderRoomOptions();

        ['check_in_at', 'check_out_at', 'discount_amount'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', refreshQuote);
                el.addEventListener('input', refreshQuote);
            }
        });
        if (roomSelect) roomSelect.addEventListener('change', function () { selectedRoomId = roomSelect.value; refreshQuote(); });

        var suggestBtn = document.getElementById('btn_suggest_room');
        if (suggestBtn) {
            suggestBtn.addEventListener('click', function () {
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
        }

        var searchBtn = document.getElementById('guest_search_btn');
        if (searchBtn) {
            searchBtn.addEventListener('click', function () {
                var q = document.getElementById('guest_search_q').value;
                if (q.length < 2) return;
                fetch('{{ route('hotel.reservations.guest-search') }}?q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (list) {
                        var box = document.getElementById('guest_search_results');
                        box.innerHTML = '';
                        if (!list.length) { box.style.display = 'none'; return; }
                        list.forEach(function (g) {
                            var button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'list-group-item list-group-item-action';
                            button.textContent = (g.first_name || '') + ' ' + (g.last_name || '') + ' — ' + (g.phone || g.email || '');
                            button.onclick = function () { bindGuestRow(g); };
                            box.appendChild(button);
                        });
                        box.style.display = 'block';
                    });
            });
        }

        var qInput = document.getElementById('guest_search_q');
        if (qInput) {
            qInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    searchBtn && searchBtn.click();
                }
            });
        }

        var addonRows = document.getElementById('addon_rows');
        var addAddonBtn = document.getElementById('btn_add_addon_row');
        function reindexAddonRows() {
            if (!addonRows) return;
            addonRows.querySelectorAll('.addon-row').forEach(function (row, index) {
                var select = row.querySelector('.addon-select');
                var qty = row.querySelector('.addon-qty');
                if (select) select.name = 'addons[' + index + '][addon_id]';
                if (qty) qty.name = 'addons[' + index + '][qty]';
            });
        }
        function bindAddonRowEvents(row) {
            row.querySelectorAll('.addon-select, .addon-qty').forEach(function (el) {
                el.addEventListener('change', refreshQuote);
                el.addEventListener('input', refreshQuote);
            });
            var removeBtn = row.querySelector('.btn-remove-addon');
            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    if (addonRows.querySelectorAll('.addon-row').length <= 1) {
                        row.querySelector('.addon-select').value = '';
                        row.querySelector('.addon-qty').value = '1';
                        refreshQuote();
                        return;
                    }
                    row.remove();
                    reindexAddonRows();
                    refreshQuote();
                });
            }
        }
        if (addonRows) {
            addonRows.querySelectorAll('.addon-row').forEach(bindAddonRowEvents);
        }
        if (addAddonBtn && addonRows) {
            addAddonBtn.addEventListener('click', function () {
                var first = addonRows.querySelector('.addon-row');
                if (!first) return;
                var clone = first.cloneNode(true);
                clone.querySelector('.addon-select').value = '';
                clone.querySelector('.addon-qty').value = '1';
                addonRows.appendChild(clone);
                reindexAddonRows();
                bindAddonRowEvents(clone);
                refreshQuote();
            });
        }

        ['adults', 'children'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', refreshQuote);
                el.addEventListener('input', refreshQuote);
            }
        });

        updateRateLabels(billingModeSelect ? billingModeSelect.value : 'nightly');
        showGuestAlerts({
            allergies: document.getElementById('allergies') ? document.getElementById('allergies').value : '',
            preferences: document.getElementById('preferences') ? document.getElementById('preferences').value : '',
            drink_preferences: document.getElementById('drink_preferences') ? document.getElementById('drink_preferences').value : '',
            is_vip: document.getElementById('is_vip') ? document.getElementById('is_vip').checked : false
        }, true);
        setTimeout(refreshQuote, 300);
    })();
</script>
@endpush
