@php
    $orderSummary = $orderSummary ?? [];
    $totalRaw = $orderSummary['total_raw'] ?? 0;
    $itemsCount = $orderSummary['items_count'] ?? 0;
    $subtotalFmt = $orderSummary['subtotal'] ?? '0';
    $taxFmt = $orderSummary['tax'] ?? '0';
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
                        <span>{{ __('pos.total') }}:</span>
                        <strong>{{ $totalFmt }}</strong>
                    </div>
                </div>
            </div>

            @if (($details['pay'] ?? '') == 'show')
                <div class="mb-2">
                    <label class="form-label small mb-1">{{ __('Payment Method') }}</label>
                    <select class="form-select form-select-sm" id="payment_method" name="payment_method">
                        <option value="cash">{{ __('pos.payment_cash') }}</option>
                        <option value="card">{{ __('pos.payment_card') }}</option>
                        <option value="credit">{{ __('pos.payment_credit') }}</option>
                        <option value="mixed">{{ __('Mixed Payment') }}</option>
                        <option value="visa">{{ __('pos.payment_visa') }}</option>
                        <option value="mpesa">{{ __('pos.payment_mpesa') }}</option>
                        <option value="bank_transfer">{{ __('pos.payment_bank_transfer') }}</option>
                        <option value="cheque">{{ __('pos.payment_cheque') }}</option>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small mb-1">{{ __('Amount') }}</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="payment_amount" value="{{ $totalRaw }}" placeholder="{{ __('pos.enter_amount') }}">
                </div>
                <div class="mb-2 d-none" id="change_wrap">
                    <div class="alert alert-success mb-0 py-1 small">
                        <strong>{{ __('Change') }}:</strong> <span id="change_display">0</span>
                    </div>
                    <input type="hidden" id="payment_change" name="change_amount" value="0">
                </div>

                <div class="mb-2 d-none" id="transaction_id_wrap">
                    <label class="form-label small mb-1">{{ __('Transaction ID') }}</label>
                    <input type="text" class="form-control form-control-sm" id="payment_transaction_id" name="transaction_id" placeholder="{{ __('Transaction ID') }}">
                </div>

                <div class="mb-2 d-none" id="credit_days_wrap">
                    <label class="form-label small mb-1">{{ __('Credit Days') }}</label>
                    <input type="number" min="1" class="form-control form-control-sm" id="payment_credit_terms" name="credit_terms" placeholder="{{ __('Enter days') }}">
                </div>

                <div class="mb-3">
                    <label class="form-label small mb-1">{{ __('Notes') }}</label>
                    <textarea class="form-control form-control-sm" id="payment_notes" name="notes" rows="2" placeholder="{{ __('Notes') }}"></textarea>
                </div>

                <button type="button" class="btn btn-success btn-sm w-100 payment-done-btn rounded" data-url="{{ route('pos.printview') }}" data-ajax-popup="true" data-size="sm" data-bs-toggle="tooltip" data-title="{{ __('pos.pos_invoice') }}">
                    {{ __('pos.pay_button') }}
                </button>
            @endif
        </div>
    </div>
@endif

<script>
(function() {
    var totalRaw = {{ $totalRaw }};
    var currencySymbol = @json(Auth::user()->currencySymbol() ?? '');
    var formatNum = function(n) {
        var v = Number(n).toFixed(2);
        if (currencySymbol) return currencySymbol + v.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return v;
    };

    function togglePaymentFields() {
        var method = $('#payment_method').val();
        var isCard = /^(card|visa|mpesa|bank_transfer|cheque)$/.test(method);
        var isCredit = method === 'credit';
        $('#transaction_id_wrap').toggleClass('d-none', !isCard);
        $('#credit_days_wrap').toggleClass('d-none', !isCredit);
    }

    function updateChange() {
        var amount = parseFloat($('#payment_amount').val()) || 0;
        var change = amount - totalRaw;
        if (change > 0) {
            $('#change_wrap').removeClass('d-none');
            $('#change_display').text(formatNum(change));
            $('#payment_change').val(change.toFixed(2));
        } else {
            $('#change_wrap').addClass('d-none');
            $('#payment_change').val('0');
        }
    }

    $('#payment_method').on('change', togglePaymentFields);
    $('#payment_amount').on('input change', updateChange);
    togglePaymentFields();
    updateChange();

    $(document).on('click', '.payment-done-btn', function (e) {
        e.preventDefault();
        var ele = $(this);
        var amount = parseFloat($('#payment_amount').val()) || totalRaw;
        if (amount < totalRaw) {
            if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("pos.amount_cannot_less_than_total") }}', 'error');
            return;
        }
        $.ajax({
            url: "{{ route('pos.data.store') }}",
            method: 'GET',
            data: {
                vc_name: $('#vc_name_hidden').val(),
                warehouse_name: $('#warehouse_name_hidden').val(),
                discount: $('#discount_hidden').val(),
                quotation_id: $('#quotation_id').val(),
                payment_method: $('#payment_method').val(),
                amount: amount.toFixed(2),
                change_amount: $('#payment_change').val() || '0',
                transaction_id: $('#payment_transaction_id').val() || '',
                credit_terms: $('#payment_credit_terms').val() || '',
                notes: $('#payment_notes').val() || '',
                pos_order_type: $('#pos_order_type_hidden').val() || '',
                pos_table_id: $('#pos_table_id_hidden').val() || '',
                pos_shift_id: $('#pos_shift_id_hidden').val() || '',
            },
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
            }
        });
    });
})();
</script>
