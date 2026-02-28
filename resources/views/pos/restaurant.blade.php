{{-- Restaurant Mode: Table-based POS with kitchen workflow --}}
@php
    $lastsegment = request()->segment(count(request()->segments()));
    $restaurantBranchIds = $restaurantBranchIds ?? [];
    $restaurantBranches = isset($branches) ? $branches->only($restaurantBranchIds) : collect();
    $restaurantWarehouses = $warehouses ?? collect();
    $shiftBranchId = (isset($userOpenShift) && $userOpenShift && $userOpenShift->branch_id) ? $userOpenShift->branch_id : null;
@endphp
<div class="pos-restaurant-layout">
    {{-- Top bar: Branch, Warehouse, Selected table + Tables grid --}}
    <div class="pos-restaurant-top card border-0 shadow-sm mb-3">
        <div class="card-body py-2 px-3">
            <div class="row align-items-center mb-2">
                <div class="col-md-3 m-0">
                    <label class="form-label small text-muted mb-0">{{ __('pos.branch') }}@if(!empty($posBranchLocked)) <span class="text-muted">({{ __('Assigned') }})</span>@endif</label>
                    <select class="form-control form-control-sm" id="pos-restaurant-branch" style="min-width: 140px;" @if(!empty($posBranchLocked)) disabled @endif>
                        <option value="">{{ __('pos.select_branch') }}</option>
                        @foreach($restaurantBranches as $id => $name)
                            <option value="{{ $id }}" {{ ($shiftBranchId && (string)$id === (string)$shiftBranchId) || (!empty($posBranchLocked) && $restaurantBranches->count() === 1) ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-0">{{ __('pos.select_warehouse') }}</label>
                    <select class="form-control form-control-sm" id="pos-restaurant-warehouse" style="min-width: 140px;">
                        <option value="">{{ __('pos.select_warehouse') }}</option>
                        @foreach($restaurantWarehouses as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted d-block" id="pos-restaurant-warehouse-hint">{{ __('pos.select_branch_first') }}</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-0">{{ __('pos.order_type') }}</label>
                    <select class="form-control form-control-sm" id="pos-restaurant-order-type" style="min-width: 100px;">
                        <option value="dine_in">{{ __('pos.dine_in') }}</option>
                        <option value="delivery">{{ __('pos.delivery') }}</option>
                        <option value="takeaway">{{ __('pos.takeaway') }}</option>
                    </select>
                </div>
                <div id="pos-restaurant-table-block" class="col-md-2 d-flex align-items-end gap-1">
                    <div>
                        <label class="form-label small text-muted mb-0">{{ __('pos.select_table') }}</label>
                        <div id="pos-selected-table" class="fw-bold text-primary">—</div>
                    </div>
                    @if($canShowPayButton ?? true)
                    <button type="button" class="btn btn-sm btn-outline-danger d-none" id="pos-close-table-btn" title="{{ __('pos.close_table') }} ({{ __('pos.manager_emergency') }})">
                        <i class="ti ti-door-exit"></i>
                    </button>
                    @endif
                </div>
                <div id="pos-restaurant-guest-block" class="col-md-4 d-none">
                    <label class="form-label small text-muted mb-0">{{ __('pos.customer_details_required') }}</label>
                    <div class="row g-1">
                        <div class="col-4"><input type="text" class="form-control form-control-sm" id="pos-guest-name" placeholder="{{ __('pos.guest_name') }}" maxlength="255"></div>
                        <div class="col-4"><input type="email" class="form-control form-control-sm" id="pos-guest-email" placeholder="{{ __('pos.guest_email') }}" maxlength="255"></div>
                        <div class="col-4"><input type="text" class="form-control form-control-sm" id="pos-guest-phone" placeholder="{{ __('pos.guest_phone') }}" maxlength="64"></div>
                    </div>
                </div>
                <div class="col-md-2 ms-auto" id="pos-restaurant-manage-tables-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="pos-manage-tables-btn" title="{{ __('pos.manage_tables') }}">
                        <i class="ti ti-settings"></i> {{ __('pos.manage_tables') }}
                    </button>
                </div>
            </div>
            <div id="pos-restaurant-table-grid-wrap" class="border-top pt-2">
                <div class="d-flex flex-wrap gap-2 align-items-center" id="pos-table-grid">
                    <p class="text-muted small mb-0">{{ __('pos.select_branch_first') }}</p>
                </div>
            </div>
            <div id="pos-restaurant-customer-grid-wrap" class="border-top pt-2 d-none">
                <label class="form-label small text-muted mb-1">{{ __('pos.select_customer') }}</label>
                <div class="d-flex flex-wrap gap-2 align-items-center" id="pos-customer-grid">
                    <p class="text-muted small mb-0">{{ __('pos.select_branch_first') }}</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="pos-new-delivery-takeaway-btn">
                    <i class="ti ti-plus"></i> <span id="pos-new-delivery-takeaway-label">{{ __('pos.new_delivery') }}</span>
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
                        <span class="fw-semibold">{{ __('pos.pos_invoice') }}</span>
                        <div class="d-flex gap-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-success" id="pos-send-to-production" title="{{ __('Send to production') }}"><i class="ti ti-send"></i> {{ __('Send to production') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="pos-print-kitchen-slip" title="{{ __('pos.print_kitchen_slip') }}"><i class="ti ti-printer"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pos-open-drawer-btn" title="{{ __('pos.open_drawer') }}"><i class="ti ti-cash"></i> {{ __('pos.open_drawer') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="pos-join-table-restaurant" title="{{ __('pos.join_table') }}"><i class="ti ti-table-plus"></i> {{ __('pos.join_table') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" id="pos-split-bill-restaurant" title="{{ __('pos.split_bill') }}"><i class="ti ti-separator-horizontal"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="pos-empty-cart-restaurant" title="{{ __('pos.empty_cart') }}"><i class="ti ti-trash"></i></button>
                            @if($canShowPayButton ?? true)
                            <button type="button" id="pos-pay-btn-restaurant" class="btn btn-sm btn-primary" data-size="xl" data-url="{{ route('pos.create') }}" data-title="{{ __('pos.pos_invoice') }}" disabled>{{ __('pos.pay') }}</button>
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
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">{{ __('pos.total') }}</span>
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
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" style="padding:11px !important"><i class="ti ti-search"></i></span>
                            <input type="text" class="form-control searchproduct-restaurant" placeholder="{{ __('pos.search_product') }}" data-url="{{ route('search.products') }}">
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <div class="button-list b-bottom catgory-pad mb-2">
                            <div class="form-row m-0" id="categories-listing-restaurant"></div>
                        </div>
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
