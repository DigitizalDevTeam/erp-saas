{{-- Multi-select product options modal (parent price + selected option prices) --}}
<div class="modal fade" id="erpProductOptionsModal" tabindex="-1" aria-labelledby="erpProductOptionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="erpProductOptionsModalLabel">{{ __('Product options') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2" id="erpProductOptionsParentName"></p>
                <div id="erpProductOptionsList" class="list-group mb-0"></div>
                <p class="text-muted small mt-2 mb-0">{{ __('Line price = parent price + sum of selected option prices.') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="erpProductOptionsConfirm">{{ __('Confirm') }}</button>
            </div>
        </div>
    </div>
</div>
<script>
    window.ErpProductOptionsI18n = {
        error: @json(__('Error')),
        noOptions: @json(__('No options configured for this product.')),
        selectOne: @json(__('Select at least one product option.')),
        qty: @json(__('Qty')),
    };
</script>
<script src="{{ asset('js/product-options-picker.js') }}"></script>
