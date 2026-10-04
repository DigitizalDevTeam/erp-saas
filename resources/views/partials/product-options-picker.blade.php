{{-- Multi-select product options modal (parent and option prices/qtys stay independent) --}}
<div class="modal fade" id="erpProductOptionsModal" tabindex="-1" aria-labelledby="erpProductOptionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="erpProductOptionsModalLabel">{{ __('Product options') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2" id="erpProductOptionsParentName"></p>
                <div id="erpProductOptionsList" class="list-group mb-0"></div>
                <p class="text-muted small mt-2 mb-0">{{ __('Parent price and quantity stay separate from each option price and quantity.') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="erpProductOptionsConfirm">{{ __('Confirm') }}</button>
            </div>
        </div>
    </div>
</div>
<style>
    #erpProductOptionsModal .erp-opt-row {
        flex-wrap: nowrap;
        min-width: 0;
    }
    #erpProductOptionsModal .erp-opt-row > .text-truncate,
    #erpProductOptionsModal .erp-opt-row > .min-w-0 {
        min-width: 0;
    }
    #erpProductOptionsModal .erp-opt-qty {
        width: 4rem;
        min-width: 4rem;
    }
</style>
<script>
    window.ErpProductOptionsI18n = {
        error: @json(__('Error')),
        noOptions: @json(__('No options configured for this product.')),
        selectOne: @json(__('Select at least one product option.')),
        selectGroup: @json(__('Select one option for')),
        selectOneGroup: @json(__('Select at most one option for')),
        addOns: @json(__('Add-ons')),
        qty: @json(__('Qty')),
        noStock: @json(__('Does not affect stock')),
        variants: @json(__('Variants')),
    };
</script>
<script src="{{ asset('js/product-options-picker.js') }}?v=20261004a"></script>
