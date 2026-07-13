@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $exec = $preview['executive'] ?? [];
    $guests = $exec['guests_in_house'] ?? [];
    $shifts = $exec['shifts'] ?? [];
    $shiftLabels = \Modules\HotelManagement\Entities\HotelCashierShift::operationalShiftOptions();
@endphp

<div class="hotel-night-audit-executive mb-4">
    <h6 class="fw-semibold mb-3">{{ __('Executive report') }}</h6>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3 text-center">
                    <div class="text-muted small">{{ __('Check-ins completed') }}</div>
                    <div class="fs-4 fw-semibold">{{ $exec['check_ins_completed'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3 text-center">
                    <div class="text-muted small">{{ __('Check-outs completed') }}</div>
                    <div class="fs-4 fw-semibold">{{ $exec['check_outs_completed'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3 text-center">
                    <div class="text-muted small">{{ __('Arrivals tomorrow') }}</div>
                    <div class="fs-4 fw-semibold">{{ $exec['arrivals_tomorrow'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3 text-center">
                    <div class="text-muted small">{{ __('Departures tomorrow') }}</div>
                    <div class="fs-4 fw-semibold">{{ $exec['departures_tomorrow'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header py-2 bg-transparent">
            <h6 class="mb-0 small fw-semibold">{{ __('Guests in house') }}</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('Guest') }}</th>
                            <th>{{ __('Nationality') }}</th>
                            <th>{{ __('Room') }}</th>
                            <th>{{ __('Check-in') }}</th>
                            <th>{{ __('Check-out') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($guests as $guest)
                        <tr>
                            <td>{{ $guest['guest'] ?? '—' }}</td>
                            <td>{{ $guest['nationality'] ?? '—' }}</td>
                            <td>{{ $guest['room_no'] ?? '—' }}</td>
                            <td>{{ $guest['check_in'] ?? '—' }}</td>
                            <td>{{ $guest['check_out'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted text-center">{{ __('No in-house guests.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header py-2 bg-transparent">
            <h6 class="mb-0 small fw-semibold">{{ __('Cashier shifts') }}</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('Cashier') }}</th>
                            <th>{{ __('Shift') }}</th>
                            <th>{{ __('Opened') }}</th>
                            <th>{{ __('Closed') }}</th>
                            <th class="text-end">{{ __('Cash') }}</th>
                            <th class="text-end">{{ __('Card') }}</th>
                            <th class="text-end">{{ __('Expected') }}</th>
                            <th class="text-end">{{ __('Closing') }}</th>
                            <th class="text-end">{{ __('Diff') }}</th>
                            <th>{{ __('Handed to') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($shifts as $shift)
                        <tr>
                            <td>{{ $shift['cashier'] ?? '—' }}</td>
                            <td>{{ $shiftLabels[$shift['operational_shift'] ?? ''] ?? ($shift['operational_shift'] ?: '—') }}</td>
                            <td>{{ $shift['opened_at'] ?? '—' }}</td>
                            <td>{{ $shift['closed_at'] ?? '—' }}</td>
                            <td class="text-end">{{ $fmt($shift['cash_collected'] ?? 0) }}</td>
                            <td class="text-end">{{ $fmt($shift['card_collected'] ?? 0) }}</td>
                            <td class="text-end">{{ $fmt($shift['expected_cash'] ?? 0) }}</td>
                            <td class="text-end">{{ $fmt($shift['closing_cash'] ?? 0) }}</td>
                            <td class="text-end">{{ $fmt($shift['difference'] ?? 0) }}</td>
                            <td>{{ $shift['handed_to'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-muted text-center">{{ __('No closed cashier shifts for this date.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-2 pt-3">
        <div class="col-md-6">
            <div class="border-top pt-4 text-center">
                <div style="min-height: 48px;"></div>
                <div class="border-top mx-auto" style="max-width: 220px;"></div>
                <div class="small fw-semibold mt-2">{{ __('Night Auditor') }}</div>
                <div class="small text-muted">{{ __('Signature / Name') }}</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="border-top pt-4 text-center">
                <div style="min-height: 48px;"></div>
                <div class="border-top mx-auto" style="max-width: 220px;"></div>
                <div class="small fw-semibold mt-2">{{ __('Director') }}</div>
                <div class="small text-muted">{{ __('Signature / Name') }}</div>
            </div>
        </div>
    </div>
</div>
