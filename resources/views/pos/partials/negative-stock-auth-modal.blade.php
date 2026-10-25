{{-- Manager authorization popup for a below-zero POS sale (retail + restaurant). --}}
<div class="modal fade" id="negStockAuthModal" tabindex="-1" aria-labelledby="negStockAuthModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="negStockAuthModalLabel">{{ __('Manager authorization') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong id="negStockAuthProductName"></strong></p>
                <p class="small text-muted mb-2" id="negStockAuthDetail"></p>
                <p class="small mb-2">{{ __('This sale would take stock below zero. Enter a manager password or POS PIN to approve this sale.') }}</p>
                <input type="password" class="form-control" id="negStockAuthPassword" placeholder="{{ __('Manager password or PIN') }}" autocomplete="off">
                <p class="small text-danger mt-2 mb-0" id="negStockAuthError" style="display:none;"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="negStockAuthConfirm">{{ __('Approve sale') }}</button>
            </div>
        </div>
    </div>
</div>
