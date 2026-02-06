{{-- Restaurant Mode: Table-based POS with kitchen workflow --}}
@php
    $lastsegment = request()->segment(count(request()->segments()));
    $restaurantBranchIds = $restaurantBranchIds ?? [];
    $restaurantBranches = isset($branches) ? $branches->only($restaurantBranchIds) : collect();
    $restaurantWarehouses = $warehouses ?? collect();
@endphp
<div class="pos-restaurant-layout">
    <div class="row mb-3">
        <div class="col-md-3">
            <label class="form-label">{{ __('pos.branch') }}</label>
            <select class="form-control" id="pos-restaurant-branch">
                <option value="">{{ __('pos.select_branch') }}</option>
                @foreach($restaurantBranches as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('pos.select_warehouse') }}</label>
            <select class="form-control" id="pos-restaurant-warehouse">
                <option value="">{{ __('pos.select_warehouse') }}</option>
                @foreach($restaurantWarehouses as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <small class="text-muted" id="pos-restaurant-warehouse-hint">{{ __('pos.select_branch_first') }}</small>
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('pos.waiter_shift') }}</label>
            <select class="form-control" id="pos-shift-select" data-current-user-id="{{ auth()->id() }}">
                <option value="">{{ __('pos.select_shift') }}</option>
            </select>
            <small class="text-muted" id="pos-shift-hint">{{ __('pos.select_branch_first') }}</small>
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('pos.select_table') }}</label>
            <div id="pos-selected-table" class="form-control-plaintext fw-bold text-primary">—</div>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-4 col-md-5">
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span>{{ __('pos.select_table') }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="pos-manage-tables-btn" title="{{ __('pos.manage_tables') }}">
                        <i class="ti ti-settings"></i> {{ __('pos.manage_tables') }}
                    </button>
                </div>
                <div class="card-body p-2" id="pos-table-grid">
                    <p class="text-muted small mb-0">{{ __('pos.select_branch_first') }}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-8 col-md-7">
            <div id="pos-restaurant-order" class="d-none">
                <div class="row">
                    <div class="col-12">
                        <div class="sop-card pos-products-card card mb-2">
                            <div class="card-header p-2">
                                <div class="pos-search-bar">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="ti ti-search"></i></span>
                                        <input type="text" class="form-control searchproduct-restaurant" placeholder="{{ __('pos.search_product') }}" data-url="{{ route('search.products') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <div class="right-content">
                                    <div class="button-list b-bottom catgory-pad mb-3">
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
                <div class="row">
                    <div class="col-12">
                        <div class="card pos-cart-card">
                            <div class="card-header p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span>{{ __('pos.pos_invoice') }}</span>
                                <div class="d-flex gap-1 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="pos-print-kitchen-slip" title="{{ __('pos.print_kitchen_slip') }}">
                                        <i class="ti ti-printer"></i> {{ __('pos.kitchen_slip') }}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" id="pos-split-bill-restaurant" title="{{ __('pos.split_bill') }}">
                                        <i class="ti ti-separator-horizontal"></i> {{ __('pos.split_bill') }}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="pos-empty-cart-restaurant" title="{{ __('pos.empty_cart') }}">
                                        <i class="ti ti-trash"></i> {{ __('pos.empty_cart') }}
                                    </button>
                                    <button type="button" id="pos-pay-btn-restaurant" class="btn btn-sm btn-primary" data-size="xl" data-url="{{ route('pos.create') }}" data-title="{{ __('pos.pos_invoice') }}" disabled>
                                        {{ __('pos.pay') }}
                                    </button>
                                </div>
                            </div>
                            <div class="card-body carttable" id="carthtml-restaurant">
                                <div class="table-responsive">
                                    <table class="table pos-cart-table">
                                        <thead>
                                            <tr>
                                                <th></th>
                                                <th class="text-left">{{ __('pos.name') }}</th>
                                                <th class="text-center">{{ __('pos.qty') }}</th>
                                                <th>{{ __('pos.tax') }}</th>
                                                <th class="text-center">{{ __('pos.price') }}</th>
                                                <th class="text-center">{{ __('pos.sub_total') }}</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbody-restaurant"></tbody>
                                    </table>
                                </div>
                                <div class="pos-totals mt-2">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>{{ __('pos.total') }}:</span>
                                        <span id="pos-restaurant-total" class="fw-bold">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="pos-restaurant-placeholder" class="text-center py-5 text-muted">
                <i class="ti ti-building-store" style="font-size: 3rem;"></i>
                <p class="mt-2">{{ __('pos.select_branch_first') }}</p>
            </div>
        </div>
    </div>
</div>
{{ Form::hidden('pos_restaurant_table_id', '', ['id' => 'pos_restaurant_table_id']) }}
{{ Form::hidden('pos_restaurant_table_name', '', ['id' => 'pos_restaurant_table_name']) }}
{{ Form::hidden('pos_shift_id_hidden', '', ['id' => 'pos_shift_id_hidden']) }}
{{ Form::hidden('pos_restaurant_warehouse_hidden', '', ['id' => 'pos_restaurant_warehouse_hidden']) }}

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
