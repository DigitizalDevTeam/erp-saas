@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
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
                                <th class="text-end">{{ __('Current night') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>{{ __('Occupied rooms') }}</td><td class="text-end">24</td><td class="text-end">26</td></tr>
                            <tr><td>{{ __('Available rooms') }}</td><td class="text-end">40</td><td class="text-end">38</td></tr>
                            <tr><td>{{ __('Occupancy %') }}</td><td class="text-end">63.16%</td><td class="text-end">68.42%</td></tr>
                            <tr><td>{{ __('Arrivals') }}</td><td class="text-end">8</td><td class="text-end">11</td></tr>
                            <tr><td>{{ __('Departures') }}</td><td class="text-end">6</td><td class="text-end">9</td></tr>
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
                            <tr><td>{{ __('Room charges') }}</td><td class="text-end">{{ $fmt(15200) }}</td></tr>
                            <tr><td>{{ __('Restaurant / POS') }}</td><td class="text-end">{{ $fmt(2800) }}</td></tr>
                            <tr><td>{{ __('Laundry & extras') }}</td><td class="text-end">{{ $fmt(1000) }}</td></tr>
                            <tr><td>{{ __('Minibar') }}</td><td class="text-end">{{ $fmt(450) }}</td></tr>
                            <tr class="fw-bold table-light"><td>{{ __('Total') }}</td><td class="text-end">{{ $fmt(19450) }}</td></tr>
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
                            <tr><td>{{ __('Opening balance') }}</td><td class="text-end">{{ $fmt(2100) }}</td></tr>
                            <tr><td>{{ __('Cash receipts') }}</td><td class="text-end">{{ $fmt(5200) }}</td></tr>
                            <tr><td>{{ __('Cash payments') }}</td><td class="text-end text-danger">-{{ $fmt(3500) }}</td></tr>
                            <tr><td>{{ __('Petty cash') }}</td><td class="text-end text-danger">-{{ $fmt(0) }}</td></tr>
                            <tr class="fw-bold table-light"><td>{{ __('Closing balance') }}</td><td class="text-end">{{ $fmt(3800) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
