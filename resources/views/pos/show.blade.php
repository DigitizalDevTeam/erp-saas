@php
    $orderSummary = $orderSummary ?? [];
    $totalRaw = $orderSummary['total_raw'] ?? 0;
    $itemsCount = $orderSummary['items_count'] ?? 0;
    $subtotalFmt = $orderSummary['subtotal'] ?? '0';
    $taxFmt = $orderSummary['tax'] ?? '0';
    $discountFmt = $orderSummary['discount'] ?? '0';
    $totalFmt = $orderSummary['total'] ?? '0';
@endphp

@if (!empty($sales) && count($sales) > 0)
    <div class="card border-0 shadow-sm pos-payment-modal-content">
        <div class="card-body p-3">
            <h6 class="card-title mb-2">{{ __('Process Payment') }}</h6>

            {{-- Order Summary (no full invoice) --}}
            <div class="card bg-light mb-2">
                <div class="card-body py-2 px-3 small">
                    <h6 class="card-subtitle mb-2 text-muted small">{{ __('Order Summary') }}</h6>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ __('Items') }} ({{ $itemsCount }}):</span>
                        <span>{{ $itemsCount }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ __('pos.sub_total') }}:</span>
                        <span>{{ $subtotalFmt }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ __('Tax') }}:</span>
                        <span>{{ $taxFmt }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ __('pos.discount') }}:</span>
                        <span>{{ $discountFmt }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ __('pos.total') }}:</span>
                        <strong>{{ $totalFmt }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1" id="order-summary-tip-row">
                        <span>{{ __('Tip') }}:</span>
                        <span id="order-summary-tip-display">{{ Auth::user()->priceFormat(0) }}</span>
                    </div>
                </div>
            </div>

            @php $canGivePosCredit = $canGivePosCredit ?? false; $posCreditTermOptions = $posCreditTermOptions ?? []; @endphp
            @if (($details['pay'] ?? '') == 'show')
                <div class="mb-2">
                    <label class="form-label small mb-1">{{ __('Payment Method') }}</label>
                    <select class="form-select form-select-sm" id="payment_method" name="payment_method">
                        <option value="cash">{{ __('pos.payment_cash') }}</option>
                        <option value="card">{{ __('pos.payment_card') }}</option>
                        @if($canGivePosCredit)<option value="credit">{{ __('pos.payment_credit') }}</option>@endif
                        <option value="mixed">{{ __('Mixed Payment') }}</option>
                        <option value="visa">{{ __('pos.payment_visa') }}</option>
                        <option value="mpesa">{{ __('pos.payment_mpesa') }}</option>
                        <option value="bank_transfer">{{ __('pos.payment_bank_transfer') }}</option>
                        <option value="cheque">{{ __('pos.payment_cheque') }}</option>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small mb-1">{{ __('Document Type') }}</label>
                    <select class="form-select form-select-sm" id="document_type" name="document_type">
                        <option value="cash_sale" selected>{{ __('Cash Sale') }}</option>
                        <option value="credit_sale">{{ __('Credit Sale') }}</option>
                        <option value="internal_consumption">{{ __('Internal Consumption') }}</option>
                    </select>
                </div>

                {{-- Single payment: amount / cash received --}}
                <div class="mb-2" id="single_payment_wrap">
                    <label class="form-label small mb-1" id="payment_amount_label">{{ __('pos.cash_received') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="payment_amount" value="{{ $totalRaw }}" placeholder="{{ __('pos.enter_amount') }}">
                </div>
                <div class="mb-2 d-none" id="change_wrap">
                    <div class="alert alert-success mb-0 py-1 small">
                        <strong>{{ __('Change') }}:</strong> <span id="change_display">0</span>
                    </div>
                    <input type="hidden" id="payment_change" name="change_amount" value="0">
                </div>

                {{-- Mixed payment: multiple rows --}}
                <div class="mb-2 d-none" id="mixed_payment_wrap">
                    <label class="form-label small mb-1">{{ __('Mixed Payment') }}</label>
                    <div id="mixed_payment_rows"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="mixed_add_row">{{ __('Add Payment') }}</button>
                    <div class="mt-2 small">
                        <span>{{ __('pos.total_paid') }}:</span> <strong id="mixed_total_paid">0</strong>
                        <span class="ms-2">{{ __('pos.remaining') }}:</span> <strong id="mixed_remaining">0</strong>
                    </div>
                </div>

                <div class="mb-2 d-none" id="transaction_id_wrap">
                    <label class="form-label small mb-1">{{ __('Transaction ID') }}</label>
                    <input type="text" class="form-control form-control-sm" id="payment_transaction_id" name="transaction_id" placeholder="{{ __('Transaction ID') }}">
                </div>

                <div class="mb-2 d-none" id="credit_days_wrap">
                    <label class="form-label small mb-1">{{ __('Credit Terms') }}</label>
                    @if(!empty($posCreditTermOptions))
                        <select class="form-select form-select-sm" id="payment_credit_terms" name="credit_terms">
                            <option value="">{{ __('Select Credit Terms') }}</option>
                            @foreach($posCreditTermOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="number" min="1" class="form-control form-control-sm" id="payment_credit_terms" name="credit_terms" placeholder="{{ __('Enter days') }}">
                    @endif
                </div>

                <div class="mb-2">
                    <label class="form-label small mb-1">{{ __('Tip') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="payment_tip" value="0" placeholder="0">
                </div>

                <div class="mb-3">
                    <label class="form-label small mb-1">{{ __('Notes') }}</label>
                    <textarea class="form-control form-control-sm" id="payment_notes" name="notes" rows="2" placeholder="{{ __('Notes') }}"></textarea>
                </div>

                <div class="mb-3 border rounded p-2">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="hotel_charge_enabled">
                        <label class="form-check-label" for="hotel_charge_enabled">{{ __('Charge to Room') }}</label>
                    </div>
                    <div id="hotel_charge_wrap" class="d-none">
                        <input type="text" class="form-control form-control-sm mb-2" id="hotel_guest_search" placeholder="{{ __('Search by guest or room') }}">
                        <select class="form-select form-select-sm" id="hotel_folio_id">
                            <option value="">{{ __('Select In-house Room/Folio') }}</option>
                        </select>
                        <small class="text-muted d-block mt-1">{{ __('Only open folios are shown.') }}</small>
                    </div>
                </div>

                <button type="button" class="btn btn-success btn-sm w-100 payment-done-btn rounded" data-url="{{ route('pos.data.store') }}" data-bs-toggle="tooltip" data-title="{{ __('pos.pos_invoice') }}">
                    {{ __('pos.pay_button') }}
                </button>
            @endif
        </div>
    </div>
@endif

<script>
(function() {
    document.addEventListener('wheel', function(event) {
        if (event.target && event.target.id === 'payment_amount') {
            event.preventDefault();
            event.target.blur();
        }
    }, { passive: false });

    var totalRaw = {{ $totalRaw }};
    var currencySymbol = @json(Auth::user()->currencySymbol() ?? '');
    var getWarehouseValue = function () {
        return $('#pos_restaurant_warehouse_hidden').val() || $('#warehouse_name_hidden').val() || '';
    };
    var formatNum = function(n) {
        var v = Number(n).toFixed(2);
        if (currencySymbol) return currencySymbol + v.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return v;
    };
    var canGivePosCredit = @json($canGivePosCredit ?? false);
    var payMethodOpts = '<option value="cash">{{ __("pos.payment_cash") }}</option><option value="card">{{ __("pos.payment_card") }}</option>' + (canGivePosCredit ? '<option value="credit">{{ __("pos.payment_credit") }}</option>' : '') + '<option value="visa">{{ __("pos.payment_visa") }}</option><option value="mpesa">{{ __("pos.payment_mpesa") }}</option><option value="bank_transfer">{{ __("pos.payment_bank_transfer") }}</option><option value="cheque">{{ __("pos.payment_cheque") }}</option>';

    function togglePaymentFields() {
        var method = $('#payment_method').val();
        var isMixed = method === 'mixed';
        var isCash = method === 'cash';
        var isCard = /^(card|visa|mpesa|bank_transfer|cheque)$/.test(method);
        var isCredit = method === 'credit';
        $('#single_payment_wrap').toggleClass('d-none', isMixed);
        $('#mixed_payment_wrap').toggleClass('d-none', !isMixed);
        $('#transaction_id_wrap').toggleClass('d-none', !isCard);
        $('#credit_days_wrap').toggleClass('d-none', !isCredit);
        $('#payment_amount_label').text(isCash ? '{{ __("pos.cash_received") }}' : '{{ __("Amount") }}');
        if (isCash) {
            updateChange();
        } else {
            $('#change_wrap').addClass('d-none');
            $('#payment_change').val('0');
        }
        if (isMixed) {
            if ($('#mixed_payment_rows .mixed-row').length === 0) {
                addMixedRow();
            }
            updateMixedTotals();
        }
    }

    function updateChange() {
        var amount = parseFloat($('#payment_amount').val()) || 0;
        var change = amount - totalRaw;
        if ($('#payment_method').val() === 'cash' && change > 0) {
            $('#change_wrap').removeClass('d-none');
            $('#change_display').text(formatNum(change));
            $('#payment_change').val(change.toFixed(2));
        } else {
            $('#change_wrap').addClass('d-none');
            $('#payment_change').val('0');
        }
    }

    function mixedRowNeedsTxnId(method) {
        return method && method !== 'cash' && method !== 'credit';
    }
    function addMixedRow() {
        var row = '<div class="mixed-row mb-2"><div class="input-group input-group-sm mb-1"><select class="form-select form-select-sm mixed-method">' + payMethodOpts + '</select><input type="number" step="0.01" min="0" class="form-control form-control-sm mixed-amount" placeholder="{{ __("pos.enter_amount") }}"><button type="button" class="btn btn-outline-danger btn-sm mixed-remove">&times;</button></div><div class="mixed-txn-wrap d-none mt-1"><input type="text" class="form-control form-control-sm mixed-transaction-id" placeholder="{{ __("Transaction ID") }}"></div></div>';
        $('#mixed_payment_rows').append(row);
        $(document).off('input change', '.mixed-amount').on('input change', '.mixed-amount', updateMixedTotals);
        $(document).off('change', '.mixed-method').on('change', '.mixed-method', function() {
            var $row = $(this).closest('.mixed-row');
            var need = mixedRowNeedsTxnId($(this).val());
            $row.find('.mixed-txn-wrap').toggleClass('d-none', !need);
        });
        $(document).off('click', '.mixed-remove').on('click', '.mixed-remove', function() {
            $(this).closest('.mixed-row').remove();
            updateMixedTotals();
        });
        updateMixedTotals();
    }

    function updateMixedTotals() {
        var sum = 0;
        $('.mixed-amount').each(function() {
            sum += parseFloat($(this).val()) || 0;
        });
        var remaining = Math.max(0, totalRaw - sum);
        $('#mixed_total_paid').text(formatNum(sum));
        $('#mixed_remaining').text(formatNum(remaining));
    }

    $('#payment_method').on('change', togglePaymentFields);
    $('#payment_amount').on('input change', updateChange);
    $('#mixed_add_row').on('click', addMixedRow);
    $('#payment_tip').on('input change', function () {
        var tip = parseFloat($(this).val()) || 0;
        $('#order-summary-tip-display').text(formatNum(tip));
    });
    togglePaymentFields();
    updateChange();

    $(document).on('pos-apply-split-config', function (e, config) {
        if (!config || config.mode !== 'equal' || !config.amounts || !config.amounts.length) {
            return;
        }
        $('#payment_method').val('mixed').trigger('change');
        $('#mixed_payment_rows').empty();
        config.amounts.forEach(function (amt) {
            addMixedRow();
            var $rows = $('#mixed_payment_rows .mixed-row');
            $rows.last().find('.mixed-amount').val(Number(amt).toFixed(2));
        });
        updateMixedTotals();
    });

    function renderHotelFolioOptions(items) {
        var html = '<option value="">{{ __("Select In-house Room/Folio") }}</option>';
        (items || []).forEach(function (row) {
            if (!row.folio_id) return;
            var label = (row.room_no ? ('Room ' + row.room_no + ' - ') : '') + (row.guest_name || '') + (row.folio_no ? (' [' + row.folio_no + ']') : '');
            html += '<option value="' + row.folio_id + '">' + $('<div>').text(label).html() + '</option>';
        });
        $('#hotel_folio_id').html(html);
    }

    function loadHotelFolios(q) {
        return $.ajax({
            url: "{{ route('hotel.pos.in-house-guests') }}",
            method: 'GET',
            dataType: 'json',
            data: { q: q || '' },
            success: function (rows) { renderHotelFolioOptions(rows || []); }
        });
    }

    $('#hotel_charge_enabled').on('change', function () {
        var on = $(this).is(':checked');
        $('#hotel_charge_wrap').toggleClass('d-none', !on);
        if (on && $('#hotel_folio_id option').length <= 1) {
            loadHotelFolios('');
        }
    });
    $('#hotel_guest_search').on('input', function () {
        if (!$('#hotel_charge_enabled').is(':checked')) return;
        loadHotelFolios($(this).val() || '');
    });

    $(document).on('click', '.payment-done-btn', function (e) {
        e.preventDefault();
        console.log('payment-done-btn clicked');
        var ele = $(this);
        var method = $('#payment_method').val();
        if (method === 'mixed') {
            var payments = [];
            $('.mixed-row').each(function() {
                var amt = parseFloat($(this).find('.mixed-amount').val()) || 0;
                if (amt <= 0) return;
                var txnId = $(this).find('.mixed-transaction-id').val();
                payments.push({
                    payment_method: $(this).find('.mixed-method').val(),
                    amount: amt.toFixed(2),
                    transaction_id: (txnId && txnId.trim()) ? txnId.trim() : ''
                });
            });
            var totalPaid = payments.reduce(function(s, p) { return s + parseFloat(p.amount); }, 0);
            if (totalPaid < totalRaw) {
                if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("pos.amount_cannot_less_than_total") }}', 'error');
                return;
            }
            var data = {
                vc_name: $('#vc_name_hidden').val(),
                warehouse_name: getWarehouseValue(),
                discount: ($('#discount_hidden_restaurant').length ? $('#discount_hidden_restaurant').val() : $('#discount_hidden').val()) || '0',
                quotation_id: $('#quotation_id').val(),
                payment_method: 'mixed',
                document_type: $('#document_type').val() || 'cash_sale',
                notes: $('#payment_notes').val() || '',
                tip: parseFloat($('#payment_tip').val()) || 0,
                pos_order_type: $('#pos_order_type_hidden').val() || '',
                pos_table_id: $('#pos_table_id_hidden').val() || '',
                pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                hotel_charge_enabled: $('#hotel_charge_enabled').is(':checked') ? 1 : 0,
                hotel_folio_id: $('#hotel_folio_id').val() || '',
            };
            if (data.hotel_charge_enabled && !data.hotel_folio_id) {
                if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("Please select room folio.") }}', 'error');
                return;
            }
                if ($('#pos_restaurant_pos_id').length && $('#pos_restaurant_pos_id').val()) data.pos_id = $('#pos_restaurant_pos_id').val();
            payments.forEach(function(p, i) {
                data['payments[' + i + '][payment_method]'] = p.payment_method;
                data['payments[' + i + '][amount]'] = p.amount;
                if (p.transaction_id) data['payments[' + i + '][transaction_id]'] = p.transaction_id;
            });
            $.ajax({
                url: "{{ route('pos.data.store') }}",
                method: 'GET',
                data: data,
                beforeSend: function () { ele.prop('disabled', true); },
                success: function (data) {
                    if (data.code == 200) {
                        $(document).trigger('pos-payment-completed', [data]);
                        if (typeof show_toastr === 'function') show_toastr('success', data.success, 'success');
                    }
                },
                error: function (xhr) {
                    var data = xhr.responseJSON || {};
                    if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', data.error || data.success || '{{ __("pos.error") }}', 'error');
                    ele.prop('disabled', false);
                }
            });
            return;
        }
        var amount = parseFloat($('#payment_amount').val()) || totalRaw;
        if (amount < totalRaw) {
            if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("pos.amount_cannot_less_than_total") }}', 'error');
            return;
        }
        console.log('payment-done-btn clicked 2');
        var payload = (function() {
            var d = {
                vc_name: $('#vc_name_hidden').val(),
                warehouse_name: getWarehouseValue(),
                discount: ($('#discount_hidden_restaurant').length ? $('#discount_hidden_restaurant').val() : $('#discount_hidden').val()) || '0',
                quotation_id: $('#quotation_id').val(),
                payment_method: method,
                document_type: $('#document_type').val() || 'cash_sale',
                amount: amount.toFixed(2),
                change_amount: $('#payment_change').val() || '0',
                transaction_id: $('#payment_transaction_id').val() || '',
                credit_terms: $('#payment_credit_terms').val() || '',
                notes: $('#payment_notes').val() || '',
                tip: parseFloat($('#payment_tip').val()) || 0,
                pos_order_type: $('#pos_order_type_hidden').val() || '',
                pos_table_id: $('#pos_table_id_hidden').val() || '',
                pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                hotel_charge_enabled: $('#hotel_charge_enabled').is(':checked') ? 1 : 0,
                hotel_folio_id: $('#hotel_folio_id').val() || '',
            };
            if (d.hotel_charge_enabled && !d.hotel_folio_id) {
                if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("Please select room folio.") }}', 'error');
                return null;
            }
            if ($('#pos_restaurant_pos_id').length && $('#pos_restaurant_pos_id').val()) d.pos_id = $('#pos_restaurant_pos_id').val();
            return d;
        })();
        if (!payload) {
            console.warn('POS payment aborted: invalid payload.');
            return;
        }
        $.ajax({
            url: "{{ route('pos.data.store') }}",
            method: 'GET',
            data: payload,
            beforeSend: function () {
                ele.prop('disabled', true);
            },
            success: function (data) {
                if (data.code == 200) {
                    $(document).trigger('pos-payment-completed', [data]);
                    if (typeof show_toastr === 'function') show_toastr('success', data.success, 'success');
                }
            },
            error: function (xhr) {
                var data = xhr.responseJSON || {};
                if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', data.error || data.success || '{{ __("pos.error") }}', 'error');
                ele.prop('disabled', false);
                console.log('payment-done-btn clicked 3');
            }
        });
    });
})();
</script>
