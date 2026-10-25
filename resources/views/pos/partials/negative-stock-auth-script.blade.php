{{-- Shared manager-authorization popup logic for below-zero POS sales.
     Included inside the page script (no <script> tags here). Call
     posOpenNegStockAuthModal(approval, onApproved): approval is the 403
     payload from add-to-cart (or a hand-built {product_id, ...} object);
     onApproved runs after the manager is verified, e.g. retrying the add. --}}
var posNegStockAuthPending = null;
function posOpenNegStockAuthModal(approval, onApproved) {
    approval = approval || {};
    $('#negStockAuthProductName').text(approval.product_name || '');
    if (approval.available != null && approval.requested != null) {
        $('#negStockAuthDetail').text('{{ __('Available: :available — requested: :requested', ['available' => '__AV__', 'requested' => '__RQ__']) }}'.replace('__AV__', approval.available).replace('__RQ__', approval.requested));
    } else {
        $('#negStockAuthDetail').text('');
    }
    $('#negStockAuthPassword').val('');
    $('#negStockAuthError').hide().text('');
    posNegStockAuthPending = { approval: approval, onApproved: onApproved || null };
    $('#negStockAuthModal').modal('show');
    setTimeout(function () { $('#negStockAuthPassword').trigger('focus'); }, 300);
}
$(document).on('click', '#negStockAuthConfirm', function () {
    var pending = posNegStockAuthPending;
    if (!pending) return;
    $.ajax({
        type: 'POST',
        url: '{{ route('pos.negative-stock.authorize') }}',
        data: {
            _token: '{{ csrf_token() }}',
            product_id: pending.approval.product_id || '',
            product_variant_id: pending.approval.product_variant_id || '',
            manager_password: $('#negStockAuthPassword').val() || ''
        },
        success: function () {
            $('#negStockAuthModal').modal('hide');
            var cb = pending.onApproved;
            posNegStockAuthPending = null;
            if (cb) cb();
        },
        error: function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.error) || '{{ __('Could not verify manager authorization.') }}';
            $('#negStockAuthError').text(msg).show();
        }
    });
});
$(document).on('keypress', '#negStockAuthPassword', function (e) {
    if (e.which === 13) { e.preventDefault(); $('#negStockAuthConfirm').trigger('click'); }
});
