@php
    $line = $line ?? (object) [];
    $rowId = $rowId ?? ('product-id-r-' . ($line->cart_line_key ?? $line->id ?? '0'));
    $rowClass = $rowClass ?? '';
    $subtotal = (float) ($subtotal ?? 0);
    $taxDisplay = $taxDisplay ?? '-';
    $sessionKeyVal = $sessionKeyVal ?? '';
    $deleteFormId = $deleteFormId ?? ('delete-form-r-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($line->cart_line_key ?? $line->id ?? '0')));
    $isSent = !empty($line->is_sent);
    $statusText = $statusText ?? __('pos.status_not_sent');
    $priceFmt = Auth::user()->priceFormat($line->price ?? 0);
    $subFmt = Auth::user()->priceFormat($subtotal);
    $qty = (int) ($line->quantity ?? 1);
    $unitSubtotal = isset($line->unit_subtotal)
        ? (float) $line->unit_subtotal
        : (float) ($line->price ?? 0);
    $optionsAmount = isset($line->options_amount)
        ? (float) $line->options_amount
        : 0.0;
    if (! isset($line->unit_subtotal) && $qty > 0 && $optionsAmount <= 0) {
        $unitSubtotal = round($subtotal / $qty, 2);
    }
    $cartLineKey = $line->cart_line_key ?? $line->id ?? '';
    $noteText = trim((string) ($line->description ?? ''));
    $hasNote = $noteText !== '';
    $isCancelled = ($line->status ?? '') === 'cancelled' || !empty($line->voided_at);
    $rowVoidClass = $isCancelled ? ' pos-cart-row--voided' : '';
    $lineName = trim((string) ($line->name ?? ''));
    if ($lineName === '') {
        $lineName = __('Product') . ' #' . ($line->id ?? '');
    }
    $selectedOptionsJson = '';
    if (! empty($line->selected_options_json)) {
        $selectedOptionsJson = is_string($line->selected_options_json)
            ? $line->selected_options_json
            : json_encode($line->selected_options_json);
    } elseif (! empty($line->selected_options) && is_array($line->selected_options)) {
        $selectedOptionsJson = json_encode($line->selected_options);
    }
@endphp
<tr class="pos-cart-row-compact {{ $rowClass }}{{ $rowVoidClass }} {{ $isSent ? 'pos-cart-row--sent' : 'pos-cart-row--draft' }}"
    data-product-id="{{ $line->id ?? '' }}"
    data-product-variant-id="{{ (int) ($line->product_variant_id ?? 0) }}"
    data-cart-line-key="{{ $cartLineKey }}"
    data-line-id="{{ $line->line_id ?? '' }}"
    data-is-sent="{{ $isSent ? '1' : '0' }}"
    data-voided="{{ $isCancelled ? '1' : '0' }}"
    data-status="{{ $line->status ?? 'not_sent' }}"
    id="{{ $rowId }}"
    data-name="{{ $lineName }}"
    data-price="{{ $line->price ?? 0 }}"
    data-price-type="{{ $line->price_type ?? 'exclusive' }}"
    data-quantity="{{ $qty }}"
    data-tax="{{ $line->tax ?? 0 }}"
    data-subtotal="{{ $subtotal }}"
    data-unit-subtotal="{{ $unitSubtotal }}"
    data-options-amount="{{ $optionsAmount }}"
    data-discount="0"
    data-print-zone="{{ $line->print_zone ?? '' }}"
    data-printer-id="{{ $line->pos_printer_id ?? '' }}"
    data-printer-name="{{ $line->printer_name ?? '' }}"
    data-warehouse-id="{{ (int) ($line->warehouse_id ?? 0) }}"
    data-selected-options="{{ e($selectedOptionsJson) }}">
    <td class="pos-cart-line-cell p-0 border-0">
        <div class="pos-cart-line">
            <div class="pos-cart-line__row">
                <div class="pos-cart-line__main min-w-0">
                    <span class="pos-cart-line__name name" title="{{ $lineName }}">{{ $lineName }}</span>
                    @if(! empty($line->warehouse_name))
                        <span class="pos-cart-line__warehouse text-muted small d-block">{{ $line->warehouse_name }}</span>
                    @endif
                    <span class="pos-cart-line__badge badge pos-line-status">{{ $statusText }}</span>
                </div>

                @if($isSent)
                    <span class="pos-cart-line__qty-label">{{ $qty }}×</span>
                @else
                    <span class="quantity buttons_added pos-cart-line__qty">
                        <input type="button" value="-" class="minus" aria-label="-">
                        <input type="number" step="1" min="1" name="quantity" class="input-number"
                            data-url="{{ url('update-cart/') }}" data-id="{{ $cartLineKey }}" value="{{ $qty }}">
                        <input type="button" value="+" class="plus" aria-label="+">
                    </span>
                @endif

                <span class="pos-cart-line__total subtotal">{{ $subFmt }}</span>
                <span class="d-none price">{{ $priceFmt }}</span>
                <span class="d-none tax">{{ $taxDisplay }}</span>

                <div class="pos-cart-line__action action-btn">
                    @if($isCancelled)
                        <span class="badge pos-cart-line__void-badge">{{ __('pos.voided') }}</span>
                    @elseif($isSent)
                        @if(!empty($canShowVoid) && !empty($line->line_id))
                            <button type="button" class="btn btn-warning btn-sm pos-void-line pos-cart-line__void-btn"
                                data-line-id="{{ $line->line_id }}" data-name="{{ $lineName }}"
                                title="{{ __('pos.void') }}">{{ __('pos.void') }}</button>
                        @endif
                    @else
                        <a href="#" class="btn btn-danger btn-sm pos-cart-line__del-btn bs-pass-para-pos pos-remove-from-cart"
                            data-confirm="{{ __('Are You Sure?') }}"
                            data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                            data-confirm-yes="{{ $deleteFormId }}"
                            title="{{ __('Delete') }}"
                            data-id="{{ $cartLineKey }}"><i class="ti ti-trash"></i></a>
                        <form method="post" action="{{ url('remove-from-cart') }}" id="{{ $deleteFormId }}" class="d-none">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="session_key" value="pos-restaurant">
                            <input type="hidden" name="id" value="{{ $cartLineKey }}">
                            @if($sessionKeyVal !== '')
                                <input type="hidden" name="cart_key" value="{{ $sessionKeyVal }}">
                            @endif
                        </form>
                    @endif
                </div>
            </div>

            @if($isSent && $hasNote)
                <div class="pos-cart-line__note notes pos-item-note-readonly" title="{{ $noteText }}">{{ $noteText }}</div>
            @elseif(!$isSent)
                <textarea class="form-control form-control-sm notes pos-item-notes pos-cart-line__note-input"
                    placeholder="{{ __('pos.note_placeholder') }}" maxlength="200" rows="1"
                    title="{{ __('Note') }}">{{ $noteText }}</textarea>
            @endif
        </div>
    </td>
</tr>
