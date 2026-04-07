{{-- POS: pick size/color before add-to-cart when product has variants_enabled --}}
<div class="modal fade" id="posVariantPickerModal" tabindex="-1" aria-labelledby="posVariantPickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posVariantPickerModalLabel">{{ __('Size / color') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <label class="form-label mb-1" for="posVariantPickerSelect">{{ __('Variant') }}</label>
                <select class="form-select" id="posVariantPickerSelect"></select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="posVariantPickerConfirm">{{ __('Add to Cart') }}</button>
            </div>
        </div>
    </div>
</div>
