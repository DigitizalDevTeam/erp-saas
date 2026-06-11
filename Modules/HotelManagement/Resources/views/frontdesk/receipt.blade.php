@extends('layouts.admin')
@section('page-title'){{ $pageTitle }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.in-house') }}">{{ __('Frontdesk') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.frontdesk.checkout', $reservation->id) }}">{{ __('Check-out') }}</a></li>
    <li class="breadcrumb-item">{{ $pageTitle }}</li>
@endsection

@push('css-page')
<style>
    .hotel-receipt .info-chip {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.45rem 0.75rem;
        text-align: center;
        min-width: 88px;
    }
    .hotel-receipt .info-chip .label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: #64748b;
    }
    .hotel-receipt .info-chip .value {
        font-weight: 700;
        color: #2563eb;
        font-size: 0.9rem;
    }
    .hotel-receipt .info-bar {
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.5rem;
    }
    .hotel-receipt .guest-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: #64748b;
    }
    .hotel-receipt .balance-hint {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.35);
        border-radius: 8px;
        padding: 0.75rem 1rem;
    }
    .hotel-receipt .payment-method-card {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 0.5rem;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
        height: 100%;
    }
    .hotel-receipt .payment-method-card:hover { border-color: #94a3b8; }
    .hotel-receipt .payment-method-card.is-active {
        border-color: var(--bs-primary);
        background: rgba(var(--bs-primary-rgb), 0.06);
    }
    .hotel-receipt .payment-method-card i { font-size: 1.35rem; display: block; margin-bottom: 0.25rem; }
    .hotel-receipt .payment-method-card span { font-size: 0.75rem; font-weight: 600; }
    .hotel-receipt .receipt-preview-wrap .form-preview-label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #10b981;
        margin-bottom: 0.35rem;
        display: block;
    }
    .hotel-receipt .receipt-preview {
        position: relative;
        border: 1px solid #a7e3cc;
        border-radius: 10px;
        background: #e8faf4;
        padding: 1.5rem 1.25rem 1.25rem;
        font-size: 0.82rem;
        color: #1e293b;
        min-height: 220px;
    }
    .hotel-receipt .receipt-preview .preview-corner-label {
        position: absolute;
        top: 10px;
        left: 14px;
        font-size: 0.7rem;
        font-weight: 600;
        color: #10b981;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .hotel-receipt .receipt-preview .receipt-preview-header {
        text-align: center;
        padding: 0.75rem 0 1rem;
        margin-top: 0.25rem;
    }
    .hotel-receipt .receipt-preview .hotel-name {
        font-weight: 800;
        font-size: 1rem;
        letter-spacing: 0.06em;
        color: #0f172a;
        text-transform: uppercase;
        margin-bottom: 0.35rem;
    }
    .hotel-receipt .receipt-preview .doc-title {
        font-weight: 800;
        font-size: 0.8rem;
        letter-spacing: 0.04em;
        color: #0f172a;
        text-transform: uppercase;
        margin-bottom: 0;
    }
    .hotel-receipt .receipt-preview .receipt-preview-body {
        margin-bottom: 1rem;
    }
    .hotel-receipt .receipt-preview .receipt-preview-body p {
        margin-bottom: 0.45rem;
        line-height: 1.45;
    }
    .hotel-receipt .receipt-preview .receipt-preview-body .line-label {
        color: #334155;
        font-weight: 500;
    }
    .hotel-receipt .receipt-preview .receipt-preview-body .line-value {
        font-weight: 500;
        color: #334155;
    }
    .hotel-receipt .receipt-preview .receipt-preview-body .line-value-amount {
        font-weight: 600;
        color: #334155;
    }
    .hotel-receipt .receipt-preview .receipt-preview-footer {
        text-align: center;
        font-weight: 700;
        font-size: 0.85rem;
        color: #0f172a;
        padding-top: 0.5rem;
        border-top: 1px dashed rgba(16, 185, 129, 0.35);
        margin-top: 0.25rem;
    }
    .hotel-receipt .history-item {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.65rem 0.75rem;
        margin-bottom: 0.5rem;
    }
    .hotel-receipt .summary-total { color: #2563eb; }
    .hotel-receipt .summary-paid { color: #10b981; }
    .hotel-receipt .summary-balance { color: #2563eb; font-size: 1.1rem; }
</style>
@endpush

@section('content')
@php
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
    $guest = $reservation->guest;
    $room = $reservation->room;
    $folio = $folio ?? $reservation->stay?->folio;
    $guestName = $guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('Guest');
    $guestCode = $guest?->guest_code ?? $guest?->id ?? '—';
    $reservationNo = $reservation->reservation_no ?? ('R' . str_pad((string) $reservation->id, 6, '0', STR_PAD_LEFT));
    $roomNo = $room?->room_no ?? '—';
    $checkInDisplay = \Carbon\Carbon::parse($reservation->check_in_date)->format('d/m/Y');
    $checkOutDisplay = \Carbon\Carbon::parse($reservation->check_out_date)->format('d/m/Y');
    $nights = max(1, \Carbon\Carbon::parse($reservation->check_in_date)->diffInDays(\Carbon\Carbon::parse($reservation->check_out_date)));
    $phone = $guest?->phone ?: '—';
    $email = $guest?->email ?: '—';
    $companyTitle = \App\Models\Utility::companyData(Auth::user()->creatorId(), 'title_text') ?: config('app.name');
    $subtotal = $folio ? (float) $folio->sub_total : 0;
    $taxTotal = $folio ? (float) $folio->tax_total : 0;
    $discountTotal = $folio ? (float) $folio->discount_total : 0;
    $servicesTotal = $folio ? max(0, (float) $folio->grand_total - $subtotal) : 0;
    $totalReservation = $folio ? (float) $folio->grand_total : 0;
    $advancesReceived = $folio ? (float) $folio->paid_total : 0;
    $pendingBalance = max(0, $totalReservation - $advancesReceived);
    $advanceAmount = old('amount', $pendingBalance > 0 ? $pendingBalance : 0);
    $balanceAfter = max(0, $pendingBalance - (float) $advanceAmount);
    $docType = $documentType ?? 'receipt';
    $receiptNo = 'RA' . now()->format('ymd') . '-' . str_pad((string) ($folio?->payments?->count() + 1 ?? 1), 4, '0', STR_PAD_LEFT);
    $previewDate = now()->format('d/m/Y H:i');
    $defaultDocTitle = $docType === 'invoice_receipt'
        ? strtoupper(__('Invoice-receipt'))
        : ($docType === 'receipt' ? strtoupper(__('Payment receipt')) : strtoupper(__('Advance receipt')));
    $paymentMethods = $paymentMethods ?? \Modules\HotelManagement\Services\HotelFolioPaymentService::paymentMethodOptions();
    $paymentHistory = $folio?->payments?->sortByDesc('id') ?? collect();
    $canManage = $canManage ?? false;
@endphp

@if($canManage && $folio)
{!! Form::open(['route' => ['hotel.frontdesk.receipt.store', $reservation->id], 'method' => 'POST', 'id' => 'receipt-payment-form']) !!}
@csrf
<input type="hidden" name="document_type" value="{{ $docType }}">
<input type="hidden" name="method" id="receipt-payment-method" value="{{ old('method', 'cash') }}">
@endif

<div class="hotel-receipt">
    <div class="info-bar mb-3">
        <div class="info-chip">
            <div class="label">{{ __('Reservation') }}</div>
            <div class="value">{{ $reservationNo }}</div>
        </div>
        <div class="info-chip">
            <div class="label">{{ __('Guest') }}</div>
            <div class="value text-truncate" style="max-width:120px;">{{ $guestName }}</div>
        </div>
        <div class="info-chip">
            <div class="label">{{ __('Room') }}</div>
            <div class="value">{{ $roomNo }}</div>
        </div>
        <div class="info-chip">
            <div class="label">{{ __('Check-in') }}</div>
            <div class="value">{{ $checkInDisplay }}</div>
        </div>
        <div class="info-chip">
            <div class="label">{{ __('Check-out') }}</div>
            <div class="value">{{ $checkOutDisplay }}</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Guest / reservation data') }}</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3 flex-wrap mb-3">
                        <div class="guest-avatar"><i class="ti ti-user"></i></div>
                        <div>
                            <h5 class="mb-0">{{ $guestName }}</h5>
                            <p class="text-muted small mb-0">{{ __('Guest') }}: {{ $guestCode }}</p>
                        </div>
                    </div>
                    <div class="row g-2 small mb-3">
                        <div class="col-4"><span class="text-muted d-block">{{ __('Check-in') }}</span><strong>{{ $checkInDisplay }}</strong></div>
                        <div class="col-4"><span class="text-muted d-block">{{ __('Check-out') }}</span><strong>{{ $checkOutDisplay }}</strong></div>
                        <div class="col-4"><span class="text-muted d-block">{{ __('Nights') }}</span><strong>{{ $nights }}</strong></div>
                    </div>
                    <p class="small mb-1"><i class="ti ti-phone me-1 text-primary"></i>{{ $phone }}</p>
                    <p class="small mb-3"><i class="ti ti-mail me-1 text-primary"></i>{{ $email }}</p>
                    <div class="row g-2 small">
                        <div class="col-4"><span class="text-muted">{{ __('Adults') }}</span> <strong>{{ $reservation->adults ?? 2 }}</strong></div>
                        <div class="col-4"><span class="text-muted">{{ __('Children') }}</span> <strong>{{ $reservation->children ?? 0 }}</strong></div>
                        <div class="col-4"><span class="text-muted">{{ __('Agency') }}</span> <strong>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $reservation->source ?? 'individual'))) }}</strong></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Register payment / advance') }}</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">{{ __('Payment amount') }}</label>
                            <input type="number" step="0.01" min="0.01" class="form-control form-control-lg" name="amount" id="receipt-amount" value="{{ is_numeric($advanceAmount) ? number_format((float) $advanceAmount, 2, '.', '') : $advanceAmount }}" @disabled(!$canManage || !$folio)>
                            <div class="balance-hint mt-2 small">
                                <i class="ti ti-info-circle text-success me-1"></i>
                                {{ __('Pending balance after this payment') }}:
                                <strong class="text-success" id="balance-after-hint">{{ $fmt($balanceAfter) }}</strong>
                            </div>
                            <label class="form-label small fw-semibold mt-3">{{ __('Payment method') }}</label>
                            <div class="row g-2">
                                @php
                                    $methodCards = [
                                        ['id' => 'cash', 'icon' => 'ti-cash', 'label' => $paymentMethods['cash'] ?? __('Cash')],
                                        ['id' => 'mpesa', 'icon' => 'ti-device-mobile', 'label' => $paymentMethods['mpesa'] ?? 'M-Pesa'],
                                        ['id' => 'card', 'icon' => 'ti-credit-card', 'label' => $paymentMethods['card'] ?? __('Card')],
                                        ['id' => 'bank_transfer', 'icon' => 'ti-building-bank', 'label' => $paymentMethods['bank_transfer'] ?? __('Transfer')],
                                        ['id' => 'e_mola', 'icon' => 'ti-device-mobile', 'label' => $paymentMethods['e_mola'] ?? 'e-Mola'],
                                        ['id' => 'mkesh', 'icon' => 'ti-device-mobile', 'label' => $paymentMethods['mkesh'] ?? 'Mkesh'],
                                    ];
                                    $selectedMethod = old('method', 'cash');
                                @endphp
                                @foreach($methodCards as $pm)
                                    <div class="col-4 col-sm-4">
                                        <div class="payment-method-card {{ $selectedMethod === $pm['id'] ? 'is-active' : '' }}" data-method="{{ $pm['id'] }}" @if(!$canManage || !$folio) style="pointer-events:none;opacity:.6" @endif>
                                            <i class="ti {{ $pm['icon'] }} text-primary"></i>
                                            <span>{{ $pm['label'] }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">{{ __('Reference / transaction no.') }} <span class="text-muted">({{ __('optional') }})</span></label>
                            <input type="text" class="form-control" name="reference" value="{{ old('reference') }}" placeholder="{{ __('Reference') }}" @disabled(!$canManage || !$folio)>
                            <label class="form-label small fw-semibold mt-3">{{ __('Payment date') }}</label>
                            <input type="datetime-local" class="form-control" id="receipt-payment-date" value="{{ now()->format('Y-m-d\TH:i') }}" disabled>
                            <label class="form-label small fw-semibold mt-3">{{ __('Notes') }} <span class="text-muted">({{ __('optional') }})</span></label>
                            <textarea class="form-control" name="notes" rows="4" placeholder="{{ __('Add notes...') }}" @disabled(!$canManage || !$folio)>{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Document to be issued') }}</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">{{ __('Document type') }}</label>
                            <select class="form-select" id="receipt-doc-type">
                                <option value="advance" {{ ! in_array($docType, ['receipt', 'invoice_receipt'], true) ? 'selected' : '' }}>{{ __('Advance receipt') }}</option>
                                <option value="payment" {{ $docType === 'receipt' ? 'selected' : '' }}>{{ __('Payment receipt') }}</option>
                                <option value="invoice_receipt" {{ $docType === 'invoice_receipt' ? 'selected' : '' }}>{{ __('Invoice-receipt') }}</option>
                            </select>
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="send-email" checked>
                                <label class="form-check-label small" for="send-email">{{ __('Send by email / WhatsApp') }}</label>
                            </div>
                        </div>
                        <div class="col-md-7 receipt-preview-wrap">
                            <div class="receipt-preview" id="receipt-preview">
                                <span class="preview-corner-label">{{ __('Preview') }}</span>
                                <div class="receipt-preview-header">
                                    <div class="hotel-name">{{ strtoupper($companyTitle) }}</div>
                                    <div class="doc-title" id="preview-doc-title">{{ $defaultDocTitle }}</div>
                                </div>
                                <div class="row receipt-preview-body g-2">
                                    <div class="col-sm-6">
                                        <p class="mb-0">
                                            <span class="line-label">{{ __('Receipt no.') }}:</span>
                                            <span class="line-value" id="preview-receipt-no">{{ $receiptNo }}</span>
                                        </p>
                                        <p class="mb-0">
                                            <span class="line-label">{{ __('Guest') }}:</span>
                                            <span class="line-value" id="preview-guest">{{ $guestName }}</span>
                                        </p>
                                        <p class="mb-0">
                                            <span class="line-label">{{ __('Reservation') }}:</span>
                                            <span class="line-value">{{ $reservationNo }}</span>
                                            <span class="line-label"> | {{ __('Room') }}:</span>
                                            <span class="line-value">{{ $roomNo }}</span>
                                        </p>
                                    </div>
                                    <div class="col-sm-6">
                                        <p class="mb-0">
                                            <span class="line-label">{{ __('Amount received') }}:</span>
                                            <span class="line-value-amount" id="preview-amount">{{ $fmt($advanceAmount) }}</span>
                                        </p>
                                        <p class="mb-0">
                                            <span class="line-label">{{ __('Date') }}:</span>
                                            <span class="line-value" id="preview-date">{{ $previewDate }}</span>
                                        </p>
                                        <p class="mb-0">
                                            <span class="line-label">{{ __('Payment method') }}:</span>
                                            <span class="line-value" id="preview-method">{{ __('Cash') }}</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="receipt-preview-footer">
                                    {{ __('Thank you for your preference!') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Reservation summary') }}</h6>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Room charges') }}</span><span>{{ $fmt($subtotal) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Additional services') }}</span><span>{{ $fmt($servicesTotal) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Discount') }}</span><span>- {{ $fmt($discountTotal) }}</span></div>
                    <div class="d-flex justify-content-between mb-3 pb-2 border-bottom"><span class="text-muted">{{ __('Taxes') }}</span><span>{{ $fmt($taxTotal) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="fw-bold">{{ __('TOTAL RESERVATION') }}</span><span class="fw-bold summary-total">{{ $fmt($totalReservation) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ __('Payments received') }}</span><span class="fw-bold summary-paid">{{ $fmt($advancesReceived) }}</span></div>
                    <div class="d-flex justify-content-between pt-2 border-top"><span class="fw-bold">{{ __('PENDING BALANCE') }}</span><span class="fw-bold summary-balance">{{ $fmt($pendingBalance) }}</span></div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Payment history') }}</h6>
                </div>
                <div class="card-body p-2">
                    @forelse($paymentHistory as $h)
                        @php
                            $methodLabel = $paymentMethods[$h->method] ?? ucfirst($h->method);
                            $receiptUrl = route('hotel.folios.payments.receipt', [$folio->id, $h->id]);
                        @endphp
                        <div class="history-item small">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>{{ __('Payment') }}</strong>
                                    <span class="text-muted d-block">{{ $h->created_at?->format('d/m/Y H:i') }} · {{ $methodLabel }}</span>
                                    <span class="text-muted">{{ __('By') }}: {{ $h->creator?->name ?? __('User') }}</span>
                                </div>
                                <div class="text-end">
                                    <span class="fw-bold text-success">{{ $fmt($h->amount) }}</span>
                                    <a href="{{ $receiptUrl }}" class="badge bg-success text-decoration-none d-block mt-1" target="_blank">{{ __('Receipt') }}</a>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0 px-2">{{ __('No payments recorded yet.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">{{ __('Quick actions') }}</h6>
                </div>
                <div class="card-body d-grid gap-2">
                    @if($folio && $paymentHistory->isNotEmpty())
                        <a href="{{ route('hotel.folios.payments.receipt', [$folio->id, $paymentHistory->first()->id]) }}" class="btn btn-outline-primary btn-sm text-start" target="_blank"><i class="ti ti-receipt me-2"></i>{{ __('Issue receipt') }}</a>
                    @endif
                    @if($folio)
                        <a href="{{ route('hotel.folios.final-bill', $folio->id) }}" class="btn btn-outline-primary btn-sm text-start" target="_blank"><i class="ti ti-file-invoice me-2"></i>{{ __('Final bill') }}</a>
                        @if($taxInvoiceUrl ?? null)
                        <a href="{{ $taxInvoiceUrl }}" class="btn btn-outline-success btn-sm text-start" target="_blank"><i class="ti ti-file-text me-2"></i>{{ __('Tax invoice PDF') }}</a>
                        @endif
                    @endif
                    <a href="{{ route('hotel.reservations.edit', $reservation->id) }}" class="btn btn-outline-secondary btn-sm text-start"><i class="ti ti-calendar me-2"></i>{{ __('View reservation') }}</a>
                    @if($reservation->stay?->folio)
                        <a href="{{ route('hotel.folios.show', $reservation->stay->folio->id) }}" class="btn btn-outline-secondary btn-sm text-start"><i class="ti ti-wallet me-2"></i>{{ __('View account') }}</a>
                    @endif
                    <a href="{{ route('hotel.frontdesk.checkout', $reservation->id) }}" class="btn btn-outline-warning btn-sm text-start"><i class="ti ti-logout me-2"></i>{{ __('Back to check-out') }}</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mt-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <a href="{{ route('hotel.frontdesk.checkout', $reservation->id) }}" class="btn btn-outline-danger w-100">
                        <i class="ti ti-x me-1"></i>{{ __('Cancel') }}
                    </a>
                </div>
                <div class="col-md-9">
                    @if($canManage && $folio)
                        <button type="submit" class="btn btn-primary w-100 btn-lg" id="btn-confirm-receipt" name="print_receipt" value="0">
                            <i class="ti ti-check me-1"></i>{{ __('Confirm receipt') }}
                        </button>
                        <button type="submit" class="btn btn-outline-primary w-100 mt-2" name="print_receipt" value="1">
                            <i class="ti ti-printer me-1"></i>{{ __('Issue receipt') }}
                        </button>
                    @else
                        <p class="text-muted small mb-0">{{ $folio ? __('View only') : __('No folio charges yet. Room bill is created at check-in.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@if($canManage && $folio)
{!! Form::close() !!}
@endif
@endsection

@push('script-page')
<script>
(function () {
    var pendingBalance = {{ (float) $pendingBalance }};
    var methodInput = document.getElementById('receipt-payment-method');
    var amountInput = document.getElementById('receipt-amount');
    var balanceHint = document.getElementById('balance-after-hint');
    var previewAmount = document.getElementById('preview-amount');
    var previewMethod = document.getElementById('preview-method');
    var docType = document.getElementById('receipt-doc-type');
    var previewTitle = document.getElementById('preview-doc-title');

    var docTitles = {
        advance: @json(strtoupper(__('Advance receipt'))),
        payment: @json(strtoupper(__('Payment receipt'))),
        invoice_receipt: @json(strtoupper(__('Invoice-receipt')))
    };
    var previewDateInput = document.getElementById('receipt-payment-date');
    var previewDateEl = document.getElementById('preview-date');
    var formattedAmountTemplate = @json($fmt($advanceAmount));

    var methodLabels = @json($paymentMethods);

    function parseAmount(val) {
        var n = parseFloat(String(val).replace(/[^\d.-]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function formatPreviewAmount(num) {
        var parts = num.toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var symbol = formattedAmountTemplate.replace(/[\d,.\s]/g, '').trim() || '';
        return parts.join('.') + (symbol ? ' ' + symbol : '');
    }

    function formatPreviewDate(isoVal) {
        if (!isoVal) return '';
        var d = new Date(isoVal);
        if (isNaN(d.getTime())) return isoVal;
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function updateAmounts() {
        var amt = parseAmount(amountInput ? amountInput.value : 0);
        var after = Math.max(0, pendingBalance - amt);
        if (balanceHint) balanceHint.textContent = after.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (previewAmount) previewAmount.textContent = formatPreviewAmount(amt);
    }

    if (amountInput) {
        amountInput.addEventListener('input', updateAmounts);
    }

    if (previewDateInput && previewDateEl) {
        previewDateInput.addEventListener('change', function () {
            previewDateEl.textContent = formatPreviewDate(previewDateInput.value);
        });
        previewDateInput.addEventListener('input', function () {
            previewDateEl.textContent = formatPreviewDate(previewDateInput.value);
        });
    }

    document.querySelectorAll('.payment-method-card').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.payment-method-card').forEach(function (c) { c.classList.remove('is-active'); });
            card.classList.add('is-active');
            var m = card.getAttribute('data-method');
            if (methodInput) methodInput.value = m;
            if (previewMethod && methodLabels[m]) previewMethod.textContent = methodLabels[m];
        });
    });

    if (docType && previewTitle) {
        docType.addEventListener('change', function () {
            previewTitle.textContent = docTitles[docType.value] || docTitles.payment;
        });
    }

    updateAmounts();
})();
</script>
@endpush
