@extends('layouts.admin')
@section('page-title'){{ __('Check-out') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item">{{ __('Check-out') }}</li>
@endsection

@push('css-page')
<style>
    .hotel-checkout .status-pill {
        font-size: 0.7rem;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        font-weight: 600;
    }
    .hotel-checkout .status-chip {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        text-align: center;
        min-width: 90px;
    }
    .hotel-checkout .status-chip .label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.03em;
    }
    .hotel-checkout .status-chip .value {
        font-weight: 700;
        color: #2563eb;
        font-size: 0.95rem;
    }
    .hotel-checkout .guest-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        color: #64748b;
        flex-shrink: 0;
    }
    .hotel-checkout .consumption-icon {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-right: 6px;
        font-size: 14px;
    }
    .hotel-checkout .balance-due-box {
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.25);
        border-radius: 8px;
        padding: 0.75rem 1rem;
    }
    .hotel-checkout .change-box {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.3);
        border-radius: 8px;
        padding: 0.65rem 1rem;
    }
    .hotel-checkout .quick-action-btn { text-align: left; }
    .hotel-checkout .checkout-status-bar {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.5rem;
    }
</style>
@endpush

@section('content')
@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $guest = $reservation->guest;
    $room = $reservation->room;
    $roomType = $room?->roomType?->name ?? __('Deluxe');
    $guestName = $guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('João Mateus');
    $guestCode = $guest?->guest_code ?? $guest?->id ?? '000125';
    $phone = $guest?->phone ?? '+258 84 123 4567';
    $email = $guest?->email ?? 'joaomateus@email.com';
    $roomNo = $room?->room_no ?? '203';
    $checkInAt = $reservation->check_in_at
        ? \Carbon\Carbon::parse($reservation->check_in_at)->format('d/m/Y H:i')
        : (\Carbon\Carbon::parse($reservation->check_in_date)->format('d/m/Y') . ' 14:00');
    $checkOutAt = $reservation->check_out_at
        ? \Carbon\Carbon::parse($reservation->check_out_at)->format('d/m/Y H:i')
        : (\Carbon\Carbon::parse($reservation->check_out_date)->format('d/m/Y') . ' 11:00');
    $nights = max(1, \Carbon\Carbon::parse($reservation->check_in_date)->diffInDays(\Carbon\Carbon::parse($reservation->check_out_date)));
    $departureDisplay = \Carbon\Carbon::parse($reservation->check_out_date)->format('d/m/Y');
    $folio = $folio ?? $reservation->stay?->folio;
    $paymentLabel = $folio ? __(ucfirst($folio->paymentStatusLabel())) : __('Pending');
    $paymentBadge = ($folio && $folio->paymentStatusLabel() === 'paid') ? 'success' : 'warning';

    $consumptionLines = $consumptionLines ?? [];
    if ($folio && empty($consumptionLines)) {
        $consumptionLines = app(\Modules\HotelManagement\Services\HotelRoomConsumptionService::class)
            ->folioLinesForCheckout($folio);
    }

    $subtotal = $folio ? (float) $folio->sub_total : 0;
    $discount = $folio ? (float) $folio->discount_total : 0;
    $tax = $folio ? (float) $folio->tax_total : 0;
    $total = $folio ? (float) $folio->grand_total : 0;
    $paid = $folio ? (float) $folio->paid_total : 0;
    $balance = max(0, $total - $paid);
    $folioShowUrl = $folio ? route('hotel.folios.show', $folio->id) : null;
    $finalBillUrl = $folio ? route('hotel.folios.final-bill', $folio->id) : null;
@endphp

<div class="hotel-checkout">
    <div class="checkout-status-bar mb-3">
            <div class="status-chip">
                <div class="label">{{ __('Room') }}</div>
                <div class="value">{{ $roomNo }}</div>
            </div>
            <div class="status-chip">
                <div class="label">{{ __('Type') }}</div>
                <div class="value">{{ $roomType }}</div>
            </div>
            <div class="status-chip">
                <div class="label">{{ __('Status') }}</div>
                <span class="status-pill bg-success text-white">{{ __('Occupied') }}</span>
            </div>
            <div class="status-chip">
                <div class="label">{{ __('Account') }}</div>
                <span class="status-pill bg-{{ $paymentBadge }} text-white">{{ $paymentLabel }}</span>
            </div>
            <div class="status-chip">
                <div class="label">{{ __('Departure') }}</div>
                <div class="value">{{ $departureDisplay }}</div>
            </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Guest details') }}</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3 flex-wrap mb-4">
                        <div class="guest-avatar"><i class="ti ti-user"></i></div>
                        <div>
                            <h4 class="mb-0">{{ $guestName }}</h4>
                            <p class="text-muted small mb-2">{{ __('Guest') }}: {{ $guestCode }}</p>
                            <p class="mb-1 small"><i class="ti ti-phone me-1 text-primary"></i>{{ $phone }}</p>
                            <p class="mb-0 small"><i class="ti ti-mail me-1 text-primary"></i>{{ $email }}</p>
                        </div>
                    </div>
                    <div class="row g-3 small">
                        <div class="col-6 col-md-4">
                            <span class="text-muted d-block">{{ __('Check-in') }}</span>
                            <strong>{{ $checkInAt }}</strong>
                        </div>
                        <div class="col-6 col-md-4">
                            <span class="text-muted d-block">{{ __('Check-out') }}</span>
                            <strong>{{ $checkOutAt }}</strong>
                        </div>
                        <div class="col-6 col-md-4">
                            <span class="text-muted d-block">{{ __('Nights') }}</span>
                            <strong>{{ $nights }}</strong>
                        </div>
                        <div class="col-6 col-md-4">
                            <span class="text-muted d-block">{{ __('Adults') }}</span>
                            <strong>{{ $reservation->adults ?? 2 }}</strong>
                        </div>
                        <div class="col-6 col-md-4">
                            <span class="text-muted d-block">{{ __('Children') }}</span>
                            <strong>{{ $reservation->children ?? 0 }}</strong>
                        </div>
                        <div class="col-6 col-md-4">
                            <span class="text-muted d-block">{{ __('Agency / Company') }}</span>
                            <strong>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $reservation->source ?? 'individual'))) }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">{{ __('Consumption summary') }}</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Details') }}</th>
                                    <th class="text-center">{{ __('Qty') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($consumptionLines as $line)
                                    <tr>
                                        <td>
                                            <span class="consumption-icon" style="background:{{ $line['bg'] }};color:{{ $line['color'] }};">
                                                <i class="ti {{ $line['icon'] }}"></i>
                                            </span>
                                            {{ $line['desc'] }}
                                        </td>
                                        <td class="text-muted small">{{ $line['detail'] }}</td>
                                        <td class="text-center">{{ $line['qty'] }}</td>
                                        <td class="text-end fw-semibold">{{ $fmt($line['amount']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted text-center py-3">{{ __('No folio charges yet. Room bill is created at check-in.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if(($canManage ?? false) && $reservation->room)
                    <div class="p-3 border-top text-end d-flex flex-wrap gap-2 justify-content-end">
                        <a href="{{ route('hotel.rooms.consumption', $reservation->room->id) }}" class="btn btn-sm btn-outline-primary">
                            <i class="ti ti-plus me-1"></i>{{ __('Add consumption') }}
                        </a>
                        @if($folioShowUrl)
                            <a href="{{ $folioShowUrl }}" class="btn btn-sm btn-outline-secondary">{{ __('Room bill') }}</a>
                        @endif
                    </div>
                    @endif
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Observations') }}</h6>
                </div>
                <div class="card-body">
                    <textarea class="form-control" rows="4" placeholder="{{ __('Add observations...') }}"></textarea>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Financial summary') }}</h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small">
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">{{ __('Subtotal') }}</span>
                            <span>{{ $fmt($subtotal) }}</span>
                        </li>
                        <li class="d-flex justify-content-between mb-2 text-danger">
                            <span>{{ __('Discount') }}</span>
                            <span>- {{ $fmt($discount) }}</span>
                        </li>
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">{{ __('VAT') }} (16%)</span>
                            <span>{{ $fmt($tax) }}</span>
                        </li>
                        <li class="d-flex justify-content-between mb-3 pt-2 border-top">
                            <span class="fw-bold">{{ __('TOTAL') }}</span>
                            <span class="fw-bold text-primary fs-5">{{ $fmt($total) }}</span>
                        </li>
                        <li class="d-flex justify-content-between mb-3">
                            <span class="text-muted">{{ __('Paid') }}</span>
                            <span class="fw-bold text-success">{{ $fmt($paid) }}</span>
                        </li>
                    </ul>
                    <div class="balance-due-box text-center">
                        <span class="text-muted small d-block">{{ __('Pending balance') }}</span>
                        <span class="fw-bold text-danger fs-4">{{ $fmt($balance) }}</span>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Payment') }}</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">{{ __('Payment method') }}</label>
                        <select class="form-select" id="checkout-payment-method">
                            <option value="mpesa" selected>{{ __('Mobile money') }} (M-Pesa)</option>
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="transfer">{{ __('Transfer') }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">{{ __('Amount received') }}</label>
                        <input type="text" class="form-control" id="checkout-amount-received" value="{{ number_format($balance, 2, '.', '') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">{{ __('Reference / Transaction no.') }}</label>
                        <input type="text" class="form-control" value="MP250512114233" placeholder="{{ __('Reference') }}">
                    </div>
                    <div class="change-box d-flex align-items-center justify-content-between">
                        <div>
                            <i class="ti ti-check text-success me-1"></i>
                            <span class="small fw-semibold">{{ __('Change') }}</span>
                        </div>
                        <span class="fw-bold text-success" id="checkout-change">{{ $fmt(0) }}</span>
                    </div>
                    @if($canManage ?? false)
                        @if($checkOutUrl ?? null)
                            {!! Form::open(['url' => $checkOutUrl, 'method' => 'POST', 'id' => 'checkout-form', 'class' => 'mt-3']) !!}
                            @csrf
                            <button type="submit" class="btn btn-primary w-100" id="btn-complete-checkout"
                                @if($folio && $folio->paymentStatusLabel() !== 'paid' && !($canForceCheckout ?? false)) disabled title="{{ __('Settle room bill before checkout') }}" @endif>
                                <i class="ti ti-logout me-1"></i>{{ __('Complete check-out') }}
                            </button>
                            @if($folio && $folio->paymentStatusLabel() !== 'paid')
                                <p class="small text-muted mt-2 mb-0">
                                    <a href="{{ route('hotel.folios.show', $folio->id) }}">{{ __('Pay room bill') }}</a>
                                    @if($canForceCheckout ?? false)
                                        · {{ __('Or force checkout if permitted') }}
                                    @endif
                                </p>
                            @endif
                            {!! Form::close() !!}
                        @else
                            <p class="text-muted small mt-3 mb-0">{{ __('Guest must be checked in before checkout.') }}</p>
                        @endif
                    @endif
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Quick actions') }}</h6>
                </div>
                <div class="card-body d-grid gap-2">
                    @if($finalBillUrl ?? null)
                    <a href="{{ $finalBillUrl }}" class="btn btn-primary quick-action-btn" target="_blank"><i class="ti ti-file-invoice me-2"></i>{{ __('Final bill') }}</a>
                    @endif
                    <a href="{{ route('hotel.frontdesk.receipt', ['reservation' => $reservation->id, 'type' => 'receipt']) }}" class="btn btn-outline-primary quick-action-btn">
                        <i class="ti ti-receipt me-2"></i>{{ __('Issue receipt') }}
                    </a>
                    <a href="{{ route('hotel.frontdesk.receipt', ['reservation' => $reservation->id, 'type' => 'invoice_receipt']) }}" class="btn btn-outline-primary quick-action-btn">
                        <i class="ti ti-file-text me-2"></i>{{ __('Invoice-receipt') }}
                    </a>
                    <button type="button" class="btn btn-outline-primary quick-action-btn"><i class="ti ti-printer me-2"></i>{{ __('Reprint document') }}</button>
                    @if($reservation->room && ($canManage ?? false))
                    <a href="{{ route('hotel.rooms.consumption', $reservation->room->id) }}" class="btn btn-outline-success quick-action-btn"><i class="ti ti-plus me-2"></i>{{ __('Add consumption') }}</a>
                    @endif
                    <button type="button" class="btn btn-outline-warning quick-action-btn"><i class="ti ti-calendar-plus me-2"></i>{{ __('Extend stay') }}</button>
                    <button type="button" class="btn btn-outline-secondary quick-action-btn" style="border-color:#8b5cf6;color:#7c3aed;"><i class="ti ti-arrows-exchange me-2"></i>{{ __('Change room') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script-page')
<script>
(function () {
    var amountInput = document.getElementById('checkout-amount-received');
    var changeEl = document.getElementById('checkout-change');
    var balanceDue = {{ (float) $balance }};

    function parseAmount(val) {
        var n = parseFloat(String(val).replace(/[^\d.-]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function updateChange() {
        if (!changeEl || !amountInput) return;
        var received = parseAmount(amountInput.value);
        var change = Math.max(0, received - balanceDue);
        changeEl.textContent = change.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    if (amountInput) {
        amountInput.addEventListener('input', updateChange);
        updateChange();
    }

})();
</script>
@endpush
