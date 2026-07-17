@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $occ = $preview['occupancy'] ?? [];
    $rev = $preview['revenue'] ?? [];
    $pay = $preview['payments'] ?? [];
    $cash = $preview['cash_register'] ?? [];
    $adv = $preview['advances'] ?? [];
    $methodLabels = \Modules\HotelManagement\Services\HotelFolioPaymentService::paymentMethodOptions();
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
                <h4 class="mb-2 text-primary">{{ number_format($occ['rate'] ?? 0, 2) }}%</h4>
                <div class="d-flex justify-content-between small">
                    <span>{{ __('Rooms sold') }} <strong>{{ $occ['occupied'] ?? 0 }}</strong></span>
                    <span>{{ __('Rooms available') }} <strong>{{ $occ['available'] ?? 0 }}</strong></span>
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
                    <li class="d-flex justify-content-between"><span>{{ __('Lodging') }}</span><span>{{ $fmt($rev['lodging'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Services') }}</span><span>{{ $fmt(($rev['services'] ?? 0) + ($rev['laundry'] ?? 0) + ($rev['minibar'] ?? 0) + ($rev['other'] ?? 0)) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('POS sales') }}</span><span>{{ $fmt($preview['pos']['sales_total'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Total revenue') }}</span><span>{{ $fmt($rev['total'] ?? 0) }}</span></li>
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
                    @forelse($pay['by_method'] ?? [] as $method => $amount)
                        <li class="d-flex justify-content-between">
                            <span>{{ $methodLabels[$method] ?? __(ucfirst(str_replace('_', ' ', $method))) }}</span>
                            <span>{{ $fmt($amount) }}</span>
                        </li>
                    @empty
                        <li class="text-muted">{{ __('No folio payments on this date.') }}</li>
                    @endforelse
                    @if(($preview['pos']['payments_total'] ?? 0) > 0)
                        <li class="d-flex justify-content-between"><span>{{ __('POS payments') }}</span><span>{{ $fmt($preview['pos']['payments_total']) }}</span></li>
                    @endif
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Total payments') }}</span><span>{{ $fmt($pay['total'] ?? 0) }}</span></li>
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
                    <li class="d-flex justify-content-between"><span>{{ __('Opening float') }}</span><span>{{ $fmt($cash['opening_balance'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Cash inflows') }}</span><span>{{ $fmt($cash['cash_inflows'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('POS cash') }}</span><span>{{ $fmt($preview['pos']['cash_payments'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between text-danger"><span>{{ __('Cash outflows (expenses)') }}</span><span>− {{ $fmt($cash['cash_outflows'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between fw-bold border-top pt-2 mt-2"><span>{{ __('Cash total') }}</span><span>{{ $fmt($cash['closing_balance'] ?? 0) }}</span></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg col-md-6">
        <div class="card shadow-sm border-0 h-100 summary-card summary-card--cyan">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="summary-icon" style="background:#e0f2fe;color:#0284c7;"><i class="ti ti-receipt"></i></span>
                    <h6 class="mb-0">{{ __('Open balances') }}</h6>
                </div>
                <ul class="list-unstyled small mb-0 summary-lines">
                    <li class="d-flex justify-content-between"><span>{{ __('Payments received') }}</span><span>{{ $fmt($adv['received'] ?? 0) }}</span></li>
                    <li class="d-flex justify-content-between"><span>{{ __('Open folio balance') }}</span><span>{{ $fmt($adv['balance'] ?? 0) }}</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>
