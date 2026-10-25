{{-- Restaurant POS: pick an allocated warehouse that can cover the item before add-to-cart. --}}
<div class="modal fade" id="posWarehousePickerModal" tabindex="-1" aria-labelledby="posWarehousePickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posWarehousePickerModalLabel">{{ __('Warehouse') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2" id="posWarehousePickerHint"></p>
                <div id="posWarehousePickerList" class="d-flex flex-column gap-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-warning d-none" id="posWarehousePickerManagerBtn">{{ __('Manager authorization') }}</button>
                <button type="button" class="btn btn-primary" id="posWarehousePickerConfirm">{{ __('Add to Cart') }}</button>
            </div>
        </div>
    </div>
</div>
