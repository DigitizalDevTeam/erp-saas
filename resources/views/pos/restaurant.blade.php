{{-- Restaurant Mode: Table-based POS with kitchen workflow --}}
@php
    $lastsegment = request()->segment(count(request()->segments()));
    $restaurantBranchIds = $restaurantBranchIds ?? [];
    $restaurantBranches = isset($branches) ? collect($branches) : collect();
    $restaurantWarehouses = $warehouses ?? collect();
    $posAssignedBranchId = isset($posPinUser) && !empty($posPinUser->pos_branch_id) ? (int) $posPinUser->pos_branch_id : null;
    $shiftBranchId = (isset($userOpenShift) && $userOpenShift && $userOpenShift->branch_id) ? $userOpenShift->branch_id : null;
    $tp = function (string $key, string $fallback) {
        $translated = __($key);
        return $translated === $key ? __($fallback) : $translated;
    };
@endphp

{{-- ============ RESPONSIVE CSS ============ --}}
<style>
/* ===== Restaurant POS Mobile Responsive ===== */
@media (max-width: 767.98px) {

    /* --- Top controls row: stack vertically --- */
    .pos-restaurant-controls-row {
        flex-wrap: wrap !important;
        align-items: stretch !important;
    }
    .pos-restaurant-controls-main {
        flex-wrap: wrap !important;
        overflow-x: visible !important;
        width: 100%;
    }
    .pos-restaurant-control-item {
        flex-shrink: 1 !important;
        width: 100%;
        margin-bottom: 6px;
    }
    .pos-restaurant-control-item select,
    .pos-restaurant-control-item input {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }
    #pos-restaurant-table-block {
        flex-wrap: wrap !important;
        width: 100%;
    }
    #pos-restaurant-table-block .btn,
    #pos-restaurant-table-block .text-nowrap {
        width: 100%;
        margin-bottom: 4px;
    }
    #pos-restaurant-guest-block {
        width: 100%;
    }
    #pos-restaurant-guest-block .d-flex {
        flex-wrap: wrap !important;
    }
    #pos-restaurant-guest-block input {
        width: 100% !important;
        min-width: 0 !important;
        margin-bottom: 4px;
    }
    #pos-restaurant-manage-tables-wrap {
        width: 100%;
    }
    #pos-restaurant-manage-tables-wrap .btn {
        width: 100%;
    }

    /* --- Cart / Products columns: stack --- */
    .pos-restaurant-layout .row.g-3 > [class*="col-"] {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        width: 100% !important;
    }
    /* Cart ko products ke neeche rakhna ho to order change karein */
    .pos-restaurant-layout .order-1 { order: 1 !important; }
    .pos-restaurant-layout .order-2 { order: 2 !important; }

    /* --- Invoice header buttons: 2 rows --- */
    .pos-invoice-actions {
        flex-direction: column;
    }
    .pos-invoice-actions__primary {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        width: 100%;
        margin-bottom: 6px;
    }
    .pos-invoice-actions__primary .btn {
        flex: 1 1 auto;
    }
    .pos-invoice-actions__tools {
        width: 100%;
    }
    .pos-invoice-actions__tools .btn {
        flex: 1 1 0;
        padding: 4px 2px;
    }

    /* --- Cart items table --- */
    .pos-cart-table--restaurant {
        font-size: 12px;
    }
    .pos-cart-table--restaurant td,
    .pos-cart-table--restaurant th {
        padding: 4px 2px;
    }

    /* --- Totals row --- */
    .pos-totals__row--discount {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 4px;
    }
    .pos-totals__discount-controls {
        width: 100%;
        display: flex;
        gap: 4px;
    }
    .pos-totals__discount-controls select {
        width: 40%;
    }
    .pos-totals__discount-input {
        flex: 1;
    }

    /* --- Category scroll arrows chhote --- */
    .pos-category-scroll-wrap {
        padding: 0 2rem !important;
    }
    .pos-category-scroll-arrow {
        min-width: 2rem !important;
        min-height: 2rem !important;
        padding: 2px 4px !important;
    }

    /* --- Product grid: 2 columns on mobile --- */
    #product-listing-restaurant .col-xl-3,
    #product-listing-restaurant .col-lg-3,
    #product-listing-restaurant .col-md-3,
    #product-listing-restaurant .col-3 {
        flex: 0 0 50% !important;
        max-width: 50% !important;
    }

    /* --- Table grid: 2 columns --- */
    #pos-table-grid .pos-table-btn {
        flex: 1 1 45%;
    }

    /* --- Modal full width on mobile --- */
    .modal-dialog {
        margin: 0.5rem !important;
    }
    .modal-dialog.modal-lg,
    .modal-dialog.modal-xl {
        max-width: calc(100% - 1rem) !important;
    }

    /* --- Prep time / Open table modals --- */
    .modal-body input,
    .modal-body select,
    .modal-body textarea {
        font-size: 14px;
    }
}

/* Extra small phones */
@media (max-width: 375.98px) {
    #product-listing-restaurant .col-xl-3,
    #product-listing-restaurant .col-lg-3,
    #product-listing-restaurant .col-md-3,
    #product-listing-restaurant .col-3 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
    .pos-invoice-actions__tools .btn {
        font-size: 11px;
    }
}
</style>

<div class="pos-restaurant-layout">
    @if(!empty($posBranchAssignmentWarning))
        <div class="alert alert-warning mb-3 py-2 small">{{ $posBranchAssignmentWarning }}</div>
    @endif

    {{-- Top bar: Branch, Warehouse, Selected table + Tables grid --}}
    <div class="pos-restaurant-top card border-0 shadow-sm mb-3">
        <div class="card-body py-2 px-3">
            <div class="pos-restaurant-controls-row d-flex flex-nowrap align-items-end gap-2 mb-2">
                <div class="pos-restaurant-controls-main d-flex flex-nowrap align-items-end gap-2 flex-grow-1 min-w-0 overflow-x-auto">

                    {{-- Branch --}}
                    <div class="pos-restaurant-control-item flex-shrink-0">
                        <label class="form-label small text-muted mb-0 text-nowrap">
                            {{ $tp('pos.branch', 'Branch') }}
                            @if(!empty($posBranchLocked)) <span class="text-muted">({{ __('Assigned') }})</span>@endif
                        </label>
                        <select class="form-control form-control-sm" id="pos-restaurant-branch"
                                style="min-width: 120px; max-width: 160px;" @if(!empty($posBranchLocked)) disabled @endif>
                            <option value="">{{ $tp('pos.select_branch', 'Select Branch') }}</option>
                            @foreach($restaurantBranches as $id => $name)
                                <option value="{{ $id }}"
                                    {{ ($posAssignedBranchId && (string)$id === (string)$posAssignedBranchId) || (!$posAssignedBranchId && $shiftBranchId && (string)$id === (string)$shiftBranchId) || (!empty($posBranchLocked) && $restaurantBranches->count() === 1) ? 'selected' : '' }}>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Warehouse --}}
                    <div class="pos-restaurant-control-item flex-shrink-0">
                        <label class="form-label small text-muted mb-0 text-nowrap">{{ $tp('pos.select_warehouse', 'Select Warehouse') }}</label>
                        <select class="form-control form-control-sm" id="pos-restaurant-warehouse"
                                style="min-width: 110px; max-width: 150px;">
                            <option value="">{{ $tp('pos.select_warehouse', 'Select Warehouse') }}</option>
                            @foreach($restaurantWarehouses as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted d-block text-nowrap" id="pos-restaurant-warehouse-hint"
                               style="font-size: 0.7rem;">{{ $tp('pos.select_branch_first', 'Select branch first') }}</small>
                    </div>

                    {{-- Order Type --}}
                    <div class="pos-restaurant-control-item flex-shrink-0">
                        <label class="form-label small text-muted mb-0 text-nowrap">{{ $tp('pos.order_type', 'Order Type') }}</label>
                        <select class="form-control form-control-sm" id="pos-restaurant-order-type" style="min-width: 95px;">
                            <option value="dine_in">{{ $tp('pos.dine_in', 'Dine In') }}</option>
                            <option value="delivery">{{ $tp('pos.delivery', 'Delivery') }}</option>
                            <option value="takeaway">{{ $tp('pos.takeaway', 'Takeaway') }}</option>
                        </select>
                    </div>

                    {{-- Table block --}}
                    <div id="pos-restaurant-table-block" class="pos-restaurant-control-item flex-shrink-0 d-flex align-items-end gap-1 flex-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                id="pos-restaurant-reset-btn"
                                title="{{ $tp('pos.reset', 'Reset') }}">
                            <i class="ti ti-refresh me-1"></i>{{ $tp('pos.reset', 'Reset') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                id="pos-open-table-panel-btn" aria-expanded="false"
                                aria-controls="pos-restaurant-table-grid-wrap"
                                title="{{ $tp('pos.open_table', 'Open Table') }}">
                            <i class="ti ti-layout-grid me-1"></i>{{ $tp('pos.open_table', 'Open Table') }}
                        </button>
                        <div class="text-nowrap">
                            <label class="form-label small text-muted mb-0">{{ $tp('pos.select_table', 'Select Table') }}</label>
                            <div id="pos-selected-table" class="fw-bold text-primary">—</div>
                        </div>
                        <div id="pos-check-selector-wrap" class="d-none text-nowrap">
                            <label class="form-label small text-muted mb-0">{{ __('pos.select_check') }}</label>
                            <select class="form-select form-select-sm" id="pos-check-selector" style="min-width: 9rem;"></select>
                        </div>
                        @if($canShowPayButton ?? true)
                            <button type="button" class="btn btn-sm btn-danger d-none flex-shrink-0 text-nowrap"
                                    id="pos-close-table-btn"
                                    title="{{ $tp('pos.close_table', 'Close Table') }} ({{ $tp('pos.manager_emergency', 'Manager / emergency') }})">
                                <i class="ti ti-door-exit me-1"></i>{{ $tp('pos.close_table', 'Close Table') }}
                            </button>
                        @endif
                    </div>

                    {{-- Guest block (delivery/takeaway) --}}
                    <div id="pos-restaurant-guest-block" class="pos-restaurant-control-item flex-shrink-0 d-none">
                        <label class="form-label small text-muted mb-0 text-nowrap d-block">{{ $tp('pos.customer_details_required', 'Customer Details Required') }}</label>
                        <div class="d-flex flex-nowrap gap-1">
                            <input type="text" class="form-control form-control-sm" id="pos-guest-name"
                                   placeholder="{{ $tp('pos.guest_name', 'Guest Name') }}" maxlength="255" style="min-width: 100px;">
                            <input type="email" class="form-control form-control-sm" id="pos-guest-email"
                                   placeholder="{{ $tp('pos.guest_email', 'Guest Email') }}" maxlength="255" style="min-width: 110px;">
                            <input type="text" class="form-control form-control-sm" id="pos-guest-phone"
                                   placeholder="{{ $tp('pos.guest_phone', 'Guest Phone') }}" maxlength="64" style="min-width: 95px;">
                        </div>
                    </div>

                </div>

                {{-- Manage tables --}}
                <div class="pos-restaurant-control-item flex-shrink-0" id="pos-restaurant-manage-tables-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                            id="pos-manage-tables-btn" title="{{ $tp('pos.manage_tables', 'Manage Tables') }}">
                        <i class="ti ti-settings"></i> {{ $tp('pos.manage_tables', 'Manage Tables') }}
                    </button>
                </div>
            </div>

            @php
                $customersForPos = $customersForPos ?? collect();
                $selCustomer = $customersForPos->firstWhere('name', $customer ?? 'Walk-in-customer') ?? $customersForPos->first();
            @endphp
            <div class="border-top pt-2 mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small fw-semibold d-flex align-items-center gap-1">
                        <i class="ti ti-user"></i> {{ __('Customer') }}
                    </span>
                    <div class="d-flex align-items-center gap-1">
                        <a href="#" class="text-muted small pos-restaurant-customer-edit d-none" id="pos-restaurant-customer-edit" title="{{ __('Add New Customer') }}"><i class="ti ti-edit"></i> {{ __('Edit') }}</a>
                        <a href="#" class="text-muted small pos-restaurant-customer-change" id="pos-restaurant-customer-change">{{ __('Change') }}</a>
                    </div>
                </div>
                <select class="form-control form-control-sm select pos-restaurant-customer-select d-none" id="pos-restaurant-customer" required>
                    @foreach($customersForPos as $c)
                        <option value="{{ $c->name }}" data-email="{{ e($c->email ?? '') }}" data-contact="{{ e($c->contact ?? '') }}" data-type="{{ (($c->name === 'Walk-in-customer') || !empty($c->is_walk_in)) ? 'walk-in' : 'customer' }}" {{ (($customer ?? '') === $c->name || (($customer ?? '') === '' && $c->name === 'Walk-in-customer')) ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                {{ Form::hidden('vc_name_hidden_restaurant', ($customer ?? '') ?: 'Walk-in-customer', ['id' => 'pos-restaurant-vc-name-hidden']) }}
                <div class="card border rounded-2 shadow-sm p-2 bg-white" id="pos-restaurant-customer-card">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <div class="fw-bold text-dark text-truncate" id="pos-restaurant-customer-name">{{ $selCustomer->name ?? __('Walk-in-customer') }}</div>
                            <small class="text-muted d-block text-truncate d-none" id="pos-restaurant-waiter-name"></small>
                            <small class="text-muted d-block text-truncate" id="pos-restaurant-customer-email">{{ $selCustomer->email ?? 'walkin@default.com' }}</small>
                            <small class="text-muted d-block" id="pos-restaurant-customer-phone">{{ $selCustomer->contact ?? '0000000000' }}</small>
                        </div>
                        <span class="badge bg-light text-dark border" id="pos-restaurant-customer-badge">{{ ($selCustomer && ($selCustomer->name === 'Walk-in-customer' || !empty($selCustomer->is_walk_in))) ? __('Walk-in') : __('Customer') }}</span>
                    </div>
                    <div id="pos-restaurant-customer-balance-wrap" class="mt-2 pt-2 border-top d-none">
                        <div class="d-flex justify-content-between align-items-center small">
                            <span class="text-muted">{{ __('Balance due') }}:</span>
                            <span id="pos-restaurant-customer-balance" class="fw-semibold text-danger" data-raw="0">0</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1 w-100" id="pos-restaurant-pay-balance-btn"><i class="ti ti-cash me-1"></i> {{ __('Pay balance') }}</button>
                    </div>
                </div>
            </div>

            <div id="pos-restaurant-table-grid-wrap" class="border-top pt-2 d-none">
                <div class="d-flex flex-wrap gap-2 align-items-center" id="pos-table-grid">
                    <p class="text-muted small mb-0">{{ $tp('pos.select_branch_first', 'Select branch first') }}</p>
                </div>
            </div>

            <div id="pos-restaurant-customer-grid-wrap" class="border-top pt-2 d-none">
                <label class="form-label small text-muted mb-1">{{ $tp('pos.select_customer', 'Select Customer') }}</label>
                <div class="d-flex flex-wrap gap-2 align-items-center" id="pos-customer-grid">
                    <p class="text-muted small mb-0">{{ $tp('pos.select_branch_first', 'Select branch first') }}</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="pos-new-delivery-takeaway-btn">
                    <i class="ti ti-plus"></i> <span id="pos-new-delivery-takeaway-label">{{ $tp('pos.new_delivery', 'New Delivery') }}</span>
                </button>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Left: Cart / Invoice --}}
        <div class="col-lg-4 col-md-5 order-2 order-lg-1">
            <div id="pos-restaurant-order" class="d-none">
                <div class="card border-0 shadow-sm pos-cart-card h-100">
                    <div class="card-header p-2 pos-invoice-header bg-light">
                        <div class="pos-invoice-actions">
                            <div class="pos-invoice-actions__primary">
                                <button type="button" class="btn btn-sm btn-success m-0" id="pos-send-to-production"
                                        title="{{ __('Send to production') }}">
                                    <i class="ti ti-send me-1"></i>
                                    <span class="pos-invoice-btn-label">{{ __('Send to production') }}</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary pos-invoice-btn-icon"
                                        id="pos-print-kitchen-slip" title="{{ __('pos.print_kitchen_slip') }}"
                                        aria-label="{{ __('pos.print_kitchen_slip') }}">
                                    <i class="ti ti-printer"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary m-0" id="pos-printer-config-btn"
                                        title="{{ __('Print') }}">
                                    <i class="ti ti-printer me-1"></i>
                                    <span class="pos-invoice-btn-label">{{ __('Print') }}</span>
                                </button>
                                @if($canShowPayButton ?? true)
                                    <button type="button" id="pos-pay-btn-restaurant"
                                            class="btn btn-sm btn-success pos-invoice-btn-pay m-0"
                                            data-size="xl" data-url="{{ route('pos.create') }}"
                                            data-title="" disabled>
                                        {{ $tp('pos.pay', 'Pay') }}
                                    </button>
                                @endif
                            </div>
                            <div class="btn-group btn-group-sm w-100 pos-invoice-actions__tools" role="group"
                                 aria-label="{{ $tp('pos.invoice_tools', 'Invoice tools') }}">
                                <button type="button" class="btn btn-outline-secondary pos-open-drawer-btn" title="{{ $tp('pos.open_drawer', 'Open Drawer') }}">
                                    <i class="ti ti-cash d-block"></i>
                                </button>
                                <button type="button" class="btn btn-outline-info" id="pos-join-table-restaurant" title="{{ $tp('pos.join_table', 'Join Table') }}">
                                    <i class="ti ti-table d-block"></i>
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="pos-move-table-restaurant" title="{{ __('pos.move_table') }}">
                                    <i class="ti ti-arrows-right d-block"></i>
                                </button>
                                <button type="button" class="btn btn-outline-warning" id="pos-split-bill-restaurant" title="{{ __('pos.split_bill') }}">
                                    <i class="ti ti-separator-horizontal d-block"></i>
                                </button>
                                <button type="button"
                                        class="btn btn-outline-danger"
                                        id="pos-empty-cart-restaurant"
                                        title="{{ __('pos.empty_cart') }}"
                                        aria-label="{{ __('pos.empty_cart') }}"
                                        data-can-delete-sent="{{ !empty($canDeleteSent) ? '1' : '0' }}">
                                    <i class="ti ti-trash d-block"></i>
                                </button>
                                @if(!empty($canDeleteSent))
                                <button type="button" class="btn btn-danger d-none" id="pos-delete-selected-restaurant" title="{{ __('pos.delete_selected') }}">
                                    <i class="ti ti-trash me-1"></i>{{ __('pos.delete_selected') }}
                                </button>
                                <button type="button" class="btn btn-outline-secondary d-none" id="pos-cancel-delete-select-restaurant" title="{{ __('pos.cancel') }}">
                                    <i class="ti ti-x d-block"></i>
                                </button>
                                @endif
                                <button type="button" class="btn btn-outline-secondary" id="pos-view-bill-restaurant" title="{{ $tp('pos.view_bill', 'View Bill') }}">
                                    <i class="ti ti-file-invoice d-block"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="pos-status-legend" aria-label="{{ __('pos.status') }}">
                        <span class="pos-status-legend__item"><span class="pos-status-legend__dot pos-status-legend__dot--sent"></span>{{ __('pos.status_legend_sent') }}</span>
                        <span class="pos-status-legend__item"><span class="pos-status-legend__dot pos-status-legend__dot--prep"></span>{{ __('pos.status_legend_preparation') }}</span>
                        <span class="pos-status-legend__item"><span class="pos-status-legend__dot pos-status-legend__dot--done"></span>{{ __('pos.status_legend_done') }}</span>
                    </div>

                    <div class="card-body p-2 carttable carttable-scroll pos-cart-restaurant-wrap"
                         id="carthtml-restaurant" style="max-height: 55vh; overflow-y: auto; overflow-x: hidden;">
                        <table class="table table-sm pos-cart-table pos-cart-table--restaurant mb-0 w-100">
                            <tbody id="tbody-restaurant"></tbody>
                        </table>
                        <div class="pos-totals pos-totals--restaurant mt-2 pt-2 border-top">
                            <div class="pos-totals__row">
                                <span class="pos-totals__label">{{ $tp('pos.sub_total', 'Sub Total') }}</span>
                                <span id="pos-restaurant-subtotal" class="pos-totals__value">0</span>
                            </div>
                            <div class="pos-totals__row pos-totals__row--discount discount-wrap-restaurant"
                                 data-currency-symbol="{{ \Auth::user()->currencySymbol() }}">
                                <span class="pos-totals__label">{{ $tp('pos.discount', 'Discount') }}</span>
                                <div class="pos-totals__discount-controls">
                                    <select class="form-select form-select-sm pos-discount-type-restaurant"
                                            id="pos_discount_type_restaurant" aria-label="{{ __('pos.discount') }}">
                                        <option value="fixed">{{ __('Fixed') }}</option>
                                        <option value="percentage">{{ __('Percentage') }}</option>
                                    </select>
                                    <div class="input-group input-group-sm pos-totals__discount-input">
                                        <span class="input-group-text pos-discount-prefix-restaurant">{{ \Auth::user()->currencySymbol() }}</span>
                                        <input type="number" step="0.01" min="0"
                                               class="form-control pos-discount-value-restaurant"
                                               id="discount_restaurant" value="0" placeholder="0" inputmode="decimal">
                                    </div>
                                </div>
                            </div>
                            {{ Form::hidden('discount_hidden_restaurant', '0', ['id' => 'discount_hidden_restaurant']) }}
                            <div class="pos-totals__row pos-totals__row--total">
                                <span class="pos-totals__label">{{ $tp('pos.total', 'Total') }}</span>
                                <span id="pos-restaurant-total" class="pos-totals__value pos-totals__value--total">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pos-restaurant-placeholder" class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="ti ti-building-store" style="font-size: 3rem; opacity: 0.5;"></i>
                    <p class="mt-2 mb-0">{{ __('pos.select_branch_first') }}</p>
                </div>
            </div>
        </div>

        {{-- Right: Products --}}
        <div class="col-lg-8 col-md-7 order-1 order-lg-2">
            <div id="pos-restaurant-order-right" class="d-none">
                <div class="card border-0 shadow-sm h-100 pos-products-card">
                    <div class="card-header py-2 bg-light">
                        <div class="row g-2">
                            <div class="col-12">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text" style="padding:11px !important"><i class="ti ti-search"></i></span>
                                    <input type="text" class="form-control searchproduct-restaurant"
                                           placeholder="{{ __('pos.search_product') }}"
                                           data-url="{{ route('search.products') }}">
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="pos-category-scroll-wrap" id="pos-restaurant-category-scroll-wrap">
                                    <button type="button" class="btn pos-category-scroll-arrow pos-category-scroll-prev" aria-label="{{ __('Previous') }}"><i class="ti ti-chevron-left"></i></button>
                                    <div class="pos-category-scroll d-flex flex-nowrap gap-2" id="pos-restaurant-category-bar" role="toolbar" aria-label="{{ __('Category') }}"></div>
                                    <button type="button" class="btn pos-category-scroll-arrow pos-category-scroll-next" aria-label="{{ __('Next') }}"><i class="ti ti-chevron-right"></i></button>
                                </div>
                                <div class="pos-category-scroll-wrap d-none mt-2" id="pos-restaurant-subcategory-scroll-wrap">
                                    <button type="button" class="btn pos-category-scroll-arrow pos-category-scroll-prev" aria-label="{{ __('Previous') }}"><i class="ti ti-chevron-left"></i></button>
                                    <div class="pos-category-scroll d-flex flex-nowrap gap-2" id="pos-restaurant-subcategory-bar" role="toolbar" aria-label="{{ __('Subcategory') }}"></div>
                                    <button type="button" class="btn pos-category-scroll-arrow pos-category-scroll-next" aria-label="{{ __('Next') }}"><i class="ti ti-chevron-right"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <div class="product-body-nop">
                            <div class="form-row m-0" id="product-listing-restaurant"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{ Form::hidden('pos_restaurant_table_id', '', ['id' => 'pos_restaurant_table_id']) }}
{{ Form::hidden('pos_restaurant_table_name', '', ['id' => 'pos_restaurant_table_name']) }}
{{ Form::hidden('pos_shift_id_hidden', '', ['id' => 'pos_shift_id_hidden']) }}
{{ Form::hidden('pos_restaurant_warehouse_hidden', '', ['id' => 'pos_restaurant_warehouse_hidden']) }}
{{ Form::hidden('pos_restaurant_pos_id', '', ['id' => 'pos_restaurant_pos_id']) }}

{{-- ================= MODALS (same as before, no change) ================= --}}

{{-- Open table modal --}}
<div class="modal fade" id="posOpenTableModal" tabindex="-1" aria-labelledby="posOpenTableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posOpenTableModalLabel">{{ __('pos.open_table') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="pos-open-table-id" value="">
                <input type="hidden" id="pos-open-table-name" value="">
                <div class="mb-3">
                    <label class="form-label" for="pos-open-table-waiter">{{ __('pos.waiter_user') }}</label>
                    <select id="pos-open-table-waiter" class="form-select">
                        @foreach(($companyUsers ?? collect()) as $u)
                            <option value="{{ $u->id }}" @selected(isset($posPinUser) && (int) $posPinUser->id === (int) $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('pos.guests_count') }}</label>
                    <input type="number" id="pos-open-table-guests" class="form-control" min="0" value="" placeholder="{{ __('pos.optional') }}">
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Customer name') }}</label>
                    <input type="text" id="pos-open-table-customer-name" class="form-control" value="" placeholder="{{ __('pos.optional') }}" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="pos-open-table-confirm">{{ __('pos.open_table') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Table management modal --}}
<div class="modal fade" id="posTableManagementModal" tabindex="-1" aria-labelledby="posTableManagementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posTableManagementModalLabel">{{ __('pos.manage_tables') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="pos-table-management-body">
                <p class="text-muted small">{{ __('pos.select_branch_first') }}</p>
            </div>
        </div>
    </div>
</div>

{{-- Move table modal --}}
<div class="modal fade" id="posMoveTableModal" tabindex="-1" aria-labelledby="posMoveTableModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posMoveTableModalLabel">{{ __('pos.move_table') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">{{ __('pos.move_table_hint') }}</p>
                <p class="mb-2"><strong>{{ __('pos.from_table') }}:</strong> <span id="pos-move-from-table-name">—</span></p>
                <label class="form-label">{{ __('pos.select_target_table') }}</label>
                <div id="pos-move-table-list" class="border rounded p-2" style="max-height:220px;overflow-y:auto;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="pos-move-table-confirm">{{ __('pos.move_table') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Join table modal --}}
<div class="modal fade" id="posJoinTableModal" tabindex="-1" aria-labelledby="posJoinTableModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posJoinTableModalLabel">{{ __('pos.join_table') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">{{ __('pos.join_table_hint') }}</p>
                <label class="form-label">{{ __('pos.select_tables_to_join') }}</label>
                <div id="pos-join-table-list" class="border rounded p-2" style="max-height:220px;overflow-y:auto;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="pos-join-table-confirm">{{ __('pos.join_tables') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Split bill modal --}}
<div class="modal fade" id="posSplitBillModal" tabindex="-1" aria-labelledby="posSplitBillModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posSplitBillModalLabel">{{ __('pos.split_bill') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="pos-split-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pos-split-equal-tab" data-bs-toggle="tab" data-bs-target="#pos-split-equal-pane" type="button" role="tab">{{ __('pos.split_equal') }}</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="pos-split-items-tab" data-bs-toggle="tab" data-bs-target="#pos-split-items-pane" type="button" role="tab">{{ __('pos.split_by_items') }}</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pos-split-equal-pane" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.total') }}</label>
                            <div id="pos-split-total-display" class="fw-bold fs-5 text-primary">0</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.split_into_parts') }}</label>
                            <input type="number" id="pos-split-parts-input" class="form-control" min="2" value="2" step="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.amount_per_person') }}</label>
                            <div id="pos-split-per-person" class="fw-bold text-success">0</div>
                        </div>
                        <p class="text-muted small mb-0">{{ __('pos.split_equal_hint') }}</p>
                    </div>
                    <div class="tab-pane fade" id="pos-split-items-pane" role="tabpanel">
                        <p class="text-muted small">{{ __('pos.split_by_items_hint') }}</p>
                        <div id="pos-split-items-list" class="border rounded p-2 mb-3" style="max-height:200px;overflow-y:auto;"></div>
                        <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
                            <div class="flex-grow-1">
                                <label class="form-label">{{ __('pos.guest_label') }}</label>
                                <input type="text" id="pos-split-guest-label" class="form-control form-control-sm" placeholder="{{ __('pos.guest_label_placeholder') }}">
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="pos-split-add-guest">{{ __('pos.add_guest_check') }}</button>
                        </div>
                        <div id="pos-split-guest-groups" class="d-flex flex-column gap-2"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="pos-split-apply-btn">{{ __('pos.apply') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Void line modal --}}
@if($canShowVoid ?? false)
<div class="modal fade" id="posVoidLineModal" tabindex="-1" aria-labelledby="posVoidLineModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posVoidLineModalLabel">{{ __('pos.void') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="pos-void-line-name"></p>
                <label class="form-label">{{ __('Reason') }} <span class="text-danger">*</span></label>
                <textarea id="pos-void-reason-input" class="form-control" rows="3" maxlength="500" placeholder="{{ __('pos.void_reason_required') }}"></textarea>
                <div class="mt-3">
                    <label class="form-label" for="pos-void-password-input">{{ __('pos.void_password_label') }} @if(empty($canVoidDirectly))<span class="text-danger">*</span>@endif</label>
                    <input type="password" id="pos-void-password-input" class="form-control" autocomplete="new-password" placeholder="{{ __('pos.void_password_label') }}">
                    @if(!empty($canVoidDirectly))
                        <small class="text-muted">{{ __('pos.void_password_optional_manager') }}</small>
                    @endif
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-warning" id="pos-void-confirm-btn">{{ __('pos.void') }}</button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Preparation time modal --}}
<div class="modal fade" id="posPrepTimeModal" tabindex="-1" aria-labelledby="posPrepTimeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posPrepTimeModalLabel">{{ __('Preparation time') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="pos-prep-minutes-input">{{ __('Minutes until ready') }}</label>
                <input type="number" id="pos-prep-minutes-input" class="form-control" min="1" max="480" step="1" value="{{ (int) ($posDefaultPrepMinutes ?? 15) }}">
                <small class="text-muted">{{ __('If prep exceeds this time before Ready / delivery, an overtime alert is shown on Production.') }}</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-success" id="pos-prep-time-confirm-btn">
                    <i class="ti ti-send me-1"></i>{{ __('Send to production') }}
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Kitchen / Bar printer configuration (agent printers) --}}
<div class="modal fade" id="posPrinterConfigModal" tabindex="-1" aria-labelledby="posPrinterConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posPrinterConfigModalLabel">{{ __('Printer Settings') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between mb-3 p-2 border rounded bg-light gap-2 flex-wrap">
                    <span class="fw-semibold">{{ __('Printer Agent Status') }}</span>
                    <div class="d-flex align-items-center gap-2">
                        <span id="pos-printer-agent-status-badge" class="badge bg-secondary">{{ __('Offline') }}</span>
                        <a href="{{ asset('downloads/print-agent/PosPrinterAgent.zip') }}"
                           class="btn btn-sm btn-outline-success"
                           id="pos-download-print-agent-btn"
                           download="PosPrinterAgent.zip">
                            <i class="ti ti-download me-1"></i>{{ __('pos.download_print_agent') }}
                        </a>
                    </div>
                </div>
                <p class="text-muted small mb-3">
                    {{ __('pos.download_print_agent_help') }}
                </p>
                <p class="text-muted small mb-3">
                    {{ __('Select Kitchen and Bar printers discovered by the printer agent on this branch.') }}
                </p>
                @unless($canChangePosPrintSettings ?? false)
                    <p class="text-muted small mb-3">{{ __('View only for :name. Change POS print settings is not on this user\'s role.', ['name' => $posPinUser->name ?? __('this user')]) }}</p>
                @endunless
                <div class="mb-3">
                    <label class="form-label" for="pos-kitchen-printer-select">{{ __('Kitchen Printer') }}</label>
                    <select id="pos-kitchen-printer-select" class="form-select" @unless($canChangePosPrintSettings ?? false) disabled @endunless>
                        <option value="">{{ __('Select printer') }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="pos-bar-printer-select">{{ __('Bar Printer') }}</label>
                    <select id="pos-bar-printer-select" class="form-select" @unless($canChangePosPrintSettings ?? false) disabled @endunless>
                        <option value="">{{ __('Select printer') }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="pos-default-printer-select">{{ __('Default Printer for Other Prints') }}</label>
                    <select id="pos-default-printer-select" class="form-select" @unless($canChangePosPrintSettings ?? false) disabled @endunless>
                        <option value="">{{ __('Select printer') }}</option>
                    </select>
                    <small class="text-muted">{{ __('Used for bills, invoices, receipts, and other non-kitchen/bar prints via the Print Agent.') }}</small>
                </div>
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="pos-use-print-agent-invoice" value="1" @unless($canChangePosPrintSettings ?? false) disabled @endunless>
                    <label class="form-check-label" for="pos-use-print-agent-invoice">{{ __('pos.use_print_agent_for_invoice') }}</label>
                    <div class="form-text">{{ __('pos.use_print_agent_for_invoice_help') }}</div>
                </div>
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="pos-save-image-when-offline" value="1" @unless($canChangePosPrintSettings ?? false) disabled @endunless>
                    <label class="form-check-label" for="pos-save-image-when-offline">{{ __('pos.save_image_when_offline') }}</label>
                    <div class="form-text">{{ __('pos.save_image_when_offline_help') }}</div>
                </div>
                <div id="pos-printer-config-empty" class="alert alert-warning d-none mb-0">
                    {{ __('Install and start the printer agent to sync printers. Until then, browser printing remains available as a fallback.') }}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                @if($canChangePosPrintSettings ?? false)
                    <button type="button" class="btn btn-primary" id="pos-printer-config-save-btn">
                        <i class="ti ti-device-floppy me-1"></i>{{ __('Save') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
