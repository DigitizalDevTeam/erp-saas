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
    $guestName = $guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('Guest');
    $guestCode = $guest?->guest_code ?? $guest?->id ?? '—';
    $phone = $guest?->phone ?: '—';
    $email = $guest?->email ?: '—';
    $roomNo = $room?->room_no ?? '—';
    $checkoutNotes = old('checkout_notes', $stay?->notes ?? '');
    $paymentMethods = $paymentMethods ?? \Modules\HotelManagement\Services\HotelFolioPaymentService::paymentMethodOptions();
    $isPaid = $folio && $folio->paymentStatusLabel() === 'paid';
    $canCompleteCheckout = $checkOutUrl && ($isPaid || ($canForceCheckout ?? false));
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
    $taxInvoiceUrl = $taxInvoiceUrl ?? ($folio && $folio->invoice_id ? route('hotel.folios.tax-invoice', $folio->id) : null);
    $reprintUrl = $taxInvoiceUrl ?? $finalBillUrl;
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
                                    <th>{{ __('Service date') }}</th>
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
                                        <td class="small text-muted">{{ $line['service_date'] ?? '—' }}</td>
                                        <td class="text-center">{{ $line['qty'] }}</td>
                                        <td class="text-end fw-semibold">{{ $fmt($line['amount']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-muted text-center py-3">{{ __('No folio charges yet. Room bill is created at check-in.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if(($consumptionAuditLogs ?? collect())->isNotEmpty())
                    <div class="p-3 border-top">
                        <h6 class="small fw-semibold mb-2">{{ __('Consumption audit trail') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Description') }}</th><th>{{ __('Qty') }}</th><th class="text-end">{{ __('Amount') }}</th></tr></thead>
                                <tbody>
                                @foreach($consumptionAuditLogs as $log)
                                    <tr>
                                        <td class="small">{{ $log->service_date?->format('Y-m-d') ?? '—' }}</td>
                                        <td class="small">{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $log->charge_type))) }}</td>
                                        <td class="small">{{ $log->description }}</td>
                                        <td class="small">{{ $log->quantity }}</td>
                                        <td class="small text-end">{{ $fmt($log->line_total) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif
                    @if(($canManage ?? false) && $reservation->room)
                    <div class="p-3 border-top text-end d-flex flex-wrap gap-2 justify-content-end">
                        @if(($requireMinibarScan ?? false) && !($minibarVerified ?? false) && $stay)
                        {!! Form::open(['route' => ['hotel.frontdesk.verify-minibar', $stay->id], 'method' => 'POST', 'class' => 'd-inline']) !!}
                        <button type="submit" class="btn btn-sm btn-warning"><i class="ti ti-fridge me-1"></i>{{ __('Record minibar scan') }}</button>
                        {!! Form::close() !!}
                        @elseif($minibarVerified ?? false)
                        <span class="badge bg-success align-self-center me-2"><i class="ti ti-check me-1"></i>{{ __('Minibar scanned') }}</span>
                        @endif
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
                    <textarea class="form-control" name="checkout_notes" id="checkout-notes" rows="4" placeholder="{{ __('Add observations...') }}" form="checkout-form" {{ !($canManage ?? false) ? 'disabled' : '' }}>{{ $checkoutNotes }}</textarea>
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

            @if(($requireMinibarScan ?? false) && !($minibarVerified ?? false))
            <div class="alert alert-warning small mb-3">{{ __('Record minibar scan before completing checkout.') }}</div>
            @endif

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Payment') }}</h6>
                </div>
                <div class="card-body">
                    @if($canManage ?? false)
                        @if($checkOutUrl ?? null)
                            {!! Form::open(['url' => $checkOutUrl, 'method' => 'POST', 'id' => 'checkout-form']) !!}
                            @csrf
                            @if(!$isPaid && ($balance ?? 0) > 0)
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">{{ __('Payment method') }}</label>
                                    <select class="form-select" name="payment_method" id="checkout-payment-method">
                                        @foreach($paymentMethods as $methodKey => $methodLabel)
                                            <option value="{{ $methodKey }}" @selected(old('payment_method', 'cash') === $methodKey)>{{ $methodLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">{{ __('Amount received') }}</label>
                                    <input type="number" step="0.01" min="0" class="form-control" name="payment_amount" id="checkout-amount-received" value="{{ old('payment_amount', number_format($balance, 2, '.', '')) }}">
                                    <div class="form-text">{{ __('Pending balance') }}: {{ $fmt($balance) }}</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">{{ __('Reference / Transaction no.') }}</label>
                                    <input type="text" class="form-control" name="payment_reference" value="{{ old('payment_reference') }}" placeholder="{{ __('Reference') }}">
                                </div>
                                <div class="change-box d-flex align-items-center justify-content-between mb-3">
                                    <div>
                                        <i class="ti ti-check text-success me-1"></i>
                                        <span class="small fw-semibold">{{ __('Change') }}</span>
                                    </div>
                                    <span class="fw-bold text-success" id="checkout-change">{{ $fmt(0) }}</span>
                                </div>
                            @else
                                <input type="hidden" name="payment_amount" value="0">
                                <p class="text-success small mb-3"><i class="ti ti-check me-1"></i>{{ __('Paid on folio') }}</p>
                            @endif
                            @if(($canForceCheckout ?? false) && !$isPaid && ($balance ?? 0) > 0)
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="force_checkout" value="1" id="force-checkout" @checked(old('force_checkout'))>
                                    <label class="form-check-label small" for="force-checkout">{{ __('Or force checkout if permitted') }}</label>
                                </div>
                            @endif
                            <button type="submit" class="btn btn-primary w-100" id="btn-complete-checkout"
                                @if((($requireMinibarScan ?? false) && !($minibarVerified ?? false)) || (!$canCompleteCheckout && ($balance ?? 0) > 0)) disabled @endif
                                @if(($requireMinibarScan ?? false) && !($minibarVerified ?? false)) title="{{ __('Record minibar scan before completing checkout.') }}" @elseif(!$canCompleteCheckout && ($balance ?? 0) > 0) title="{{ __('Settle room bill before checkout') }}" @endif>
                                <i class="ti ti-logout me-1"></i>{{ __('Complete check-out') }}
                            </button>
                            @if($folio && !$isPaid)
                                <p class="small text-muted mt-2 mb-0">
                                    <a href="{{ route('hotel.frontdesk.receipt', ['reservation' => $reservation->id, 'type' => 'receipt']) }}">{{ __('Register payment') }}</a>
                                    · <a href="{{ route('hotel.folios.show', $folio->id) }}">{{ __('Room bill') }}</a>
                                </p>
                            @endif
                            {!! Form::close() !!}
                        @else
                            <p class="text-muted small mb-0">{{ __('Guest must be checked in before checkout.') }}</p>
                        @endif
                    @else
                        <p class="text-muted small mb-0">{{ __('View only') }}</p>
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
                    @if($taxInvoiceUrl ?? null)
                    <a href="{{ $taxInvoiceUrl }}" class="btn btn-outline-primary quick-action-btn" target="_blank">
                        <i class="ti ti-file-text me-2"></i>{{ __('Tax invoice PDF') }}
                    </a>
                    @else
                    <a href="{{ route('hotel.frontdesk.receipt', ['reservation' => $reservation->id, 'type' => 'invoice_receipt']) }}" class="btn btn-outline-primary quick-action-btn">
                        <i class="ti ti-file-text me-2"></i>{{ __('Invoice-receipt') }}
                    </a>
                    @endif
                    @if($reprintUrl ?? null)
                    <a href="{{ $reprintUrl }}" class="btn btn-outline-primary quick-action-btn" target="_blank"><i class="ti ti-printer me-2"></i>{{ $taxInvoiceUrl ? __('Tax invoice PDF') : __('Reprint document') }}</a>
                    @endif
                    @if($reservation->room && ($canManage ?? false))
                    <a href="{{ route('hotel.rooms.consumption', $reservation->room->id) }}" class="btn btn-outline-success quick-action-btn"><i class="ti ti-plus me-2"></i>{{ __('Add consumption') }}</a>
                    @endif
                    @if(($canManage ?? false) && $reservation->status === \Modules\HotelManagement\Entities\HotelReservation::STATUS_CHECKED_IN)
                    <button type="button" class="btn btn-outline-warning quick-action-btn" data-bs-toggle="modal" data-bs-target="#extendStayModal"><i class="ti ti-calendar-plus me-2"></i>{{ __('Extend stay') }}</button>
                    <button type="button" class="btn btn-outline-secondary quick-action-btn" style="border-color:#8b5cf6;color:#7c3aed;" data-bs-toggle="modal" data-bs-target="#changeRoomModal"><i class="ti ti-arrows-exchange me-2"></i>{{ __('Change room') }}</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@if(($canManage ?? false) && $reservation->status === \Modules\HotelManagement\Entities\HotelReservation::STATUS_CHECKED_IN)
<div class="modal fade" id="extendStayModal" tabindex="-1" aria-labelledby="extendStayModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {!! Form::open(['route' => ['hotel.frontdesk.extend-stay', $reservation->id], 'method' => 'POST']) !!}
            <div class="modal-header">
                <h5 class="modal-title" id="extendStayModalLabel">{{ __('Extend stay') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">{{ __('Current check-out') }}: <strong>{{ $reservation->check_out_date }}</strong></p>
                {!! Form::label('check_out_at', __('New check-out date & time')) !!}
                {!! Form::datetimeLocal('check_out_at', \Carbon\Carbon::parse($reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'))->addDay()->format('Y-m-d\TH:i'), ['class' => 'form-control', 'required']) !!}
                <p class="small text-muted mt-2 mb-0">{{ __('Additional room nights will be posted to the room bill.') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-warning">{{ __('Extend stay') }}</button>
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>

<div class="modal fade" id="changeRoomModal" tabindex="-1" aria-labelledby="changeRoomModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {!! Form::open(['route' => ['hotel.frontdesk.change-room', $reservation->id], 'method' => 'POST']) !!}
            <div class="modal-header">
                <h5 class="modal-title" id="changeRoomModalLabel">{{ __('Change room') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">{{ __('Current room') }}: <strong>{{ $reservation->room->room_no ?? '—' }}</strong></p>
                {!! Form::label('hotel_room_id', __('New room')) !!}
                <select name="hotel_room_id" id="hotel_room_id" class="form-control" required>
                    <option value="">{{ __('Select room') }}</option>
                    @foreach($availableRooms ?? [] as $room)
                        <option value="{{ $room->id }}">
                            {{ $room->room_no }} — {{ $room->roomType->name ?? '' }}
                            @if($room->floor) ({{ $room->floor->name }}) @endif
                        </option>
                    @endforeach
                </select>
                @if(($availableRooms ?? collect())->isEmpty())
                    <p class="small text-danger mt-2 mb-0">{{ __('No available rooms for the remaining stay.') }}</p>
                @else
                    <p class="small text-muted mt-2 mb-0">{{ __('Rate difference for remaining nights will be posted to the room bill.') }}</p>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" @if(($availableRooms ?? collect())->isEmpty()) disabled @endif>{{ __('Change room') }}</button>
            </div>
            {!! Form::close() !!}
        </div>
    </div>
</div>
@endif
@endsection

@push('script-page')
<script>
(function () {
    var amountInput = document.getElementById('checkout-amount-received');
    var changeEl = document.getElementById('checkout-change');
    var forceCheckout = document.getElementById('force-checkout');
    var completeBtn = document.getElementById('btn-complete-checkout');
    var balanceDue = {{ (float) $balance }};
    var isPaid = @json($isPaid ?? false);

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

    function updateCheckoutButton() {
        if (!completeBtn || isPaid) return;
        var force = forceCheckout && forceCheckout.checked;
        var received = amountInput ? parseAmount(amountInput.value) : 0;
        var settled = balanceDue <= 0.01 || received >= balanceDue - 0.01;
        completeBtn.disabled = !settled && !force;
    }

    if (amountInput) {
        amountInput.addEventListener('input', function () {
            updateChange();
            updateCheckoutButton();
        });
        updateChange();
    }
    if (forceCheckout) {
        forceCheckout.addEventListener('change', updateCheckoutButton);
    }
    updateCheckoutButton();

})();
</script>
@endpush
