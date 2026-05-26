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
<div class="pos-restaurant-layout">
    @if(!empty($posBranchAssignmentWarning))
        <div class="alert alert-warning mb-3 py-2 small">{{ $posBranchAssignmentWarning }}</div>
    @endif
    {{-- Top bar: Branch, Warehouse, Selected table + Tables grid --}}
    <div class="pos-restaurant-top card border-0 shadow-sm mb-3">
        <div class="card-body py-2 px-3">
            <div class="pos-restaurant-controls-row d-flex flex-nowrap align-items-end gap-2 mb-2">
                <div class="pos-restaurant-controls-main d-flex flex-nowrap align-items-end gap-2 flex-grow-1 min-w-0 overflow-x-auto">
                    <div class="pos-restaurant-control-item flex-shrink-0">
                        <label class="form-label small text-muted mb-0 text-nowrap">{{ $tp('pos.branch', 'Branch') }}@if(!empty($posBranchLocked)) <span class="text-muted">({{ __('Assigned') }})</span>@endif</label>
                        <select class="form-control form-control-sm" id="pos-restaurant-branch" style="min-width: 120px; max-width: 160px;" @if(!empty($posBranchLocked)) disabled @endif>
                            <option value="">{{ $tp('pos.select_branch', 'Select Branch') }}</option>
                            @foreach($restaurantBranches as $id => $name)
                                <option value="{{ $id }}" {{ ($posAssignedBranchId && (string)$id === (string)$posAssignedBranchId) || (!$posAssignedBranchId && $shiftBranchId && (string)$id === (string)$shiftBranchId) || (!empty($posBranchLocked) && $restaurantBranches->count() === 1) ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pos-restaurant-control-item flex-shrink-0">
                        <label class="form-label small text-muted mb-0 text-nowrap">{{ $tp('pos.select_warehouse', 'Select Warehouse') }}</label>
                        <select class="form-control form-control-sm" id="pos-restaurant-warehouse" style="min-width: 110px; max-width: 150px;">
                            <option value="">{{ $tp('pos.select_warehouse', 'Select Warehouse') }}</option>
                            @foreach($restaurantWarehouses as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted d-block text-nowrap" id="pos-restaurant-warehouse-hint" style="font-size: 0.7rem;">{{ $tp('pos.select_branch_first', 'Select branch first') }}</small>
                    </div>
                    <div class="pos-restaurant-control-item flex-shrink-0">
                        <label class="form-label small text-muted mb-0 text-nowrap">{{ $tp('pos.order_type', 'Order Type') }}</label>
                        <select class="form-control form-control-sm" id="pos-restaurant-order-type" style="min-width: 95px;">
                            <option value="dine_in">{{ $tp('pos.dine_in', 'Dine In') }}</option>
                            <option value="delivery">{{ $tp('pos.delivery', 'Delivery') }}</option>
                            <option value="takeaway">{{ $tp('pos.takeaway', 'Takeaway') }}</option>
                        </select>
                    </div>
                    <div id="pos-restaurant-table-block" class="pos-restaurant-control-item flex-shrink-0 d-flex align-items-end gap-1 flex-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="pos-open-table-panel-btn" aria-expanded="false" aria-controls="pos-restaurant-table-grid-wrap" title="{{ $tp('pos.open_table', 'Open Table') }}">
                            <i class="ti ti-layout-grid me-1"></i>{{ $tp('pos.open_table', 'Open Table') }}
                        </button>
                        <div class="text-nowrap">
                            <label class="form-label small text-muted mb-0">{{ $tp('pos.select_table', 'Select Table') }}</label>
                            <div id="pos-selected-table" class="fw-bold text-primary">—</div>
                        </div>
                        @if($canShowPayButton ?? true)
                        <button type="button" class="btn btn-sm btn-outline-danger d-none flex-shrink-0" id="pos-close-table-btn" title="{{ $tp('pos.close_table', 'Close Table') }} ({{ $tp('pos.manager_emergency', 'Manager Emergency') }})">
                            <i class="ti ti-door-exit"></i>
                        </button>
                        @endif
                    </div>
                    <div id="pos-restaurant-guest-block" class="pos-restaurant-control-item flex-shrink-0 d-none">
                        <label class="form-label small text-muted mb-0 text-nowrap d-block">{{ $tp('pos.customer_details_required', 'Customer Details Required') }}</label>
                        <div class="d-flex flex-nowrap gap-1">
                            <input type="text" class="form-control form-control-sm" id="pos-guest-name" placeholder="{{ $tp('pos.guest_name', 'Guest Name') }}" maxlength="255" style="min-width: 100px;">
                            <input type="email" class="form-control form-control-sm" id="pos-guest-email" placeholder="{{ $tp('pos.guest_email', 'Guest Email') }}" maxlength="255" style="min-width: 110px;">
                            <input type="text" class="form-control form-control-sm" id="pos-guest-phone" placeholder="{{ $tp('pos.guest_phone', 'Guest Phone') }}" maxlength="64" style="min-width: 95px;">
                        </div>
                    </div>
                </div>
                <div class="pos-restaurant-control-item flex-shrink-0" id="pos-restaurant-manage-tables-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" id="pos-manage-tables-btn" title="{{ $tp('pos.manage_tables', 'Manage Tables') }}">
                        <i class="ti ti-settings"></i> {{ $tp('pos.manage_tables', 'Manage Tables') }}
                    </button>
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
                    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2 bg-light">
                        <span class="fw-semibold">{{ $tp('pos.pos_invoice', 'POS Invoice') }}</span>
                        <div class="d-flex gap-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-success" id="pos-send-to-production" title="{{ __('Send to production') }}"><i class="ti ti-send"></i> {{ __('Send to production') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="pos-print-kitchen-slip" title="{{ __('pos.print_kitchen_slip') }}"><i class="ti ti-printer"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pos-open-drawer-btn" title="{{ $tp('pos.open_drawer', 'Open Drawer') }}"><i class="ti ti-cash"></i> {{ $tp('pos.open_drawer', 'Open Drawer') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="pos-join-table-restaurant" title="{{ $tp('pos.join_table', 'Join Table') }}"><i class="ti ti-table-plus"></i> {{ $tp('pos.join_table', 'Join Table') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" id="pos-split-bill-restaurant" title="{{ __('pos.split_bill') }}"><i class="ti ti-separator-horizontal"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="pos-empty-cart-restaurant" title="{{ __('pos.empty_cart') }}"><i class="ti ti-trash"></i></button>
                            <button type="button" id="pos-view-bill-restaurant" class="btn btn-sm btn-outline-secondary" title="{{ $tp('pos.view_bill', 'View Bill') }}"><i class="ti ti-file-invoice me-1"></i>{{ $tp('pos.view_bill', 'View Bill') }}</button>
                            @if($canShowPayButton ?? true)
                            <button type="button" id="pos-pay-btn-restaurant" class="btn btn-sm btn-primary" data-size="xl" data-url="{{ route('pos.create') }}" data-title="{{ $tp('pos.pos_invoice', 'POS Invoice') }}" disabled>{{ $tp('pos.pay', 'Pay') }}</button>
                            @endif
                        </div>
                    </div>
                    <div class="card-body p-2 carttable carttable-scroll" id="carthtml-restaurant" style="max-height: 55vh; overflow-y: auto;">
                        <style>
                            .pos-cart-table tbody tr.pos-cart-row-sent { background-color: #ffcdd2 !important; }
                            .pos-cart-table tbody tr.pos-cart-row-preparation { background-color: #ffe0b2 !important; }
                            .pos-cart-table tbody tr.pos-cart-row-ready { background-color: #c8e6c9 !important; }
                            .pos-cart-table tbody tr.pos-cart-row-cancelled { background-color: #eeeeee !important; }
                            .pos-item-notes {
                                min-height: 2.25rem; font-size: 0.8125rem; border-radius: 6px;
                                background-color: #f8fafc; border: 1px solid #e2e8f0;
                                transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
                                resize: none;
                            }
                            .pos-item-notes::placeholder { color: #94a3b8; }
                            .pos-item-notes:hover { background-color: #f1f5f9; border-color: #cbd5e1; }
                            .pos-item-notes:focus {
                                background-color: #fff; border-color: var(--bs-primary, #0d6efd);
                                box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.12);
                                outline: 0;
                            }
                            .pos-item-note-readonly {
                                font-size: 0.8125rem; color: #475569; background: #f1f5f9;
                                border-radius: 6px; padding: 0.35rem 0.5rem; min-height: 2rem;
                                border-left: 3px solid var(--bs-primary, #0d6efd);
                                line-height: 1.35; white-space: pre-wrap; word-break: break-word;
                            }
                            .pos-cart-table .pos-note-cell { vertical-align: middle; min-width: 140px; max-width: 180px; }
                        </style>
                        <div class="table-responsive">
                            <table class="table table-sm pos-cart-table mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-left">{{ __('pos.name') }}</th>
                                        <th class="text-left pos-note-cell"><span class="text-muted small">{{ __('Note') }}</span></th>
                                        <th class="text-center">{{ __('pos.qty') }}</th>
                                        <th>{{ __('pos.tax') }}</th>
                                        <th class="text-center">{{ __('pos.price') }}</th>
                                        <th class="text-center">{{ __('pos.sub_total') }}</th>
                                        <th class="text-center">{{ __('pos.status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-restaurant"></tbody>
                            </table>
                        </div>
                        <div class="pos-totals mt-2 pt-2 border-top">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold">{{ $tp('pos.sub_total', 'Sub Total') }}</span>
                                <span id="pos-restaurant-subtotal" class="fw-semibold">0</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 align-items-center flex-wrap gap-1 discount-wrap-restaurant" data-currency-symbol="{{ \Auth::user()->currencySymbol() }}">
                                <span class="fw-semibold">{{ $tp('pos.discount', 'Discount') }}</span>
                                <div class="d-flex align-items-center gap-1 flex-nowrap">
                                    <select class="form-select form-select-sm pos-discount-type-restaurant" id="pos_discount_type_restaurant" style="width: auto; min-width: 95px;">
                                        <option value="fixed">{{ __('Fixed') }}</option>
                                        <option value="percentage">{{ __('Percentage') }}</option>
                                    </select>
                                    <div class="input-group input-group-sm" style="max-width: 100px;">
                                        <span class="input-group-text bg-transparent pos-discount-prefix-restaurant">{{ \Auth::user()->currencySymbol() }}</span>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm pos-discount-value-restaurant" id="discount_restaurant" value="0" placeholder="0">
                                    </div>
                                </div>
                            </div>
                            {{ Form::hidden('discount_hidden_restaurant', '0', ['id' => 'discount_hidden_restaurant']) }}
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ $tp('pos.total', 'Total') }}</span>
                                <span id="pos-restaurant-total" class="fw-bold text-primary">0</span>
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
                                    <input type="text" class="form-control searchproduct-restaurant" placeholder="{{ __('pos.search_product') }}" data-url="{{ route('search.products') }}">
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

{{-- Open table modal (guest count) --}}
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
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="posSplitBillModalLabel">{{ __('pos.split_bill') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="pos-split-apply-btn">{{ __('pos.apply') }}</button>
            </div>
        </div>
    </div>
</div>

{{-- Void line modal (Manager/Cashier only) --}}
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                <button type="button" class="btn btn-warning" id="pos-void-confirm-btn">{{ __('pos.void') }}</button>
            </div>
        </div>
    </div>
</div>
@endif
