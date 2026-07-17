@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $occ = $preview['occupancy'] ?? [];
    $prev = $preview['occupancy_previous'] ?? [];
    $rev = $preview['revenue'] ?? [];
    $sourceLabels = [
        'room_charges' => __('Room charges'),
        'restaurant_pos' => __('Restaurant / POS'),
        'laundry_extras' => __('Laundry & extras'),
        'minibar' => __('Minibar'),
    ];
@endphp
<h6 class="fw-semibold mb-3">{{ __('Report details') }}</h6>
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header py-2 bg-transparent border-bottom">
                <h6 class="mb-0 small fw-semibold"><i class="ti ti-bed me-1 text-primary"></i>{{ __('Occupancy report') }}</h6>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Metric') }}</th>
                                <th class="text-end">{{ __('Previous day') }}</th>
                                <th class="text-end">{{ __('Selected date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>{{ __('Occupied rooms') }}</td><td class="text-end">{{ $prev['occupied'] ?? 0 }}</td><td class="text-end">{{ $occ['occupied'] ?? 0 }}</td></tr>
                            <tr><td>{{ __('Available rooms') }}</td><td class="text-end">{{ $prev['available'] ?? 0 }}</td><td class="text-end">{{ $occ['available'] ?? 0 }}</td></tr>
                            <tr><td>{{ __('Occupancy %') }}</td><td class="text-end">{{ number_format($prev['rate'] ?? 0, 2) }}%</td><td class="text-end">{{ number_format($occ['rate'] ?? 0, 2) }}%</td></tr>
                            <tr><td>{{ __('Arrivals') }}</td><td class="text-end">—</td><td class="text-end">{{ $occ['arrivals'] ?? 0 }}</td></tr>
                            <tr><td>{{ __('Departures') }}</td><td class="text-end">—</td><td class="text-end">{{ $occ['departures'] ?? 0 }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header py-2 bg-transparent border-bottom">
                <h6 class="mb-0 small fw-semibold"><i class="ti ti-cash me-1 text-success"></i>{{ __('Revenue report') }}</h6>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="table-light">
                            <tr><th>{{ __('Source') }}</th><th class="text-end">{{ __('Amount') }}</th></tr>
                        </thead>
                        <tbody>
                            @foreach($rev['by_source'] ?? [] as $row)
                                <tr>
                                    <td>{{ $sourceLabels[$row['label']] ?? $row['label'] }}</td>
                                    <td class="text-end">{{ $fmt($row['amount'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                            <tr class="fw-bold table-light"><td>{{ __('Total') }}</td><td class="text-end">{{ $fmt($rev['total'] ?? 0) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header py-2 bg-transparent border-bottom">
                <h6 class="mb-0 small fw-semibold"><i class="ti ti-wallet me-1 text-warning"></i>{{ __('Cash report') }}</h6>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="table-light">
                            <tr><th>{{ __('Movement') }}</th><th class="text-end">{{ __('Amount') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>{{ __('Folio cash payments') }}</td><td class="text-end">{{ $fmt($preview['payments']['by_method']['cash'] ?? 0) }}</td></tr>
                            <tr><td>{{ __('POS cash') }}</td><td class="text-end">{{ $fmt($preview['pos']['cash_payments'] ?? 0) }}</td></tr>
                            <tr><td>{{ __('Opening float') }}</td><td class="text-end">{{ $fmt($preview['cash_register']['opening_balance'] ?? 0) }}</td></tr>
                            <tr><td>{{ __('Cash outflows (expenses)') }}</td><td class="text-end text-danger">− {{ $fmt($preview['cash_register']['cash_outflows'] ?? 0) }}</td></tr>
                            <tr class="fw-bold table-light"><td>{{ __('Cash total') }}</td><td class="text-end">{{ $fmt($preview['cash_register']['closing_balance'] ?? 0) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
