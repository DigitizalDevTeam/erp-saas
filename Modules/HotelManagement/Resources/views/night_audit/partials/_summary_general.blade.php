@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
@endphp
<div class="row g-3 mb-3">
    <div class="col-lg col-md-6">
        <div class="card shadow-sm border-0 h-100 summary-card summary-card--blue">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="summary-icon bg-primary-subtle text-primary"><i class="ti ti-bed"></i></span>
                    <h6 class="mb-0">{{ __('Occupancy') }}</h6>
                </div>
                <p class="mb-1 small text-muted">{{ __('Average occupancy') }}</p>
                <h4 class="mb-2 text-primary">68.42%</h4>
                <div class="d-flex justify-content-between small">
                    <span>{{ __('Rooms sold') }} <strong>26</strong></span>
                    <span>{{ __('Rooms available') }} <strong>38</strong></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg col-md-6">
        <div class="card shadow-sm border-0 h-100 summary-card summary-card--green">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="summary-icon bg-success-subtle text-success"><i class="ti ti-cash"></i></span>
                    <h6 class="mb-0">{{ __('Revenue') }}</h6>
                </div>
                <ul class="list-unstyled small mb-0 summary-lines">
                    <li class="d-flex justify-content-between"><span>{{ __('Lodging') }}</span><span>{{ $fmt(18500) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Services') }}</span><span>{{ $fmt(3800) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Other revenue') }}</span><span>{{ $fmt(1450) }}</span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Total revenue') }}</span><span>{{ $fmt(23750) }}</span></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg col-md-6">
        <div class="card shadow-sm border-0 h-100 summary-card summary-card--purple">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="summary-icon" style="background:#f3e8ff;color:#7c3aed;"><i class="ti ti-credit-card"></i></span>
                    <h6 class="mb-0">{{ __('Payments') }}</h6>
                </div>
                <ul class="list-unstyled small mb-0 summary-lines">
                    <li class="d-flex justify-content-between"><span>{{ __('Cash') }}</span><span>{{ $fmt(5200) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Card') }}</span><span>{{ $fmt(8900) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Mobile money') }}</span><span>{{ $fmt(6150) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Transfer') }}</span><span>{{ $fmt(3500) }}</span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Total payments') }}</span><span>{{ $fmt(23750) }}</span></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg col-md-6">
        <div class="card shadow-sm border-0 h-100 summary-card summary-card--orange">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="summary-icon bg-warning-subtle text-warning"><i class="ti ti-wallet"></i></span>
                    <h6 class="mb-0">{{ __('Cash register') }}</h6>
                </div>
                <ul class="list-unstyled small mb-0 summary-lines">
                    <li class="d-flex justify-content-between"><span>{{ __('Previous balance') }}</span><span>{{ $fmt(2100) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Day inflows') }}</span><span>{{ $fmt(5200) }}</span></li>
                    <li class="d-flex justify-content-between text-danger"><span>{{ __('Day outflows') }}</span><span>{{ $fmt(3500) }}</span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Final balance') }}</span><span>{{ $fmt(3800) }}</span></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg col-md-6">
        <div class="card shadow-sm border-0 h-100 summary-card summary-card--cyan">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="summary-icon" style="background:#e0f2fe;color:#0284c7;"><i class="ti ti-receipt"></i></span>
                    <h6 class="mb-0">{{ __('Advances') }}</h6>
                </div>
                <ul class="list-unstyled small mb-0 summary-lines">
                    <li class="d-flex justify-content-between"><span>{{ __('Received') }}</span><span>{{ $fmt(4200) }}</span></li>
                    <li class="d-flex justify-content-between text-danger"><span>{{ __('Used') }}</span><span>{{ $fmt(1700) }}</span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Balance') }}</span><span>{{ $fmt(2500) }}</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>
