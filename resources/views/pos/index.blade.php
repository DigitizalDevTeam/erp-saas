@php
    $logo = \App\Models\Utility::get_file('uploads/logo');
    $company_favicon = Utility::getValByName('company_favicon');
    $SITE_RTL = Utility::getValByName('SITE_RTL');
    $setting = \App\Models\Utility::colorset();
    $color = 'theme-3';
    if (!empty($setting['color'])) {
        $color = $setting['color'];
    }

    if (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $themeColor = 'theme-3';
    } else {
        $themeColor = $color;
    }

@endphp
<!DOCTYPE html>

<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="{{ asset('pos-manifest.json') }}">
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}">
    <title>
        {{ !empty($companySettings['header_text']) ? $companySettings['header_text']->value : config('app.name', 'ERP SaaS') }}
        - {{ __('POS Retail') }}</title>

    <link rel="icon"
        href="{{ asset(Storage::url('uploads/logo/')) . '/' . (isset($companySettings['company_favicon']) && !empty($companySettings['company_favicon']) ? $companySettings['company_favicon']->value : 'favicon.png') }}"
        type="image" sizes="16x16">
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}" id="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- font css -->
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/material.css') }}">

    <!--bootstrap switch-->
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/bootstrap-switch-button.min.css') }}">

    <!-- vendor css -->
    @if ($SITE_RTL == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-rtl.css') }}">
    @endif
    @if ($setting['cust_darklayout'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link">
    @endif

    <link rel="stylesheet" href="{{ asset('assets/css/customizer.css') }}">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}" id="main-style-link">

    <style>
        .main_div{
            position: relative;
        }
        .disabled_div{
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 1000;
            background: #c2c2c28c;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .pos-top-bar { padding: 0.6rem 1rem; align-items: center; border-radius: 0.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .pos-top-bar .pos-brand { font-weight: 700; font-size: 1.25rem; letter-spacing: 0.02em; }
        .pos-cart-card .card-header { font-weight: 600; }
        .pos-cart-card .total-section { background: rgba(0,0,0,0.03); border-radius: 0.5rem; padding: 1rem; }
        .pl-1{
            padding-left: 3px !important;
        }
        .pr-1{
            padding-right: 3px !important;
        }
        .input-group-text{
            padding: 15px !important;
        }
        .carttable {
            padding: 8px;
        }
        @media(max-width: 500px)
        {
             .pl-1{
                padding-left: 12px !important;
            }
            .pr-1{
                padding-right: 12px !important;
            }
        }
        @media print {
            .no-print { display: none !important; }
            body * { visibility: hidden; }
            #commonModal, #commonModal .modal-dialog, #commonModal .modal-content, #commonModal .body,
            #commonModal #printarea, #commonModal #printarea * { visibility: visible; }
            #commonModal { position: absolute !important; left: 0; top: 0; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
            #commonModal .modal-header { display: none !important; }
            #commonModal .modal-dialog { max-width: 100% !important; margin: 0 !important; box-shadow: none !important; }
            #commonModal .modal-content { border: none !important; box-shadow: none !important; }
            #commonModal .modal-footer { display: none !important; }
            #commonModal .body { padding: 0 !important; border: none !important; }
            #commonModal #printarea { position: static !important; width: 100%; padding: 0.5in; font-size: 12pt; }
            #commonModal #printarea .pos-module-tbl { width: 100%; }
            #commonModal #printarea .product-border { border-bottom: 1px solid #ddd !important; padding: 2px 0; }
        }
    </style>
</head>


<body class="{{ $themeColor }}">
    <div id="pos-offline-banner" class="alert alert-warning py-2 mb-0 rounded-0 text-center d-none" role="alert" style="z-index: 9998;">
        <span class="ti ti-wifi-off me-1"></span> {{ __('pos.offline_banner') }}
    </div>
@php
    $userOpenShift = $userOpenShift ?? null;
@endphp
    <div class="pos-page container-fluid px-2">
        <?php $lastsegment = request()->segment(count(request()->segments())); ?>
        <div class="row">
            <div class="col-12">
                <div class="mt-2 pos-top-bar bg-primary d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="text-white pos-brand">{{ __('POS Retail') }}</span>
                        @if($userOpenShift)
                            <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.opening_balance') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->opening_balance ?? 0) }}</span>
                            @if(isset($userOpenShift->closing_balance) && $userOpenShift->closing_balance !== null && $userOpenShift->closing_balance !== '')
                                <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.closing_balance') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->closing_balance) }}</span>
                            @else
                                <a href="#" data-url="{{ route('pos.add_closing_balance') }}" data-bs-toggle="tooltip" title="{{ __('pos.add_closing_balance') }}" data-ajax-popup="true" data-title="{{ __('pos.add_closing_balance') }}" class="btn btn-sm btn-light">{{ __('pos.add_closing_balance') }}</a>
                            @endif
                        @else
                            <button type="button" class="btn btn-sm btn-light ms-2" id="pos-open-shift-btn" title="{{ __('pos.open_shift') }}">
                                <i class="ti ti-login"></i> {{ __('pos.open_shift') }}
                            </button>
                        @endif
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span id="pos-current-time" class="text-white opacity-90 align-middle" style="min-width: 5rem;">--:--:--</span>
                        <span id="pos-status-badge" class="badge bg-success align-middle" style="font-size: 0.75rem;">{{ __('pos.online') }}</span>
                        <span id="pos-pending-badge" class="badge bg-warning text-dark d-none" style="font-size: 0.8rem; cursor: pointer;" data-msg="{{ __('pos.pending_sync_label') }}" title="{{ __('pos.sync_now') }}"></span>
                        <button type="button" id="pos-install-app-btn" class="btn btn-sm btn-light" title="{{ __('pos.install_app') }}">
                            <i class="ti ti-download" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('pos.install_app') }}</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-light pos-open-drawer-btn" title="{{ __('pos.open_drawer') }}">
                            <i class="ti ti-cash" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('pos.open_drawer') }}</span>
                        </button>
                        <div class="dropdown d-inline-block">
                            <button type="button" class="btn btn-sm btn-light dropdown-toggle" id="pos-reports-dropdown" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('Reports & more') }}">
                                <i class="ti ti-report" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('Reports') }}</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="pos-reports-dropdown">
                                <li><h6 class="dropdown-header">{{ __('Reports') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.report') }}" target="_blank"><i class="ti ti-summary me-2"></i>{{ __('pos.pos_summary') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.balances') }}" target="_blank"><i class="ti ti-wallet me-2"></i>{{ __('pos.pos_balances') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.daily.pos') }}" target="_blank"><i class="ti ti-calendar me-2"></i>{{ __('POS Daily/Monthly Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.branch') }}" target="_blank"><i class="ti ti-building-store me-2"></i>{{ __('POS by Branch') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.vs.purchase') }}" target="_blank"><i class="ti ti-chart-bar me-2"></i>{{ __('Pos VS Purchase Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.day-summary', ['date' => date('Y-m-d')]) }}" target="_blank"><i class="ti ti-calendar-event me-2"></i>{{ __('Day Summary') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.eod', ['date' => date('Y-m-d')]) }}" target="_blank"><i class="ti ti-calendar-off me-2"></i>{{ __('pos.day_closed') }} / {{ __('EOD Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.low-stock') }}" target="_blank"><i class="ti ti-box me-2"></i>{{ __('Low Stock') }}</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">{{ __('Other') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.barcode') }}" target="_blank"><i class="ti ti-barcode me-2"></i>{{ __('pos.pos_product_barcode') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.setting') }}" target="_blank"><i class="ti ti-settings me-2"></i>{{ __('pos.barcode_setting') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.print.setting') }}" target="_blank"><i class="ti ti-printer me-2"></i>{{ __('Print') }} {{ __('Setting') }}</a></li>
                            </ul>
                        </div>
                        <a href="{{ route('pos.restaurant.index') }}" class="btn btn-sm btn-outline-light" title="{{ __('POS Restaurant') }}"><i class="ti ti-building-store" style="font-size: 1.25rem;"></i></a>
                        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light" title="{{ __('pos.dashboard') }}"><i class="ti ti-home" style="font-size: 1.25rem;"></i></a>
                    </div>
                </div>
            </div>
        </div>
        @php
            $posMode = $posMode ?? 'retail';
            $hasRetailBranches = $hasRetailBranches ?? true;
            $hasRestaurantBranches = $hasRestaurantBranches ?? false;
            $showModeTabs = ($posMode !== 'retail' && $posMode !== 'restaurant') && $hasRetailBranches && $hasRestaurantBranches;
            $defaultMode = $hasRestaurantBranches && !$hasRetailBranches ? 'restaurant' : 'retail';
        @endphp
        @if($showModeTabs)
        <div class="mt-2 d-flex gap-2">
            <button type="button" class="btn btn-outline-primary pos-mode-btn active" data-mode="retail">{{ __('pos.mode_retail') }}</button>
            <button type="button" class="btn btn-outline-primary pos-mode-btn" data-mode="restaurant">{{ __('pos.mode_restaurant') }}</button>
        </div>
        @endif
        <div id="pos-retail-panel" class="mt-2 mb-4 pb-3 pos-mode-panel {{ ($posMode === 'restaurant') ? 'd-none' : '' }} card rounded">
        <div class="row gy-2 main_div">
            @if(!$userOpenShift)
                <div class="disabled_div">
                    <h3>{{ __('pos.open_shift_and_balance') }}</h3>
                    <p class="mb-0 mt-2 small">{{ __('pos.open_shift_click_above') }}</p>
                </div>
            @endif
            <div class="col-lg-9 border-right">
                <div class="sop-card mb-0">
                    <div class="p-2">
                        <div class="search-bar-left pos-search-bar">
                            <form>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text" style="padding: 0.50rem 0.75rem !important;"><i class="ti ti-search"></i></span>
                                            </div>
                                            <input type="text" data-url="{{ route('search.products') }}"
                                                placeholder="{{ __('pos.search_product') }}"
                                                class="form-control form-control-sm pr-4 rounded-right searchproduct">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text" style="padding: 0.50rem 0.75rem !important;"><i class="ti ti-search"></i></span>
                                            </div>
                                            <input type="text" data-url="{{ route('search.products') }}"
                                                placeholder="{{ __('pos.search_barcode') }}"
                                                class="form-control pr-4 rounded-right barcodeScanner form-control-sm">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-control form-control-sm select" id="pos-retail-category">
                                            <option value="0">{{ __('All Categories') }}</option>
                                        </select>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="p-2 bg-light">
                        <div class="right-content">
                            <div class="product-body-nop">
                                <div class="form-row" id="product-listing">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 ps-lg-0">
                <div class="m-0 h-100">
                    <div class="p-2">
                        {{-- Branch & Warehouse: minimal one row --}}
                        @php
                            $restaurantBranchIds = $restaurantBranchIds ?? [];
                            $defaultBranchId = $defaultBranchIdFromShift ?? ((isset($branches) && $branches->count() == 1) ? $branches->keys()->first() : '');
                            $customersForPos = $customersForPos ?? collect();
                        @endphp
                        @if(isset($branches) && $branches->count() >= 1)
                        <div class="row g-1 mb-2">
                            <div class="col-6">
                                <select class="form-control form-control-sm select branch_select" id="branch_id" @if(!empty($posBranchLocked)) disabled @endif>
                                    <option value="">{{ __('Select Branch') }}</option>
                                    @foreach($branches as $bid => $bname)
                                        <option value="{{ $bid }}" {{ (string)$bid === (string)$defaultBranchId ? 'selected' : '' }}>{{ $bname }}</option>
                                    @endforeach
                                </select>
                                @if(!empty($posBranchLocked))<small class="text-muted d-block">{{ __('Assigned branch only') }}</small>@endif
                            </div>
                            <div class="col-6">
                                <select class="form-control form-control-sm select warehouse_select" id="warehouse" required>
                                    <option value="">{{ __('Select Warehouse') }}</option>
                                    @foreach($warehouses as $wid => $wname)
                                        <option value="{{ $wid }}" {{ $wid == $warehouseId ? 'selected' : '' }}>{{ $wname }}</option>
                                    @endforeach
                                </select>
                                {{ Form::hidden('warehouse_name_hidden', $warehouseId ?? '', ['id' => 'warehouse_name_hidden']) }}
                            </div>
                        </div>
                        @endif
                        @if(($posMode ?? '') === 'restaurant' && !empty($restaurantBranchIds))
                        <div id="pos-restaurant-options" class="row gy-1 mb-2">
                            <div class="col-sm-6 pr-1">
                                {{ Form::select('pos_order_type', ['dine_in' => __('pos.dine_in'), 'takeaway' => __('pos.takeaway'), 'delivery' => __('pos.delivery'), 'kiosk' => __('pos.kiosk')], null, ['class' => 'form-control select', 'id' => 'pos_order_type', 'placeholder' => __('pos.select_order_type')]) }}
                                {{ Form::hidden('pos_order_type_hidden', '', ['id' => 'pos_order_type_hidden']) }}
                            </div>
                            <div class="col-sm-6 pl-1">
                                {{ Form::select('pos_table_id', [], null, ['class' => 'form-control select', 'id' => 'pos_table_id', 'placeholder' => __('Select Table')]) }}
                                {{ Form::hidden('pos_table_id_hidden', '', ['id' => 'pos_table_id_hidden']) }}
                            </div>
                        </div>
                        @endif
                        {{ Form::hidden('quotation_id', $id, ['id' => 'quotation_id', 'class' => 'quotation']) }}

                        {{-- Customer: header + Change + Edit (when Walk-in) + card --}}
                        <div class="pos-customer-block mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold d-flex align-items-center gap-1">
                                    <i class="ti ti-user"></i> {{ __('Customer') }}
                                </span>
                                <div class="d-flex align-items-center gap-1">
                                    <a href="#" class="text-muted small pos-customer-edit d-none" id="pos-customer-edit" title="{{ __('Add New Customer') }}"><i class="ti ti-edit"></i> {{ __('Edit') }}</a>
                                    <a href="#" class="text-muted small pos-customer-change" id="pos-customer-change">{{ __('Change') }}</a>
                                </div>
                            </div>
                            <select class="form-control form-control-sm select customer_select d-none" id="customer" required>
                                @foreach($customersForPos as $c)
                                    <option value="{{ $c->name }}" data-email="{{ e($c->email ?? '') }}" data-contact="{{ e($c->contact ?? '') }}" data-type="{{ $c->name === 'Walk-in-customer' ? 'walk-in' : 'customer' }}" {{ (($customer ?? '') === $c->name || (($customer ?? '') === '' && $c->name === 'Walk-in-customer')) ? 'selected' : '' }}>{{ $c->name }}</option>
                                @endforeach
                            </select>
                            {{ Form::hidden('vc_name_hidden', ($customer ?? '') ?: 'Walk-in-customer', ['id' => 'vc_name_hidden']) }}
                            <div class="pos-customer-card card border rounded-2 shadow-sm p-2" id="pos-customer-card">
                                @php
                                    $selCustomer = $customersForPos->firstWhere('name', $customer ?? 'Walk-in-customer') ?? $customersForPos->first();
                                @endphp
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="min-w-0">
                                        <div class="fw-bold text-dark text-truncate" id="pos-customer-name">{{ $selCustomer->name ?? __('Walk-in-customer') }}</div>
                                        <small class="text-muted d-block text-truncate" id="pos-customer-email">{{ $selCustomer->email ?? 'walkin@default.com' }}</small>
                                        <small class="text-muted d-block" id="pos-customer-phone">{{ $selCustomer->contact ?? '0000000000' }}</small>
                                    </div>
                                    <span class="badge bg-light text-dark border" id="pos-customer-badge">{{ $selCustomer && $selCustomer->name === 'Walk-in-customer' ? __('Walk-in') : __('Customer') }}</span>
                                </div>
                                <div id="pos-customer-balance-wrap" class="mt-2 pt-2 border-top d-none">
                                    <div class="d-flex justify-content-between align-items-center small">
                                        <span class="text-muted">{{ __('Balance due') }}:</span>
                                        <span id="pos-customer-balance" class="fw-semibold text-danger" data-raw="0">0</span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-1 w-100" id="pos-pay-balance-btn"><i class="ti ti-cash me-1"></i> {{ __('Pay balance') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @php
                        $cartCount = session($lastsegment) && !empty(session($lastsegment)) ? count(session($lastsegment)) : 0;
                    @endphp
                    <div class="d-flex justify-content-between align-items-center p-2 mb-2 border-bottom">
                        <h5 class="mb-0 d-flex align-items-center gap-2">
                            <i class="ti ti-shopping-cart"></i>
                            <span>{{ __('Cart') }} <span id="pos-cart-count">({{ $cartCount }})</span></span>
                        </h5>
                        <div class="tab-content btn-empty">
                            <a href="#" class="btn btn-sm btn-outline-danger bs-pass-para-pos"
                                data-toggle="tooltip" data-original-title="{{ __('pos.empty_cart') }}"
                                data-confirm="{{ __('pos.are_you_sure') }}"
                                data-text="{{ __('pos.confirm_undo') }}"
                                data-confirm-yes="delete-form-emptycart">{{ __('Clear') }}</a>
                            {!! Form::open(['method' => 'post', 'url' => ['empty-cart'], 'id' => 'delete-form-emptycart']) !!}
                            <input type="hidden" name="session_key" value="{{ $lastsegment }}" id="empty_cart">
                            {!! Form::close() !!}
                        </div>
                    </div>
                    <div class="carttable cart-product-list carttable-scroll p-2" id="carthtml" style="max-height: 320px; overflow-y: auto;">
                        @php
                            $total = 0;
                            $netPrice = 0;
                            $discount = 0;
                        @endphp
                        <div id="tbody" class="pos-cart-cards">
                                    @if (session($lastsegment) && !empty(session($lastsegment)) && count(session($lastsegment)) > 0)
                                        @foreach (session($lastsegment) as $id => $details)
                                            @php
                                                $product = \App\Models\ProductService::find($details['id']);
                                                $image_url = !empty($product) && isset($product->pro_image) ? $product->pro_image : 'uploads/pro_image/default.png';
                                                $sku = $product && $product->sku ? $product->sku : '';
                                                $catName = $product && $product->category ? $product->category->name : '';
                                                $total += $details['subtotal'];
                                                $netPrice += $details['netPrice'];
                                                $discount += $details['discount'];
                                                $taxStr = '-';
                                                if (!empty($product->tax_id)) {
                                                    $taxes = \Utility::tax($product->tax_id);
                                                    $taxStr = $taxes->map(fn($t) => $t->name . ' (' . $t->rate . '%)')->join(', ');
                                                }
                                            @endphp
                                            <div class="pos-cart-card-item card border rounded-2 mb-2 shadow-sm" data-product-id="{{ $id }}" id="product-id-{{ $id }}"
                                                data-name="{{ e($details['name']) }}" data-price="{{ $details['price'] }}"
                                                data-quantity="{{ $details['quantity'] }}" data-tax="{{ $details['tax'] ?? 0 }}"
                                                data-subtotal="{{ $details['subtotal'] }}" data-discount="{{ $details['discount'] ?? 0 }}"
                                                data-netprice="{{ $details['netPrice'] ?? $details['subtotal'] }}">
                                                <div class="card-body p-2">
                                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                                        <div class="flex-grow-1 min-w-0">
                                                            <h6 class="mb-0 text-dark fw-bold text-truncate name">{{ $details['name'] }}</h6>
                                                            @if($sku)
                                                                <small class="text-muted d-block">SKU: {{ $sku }}</small>
                                                            @endif
                                                            @if($catName)
                                                                <span class="badge bg-light text-dark border mt-1">{{ $catName }}</span>
                                                            @endif
                                                        </div>
                                                        <div class="action-btn">
                                                            <a href="#" class="btn btn-sm btn-danger p-1 bs-pass-para-pos"
                                                                data-confirm="{{ __('Are You Sure?') }}"
                                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                                data-confirm-yes="delete-form-{{ $id }}" title="{{ __('Delete') }}" data-id="{{ $id }}">
                                                                <i class="ti ti-trash"></i>
                                                            </a>
                                                            {!! Form::open(['method' => 'delete', 'url' => ['remove-from-cart'], 'id' => 'delete-form-' . $id]) !!}
                                                            <input type="hidden" name="session_key" value="{{ $lastsegment }}">
                                                            <input type="hidden" name="id" value="{{ $id }}">
                                                            {!! Form::close() !!}
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">
                                                        <span class="quantity buttons_added d-inline-flex align-items-center border rounded">
                                                            <input type="button" value="-" class="minus btn btn-sm btn-outline-secondary border-0 py-0">
                                                            <input type="number" step="1" min="1" max="" name="quantity" title="{{ __('pos.quantity') }}" class="input-number form-control form-control-sm border-0 text-center" style="width: 2.5rem;" data-url="{{ url('update-cart/') }}" data-id="{{ $id }}" value="{{ $details['quantity'] }}">
                                                            <input type="button" value="+" class="plus btn btn-sm btn-outline-secondary border-0 py-0">
                                                        </span>
                                                        <div class="text-end">
                                                            <div class="fw-bold text-dark subtotal">{{ Auth::user()->priceFormat($details['subtotal']) }}</div>
                                                            <small class="text-muted price">{{ Auth::user()->priceFormat($details['price']) }} {{ __('each') }}</small>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted d-block mt-1 tax">{{ __('Tax') }}: {{ $taxStr }}</small>
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="text-center no-found py-4 text-muted">
                                            {{ __('pos.no_data_found') }}
                                        </div>
                                    @endif
                        </div>
                        <div class="total-section pos-totals mt-3">
                            <div class="sub-total">
                                <div class="">
                                    <div class="sub-total-right flex-1">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <p class="mb-0 text-dark">{{ __('pos.sub_total') }} :</p>
                                            <p class="mb-0 text-dark subtotal_price" id="displaytotal">
                                                {{ Auth::user()->priceFormat($total) }}</p>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between align-items-center gap-2 mb-2 discount-wrap">
                                            <p class="mb-0 text-dark">{{ __('pos.discount') }} :</p>
                                            <div class="d-flex align-items-center input-group input-group-sm" style="max-width: 120px;">
                                                <span class="input-group-text bg-transparent" style="padding: 0.50rem 0.75rem !important;">{{ \Auth::user()->currencySymbol() }}</span>
                                                {{ Form::number('discount', $discount, ['class' => 'form-control form-control-sm discount', 'required' => 'required', 'placeholder' => '0']) }}
                                            </div>
                                            {{ Form::hidden('discount_hidden', $discount, ['id' => 'discount_hidden']) }}
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <p class="mb-0 text-dark">{{ __('Total') }} :</p>
                                            <p class="mb-0 text-dark totalamount">
                                                {{ Auth::user()->priceFormat($netPrice) }}</p>
                                        </div>
                                        <button type="button" id="pos-pay-btn" class="btn btn-primary rounded pos-pay-btn w-100" data-ajax-popup="true"
                                            data-size="xl" data-align="centered" data-url="{{ route('pos.create') }}"
                                            data-title="{{ __('pos.pos_invoice') }}"
                                            @if (session($lastsegment) && !empty(session($lastsegment)) && count(session($lastsegment)) > 0) @else disabled="disabled" @endif>
                                            {{ __('pos.pay') }}
                                        </button>
                                    </div>
                                    <div class="sub-total-left flex-1 d-none" id="btn-pur">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
        </div>{{-- /pos-retail-panel --}}

        @if(($posMode ?? '') !== 'retail')
        <div id="pos-restaurant-panel" class="pos-mode-panel mt-2 {{ ($posMode ?? '') === 'restaurant' ? '' : 'd-none' }}">
            @include('pos.restaurant')
        </div>{{-- /pos-restaurant-panel --}}
        @endif
    </div>{{-- /pos-page --}}

    <div class="modal fade" id="commonModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="body">
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="posOpenShiftModal" tabindex="-1" aria-labelledby="posOpenShiftModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posOpenShiftModalLabel">{{ __('pos.open_shift') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="pos-open-shift-form">
                    <div class="modal-body">
                        @csrf
                        @if(!empty($posBranchLocked) && Auth::user()->pos_branch_id && isset($branches) && $branches->has(Auth::user()->pos_branch_id))
                            <input type="hidden" name="branch_id" value="{{ Auth::user()->pos_branch_id }}">
                            <div class="mb-3">
                                <label class="form-label">{{ __('pos.branch') }}</label>
                                <div class="form-control-plaintext fw-semibold">{{ $branches[Auth::user()->pos_branch_id] ?? __('Assigned branch') }}</div>
                            </div>
                        @else
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.branch') }}</label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">{{ __('pos.select_branch') }}</option>
                                @isset($branches)
                                    @foreach($branches as $bid => $bname)
                                        <option value="{{ $bid }}">{{ $bname }}</option>
                                    @endforeach
                                @endisset
                            </select>
                        </div>
                        @endif
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.opening_balance') }}</label>
                            <input type="number" name="opening_balance" class="form-control" min="0" step="0.01" value="0" placeholder="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                        <button type="submit" class="btn btn-primary" id="pos-open-shift-submit">{{ __('pos.open_shift') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Add New Customer from POS (when Walk-in selected) --}}
    <div class="modal fade" id="posNewCustomerModal" tabindex="-1" aria-labelledby="posNewCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posNewCustomerModalLabel">{{ __('Add New Customer') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="pos-new-customer-form">
                    <div class="modal-body">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="255" placeholder="{{ __('Name') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Contact') }} <span class="text-danger">*</span></label>
                            <input type="text" name="contact" class="form-control" required maxlength="50" placeholder="{{ __('Contact') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Email') }} <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required placeholder="{{ __('Email') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Tax Number') }} / {{ __('VAT') }}</label>
                            <input type="text" name="tax_number" class="form-control" maxlength="50" placeholder="{{ __('Tax Number') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Address') }}</label>
                            <textarea name="billing_address" class="form-control" rows="2" placeholder="{{ __('Enter Address') }}"></textarea>
                        </div>
                        <div id="pos-new-customer-error" class="alert alert-danger py-2 d-none mb-0"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary" id="pos-new-customer-submit">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Pay customer balance (outstanding invoices) from POS --}}
    <div class="modal fade" id="posPayBalanceModal" tabindex="-1" aria-labelledby="posPayBalanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posPayBalanceModalLabel">{{ __('Pay balance') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="pos-pay-balance-form">
                    <div class="modal-body">
                        @csrf
                        <input type="hidden" name="vc_name" id="pos-pay-balance-vc-name" value="">
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{ __('Outstanding Invoices') }}</label>
                            <div id="pos-pay-balance-invoices" class="border rounded p-2 bg-light" style="max-height: 200px; overflow-y: auto;">
                                <p class="text-muted small mb-0">{{ __('Loading...') }}</p>
                            </div>
                            <p class="small text-muted mt-1 mb-0"><strong>{{ __('Total due') }}:</strong> <span id="pos-pay-balance-total-due">0</span></p>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Amount to pay') }} <span class="text-danger">*</span></label>
                                <input type="number" name="amount" id="pos-pay-balance-amount" class="form-control" required min="0.01" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Payment method') }} <span class="text-danger">*</span></label>
                                <select name="payment_method" id="pos-pay-balance-payment-method" class="form-select" required>
                                    <option value="cash">{{ __('pos.payment_cash') }}</option>
                                    <option value="card">{{ __('pos.payment_card') }}</option>
                                    <option value="visa">{{ __('pos.payment_visa') }}</option>
                                    <option value="cheque">{{ __('pos.payment_cheque') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Bank account') }} <span class="text-danger">*</span></label>
                            <select name="account_id" id="pos-pay-balance-account" class="form-select" required>
                                <option value="">{{ __('Loading...') }}</option>
                            </select>
                        </div>
                        <div id="pos-pay-balance-error" class="alert alert-danger py-2 d-none mb-0"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary" id="pos-pay-balance-submit">{{ __('Pay') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="position-fixed top-0 end-0 p-3" style="z-index: 99999">
        <div id="liveToast" class="toast text-white  fade" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"> </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
                    aria-label="Close"></button>
            </div>
        </div>
    </div>
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/popper.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/perfect-scrollbar.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/bootstrap.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/feather.min.js') }}"></script>
    <script src="{{ asset('assets/js/dash.js') }}"></script>
    <script src="{{ asset('js/moment.min.js') }}"></script>

    <script src="{{ asset('assets/js/plugins/bootstrap-switch-button.min.js') }}"></script>

    <script src="{{ asset('assets/js/plugins/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/simple-datatables.js') }}"></script>

    <!-- Apex Chart -->
    <script src="{{ asset('assets/js/plugins/apexcharts.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/main.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/choices.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/flatpickr.min.js') }}"></script>


    <script src="{{ asset('js/jscolor.js') }}"></script>
    <script src="{{ asset('js/custom.js') }}"></script>

    @if ($message = Session::get('success'))
        <script>
            show_toastr('success', '{!! $message !!}');
        </script>
    @endif
    @if ($message = Session::get('error'))
        <script>
            show_toastr('error', '{!! $message !!}');
        </script>
    @endif
    @stack('script-page')

    <script src="{{ asset('js/jquery-ui.min.js') }}"></script>

    <script>
        var POS_PENDING_KEY = 'pos_pending_sales';
        var POS_CACHE_PRODUCTS = 'pos_cache_products';
        var POS_CACHE_CATEGORIES = 'pos_cache_categories';
        var posSyncUrl = '{{ route("pos.sync") }}';
        var posCurrencySymbol = @json(Auth::user()->currencySymbol() ?? '');
        var posPrintSettings = @json(\App\Models\Utility::settings());
        var posEmptyCartUrl = '{{ route("warehouse-empty-cart") }}';
        var posSearchProductsUrl = '{{ route("search.products") }}';
        var posCategoriesUrl = '{{ route("product.categories") }}';
        var posCategoriesJsonUrl = '{{ route("product.categories.json") }}';
        var sessionKey = '{{ $lastsegment ?? "pos" }}';
        var session_key = sessionKey;
        var posIsRetail = @json(($posMode ?? '') === 'retail');
        var posEmptyCartHtmlRetail = '<div class="text-center no-found py-4 text-muted">{{ __("pos.no_data_found") }}</div>';
        var posEmptyCartHtmlRestaurant = '<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>';
        function posEmptyCartHtml(selector) { return selector === '#tbody' ? posEmptyCartHtmlRetail : posEmptyCartHtmlRestaurant; }
        function posCartItemSelector(selector) { return selector + ' [data-product-id]'; }

        function posProductCacheKey(warehouse, cat, search, type) {
            return (warehouse || '0') + '|' + (cat || '0') + '|' + (search || '') + '|' + (type || 'name');
        }
        function posGetCachedProducts(key) {
            try {
                var o = JSON.parse(localStorage.getItem(POS_CACHE_PRODUCTS) || '{}');
                return o[key] || null;
            } catch (e) { return null; }
        }
        function posSetCachedProducts(key, html) {
            try {
                var o = JSON.parse(localStorage.getItem(POS_CACHE_PRODUCTS) || '{}');
                o[key] = html;
                var keys = Object.keys(o);
                if (keys.length > 50) {
                    var keep = keys.slice(-40);
                    var n = {};
                    keep.forEach(function(k) { n[k] = o[k]; });
                    o = n;
                }
                localStorage.setItem(POS_CACHE_PRODUCTS, JSON.stringify(o));
            } catch (e) {}
        }
        function posGetCachedCategories() {
            try { return localStorage.getItem(POS_CACHE_CATEGORIES); } catch (e) { return null; }
        }
        function posSetCachedCategories(html) {
            try { localStorage.setItem(POS_CACHE_CATEGORIES, html); } catch (e) {}
        }

        function posUpdateOfflineBanner() {
            var el = document.getElementById('pos-offline-banner');
            if (el) el.classList.toggle('d-none', navigator.onLine);
            var badge = document.getElementById('pos-status-badge');
            if (badge) {
                if (navigator.onLine) {
                    badge.textContent = '{{ __("pos.online") }}';
                    badge.className = 'badge bg-success align-middle';
                    badge.style.fontSize = '0.75rem';
                } else {
                    badge.textContent = '{{ __("pos.offline") }}';
                    badge.className = 'badge bg-secondary align-middle';
                    badge.style.fontSize = '0.75rem';
                }
            }
        }
        function posUpdateCurrentTime() {
            var el = document.getElementById('pos-current-time');
            if (el) {
                var now = new Date();
                el.textContent = now.toLocaleTimeString('en-GB', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        function posUpdatePendingBadge() {
            var pending = [];
            try { pending = JSON.parse(localStorage.getItem(POS_PENDING_KEY) || '[]'); } catch (e) {}
            var badge = document.getElementById('pos-pending-badge');
            if (!badge) return;
            var n = pending.length;
            if (n > 0) {
                var msg = badge.getAttribute('data-msg') || 'Pending sync:';
                badge.textContent = msg + ' ' + n;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        }
        function posGetCartFromDom() {
            var cart = {};
            $('#tbody [data-product-id]').each(function () {
                var id = $(this).attr('data-product-id');
                var qty = parseInt($(this).find('input.input-number').val() || 1, 10);
                var price = parseFloat($(this).attr('data-price')) || 0;
                var tax = parseFloat($(this).attr('data-tax')) || 0;
                var subtotal = price * qty;
                var discount = parseFloat($(this).attr('data-discount')) || 0;
                var netPrice = subtotal - discount;
                cart[id] = {
                    id: parseInt(id, 10),
                    name: $(this).attr('data-name') || '',
                    price: price,
                    quantity: qty,
                    tax: tax,
                    subtotal: subtotal,
                    discount: discount,
                    netPrice: netPrice
                };
            });
            return cart;
        }
        function posSaveOfflineSale() {
            var cart = posGetCartFromDom();
            if (Object.keys(cart).length === 0) return false;
            var pm = ($('#payment_method').length ? $('#payment_method').val() : null) || 'cash';
            var amount = parseFloat($('#payment_amount').val()) || 0;
            var changeAmount = parseFloat($('#payment_change').val()) || 0;
            var sale = {
                vc_name: $('#vc_name_hidden').val() || '',
                warehouse_name: $('#warehouse_name_hidden').val() || '',
                discount: $('#discount_hidden').val() || 0,
                payment_method: pm,
                amount: amount,
                change_amount: changeAmount,
                transaction_id: ($('#payment_transaction_id').length ? $('#payment_transaction_id').val() : '') || null,
                credit_terms: ($('#payment_credit_terms').length ? $('#payment_credit_terms').val() : '') || null,
                notes: ($('#payment_notes').length ? $('#payment_notes').val() : '') || null,
                pos_shift_id: ($('#pos_shift_id_hidden').length ? $('#pos_shift_id_hidden').val() : '') || '',
                quotation_id: $('#quotation_id').val() || 0,
                date: new Date().toISOString().slice(0, 10),
                client_order_id: 'offline-' + Date.now() + '-' + Math.random().toString(36).slice(2, 11),
                cart: cart
            };
            if (!posIsRetail && $('#pos_order_type_hidden').length) { sale.pos_order_type = $('#pos_order_type_hidden').val() || ''; sale.pos_table_id = $('#pos_table_id_hidden').length ? $('#pos_table_id_hidden').val() || '' : ''; }
            if (pm === 'mixed' && $('#mixed_payment_wrap').length && !$('#mixed_payment_wrap').hasClass('d-none')) {
                var payments = [];
                var totalPaid = 0;
                $('.mixed-row').each(function() {
                    var amt = parseFloat($(this).find('.mixed-amount').val()) || 0;
                    if (amt > 0) {
                        payments.push({ payment_method: $(this).find('.mixed-method').val(), amount: amt });
                        totalPaid += amt;
                    }
                });
                if (payments.length > 0) {
                    sale.payment_method = 'mixed';
                    sale.payments = payments;
                    sale.amount = totalPaid;
                    sale.change_amount = 0;
                }
            }
            try {
                var pending = JSON.parse(localStorage.getItem(POS_PENDING_KEY) || '[]');
                pending.push(sale);
                localStorage.setItem(POS_PENDING_KEY, JSON.stringify(pending));
            } catch (e) { return false; }
            return true;
        }
        function posClearCartDom() {
            $('#tbody [data-product-id]').remove();
            $('#tbody').html(posEmptyCartHtmlRetail);
            $('#pos-cart-count').text('(0)');
            $('#pos-pay-btn').prop('disabled', true);
            $('.btn-empty button').removeClass('btn-clear-cart');
            $('#displaytotal, .totalamount').text('0');
        }
        function posSyncPending() {
            try {
                var pending = JSON.parse(localStorage.getItem(POS_PENDING_KEY) || '[]');
                if (pending.length === 0) { posUpdatePendingBadge(); return; }
                $.ajax({
                    url: posSyncUrl,
                    method: 'POST',
                    contentType: 'application/json',
                    dataType: 'json',
                    data: JSON.stringify({ sales: pending }),
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function (res) {
                        if (res && res.synced > 0) {
                            var remaining = pending.slice(res.synced);
                            localStorage.setItem(POS_PENDING_KEY, JSON.stringify(remaining));
                            if (typeof show_toastr === 'function') show_toastr('success', res.success || ('{{ __("pos.synced_count") }}'.replace(':count', res.synced)));
                        }
                        if (res && res.errors && res.errors.length > 0 && (res.synced || 0) === 0) {
                            var errMsg = res.errors[0].error || '{{ __("pos.sync_failed") }}';
                            if (typeof show_toastr === 'function') show_toastr('error', errMsg);
                        }
                        posUpdatePendingBadge();
                    },
                    error: function (xhr) {
                        posUpdatePendingBadge();
                        var msg = '{{ __("pos.sync_failed") }}';
                        if (xhr.responseJSON) {
                            msg = xhr.responseJSON.error || (xhr.responseJSON.message || msg);
                            if (xhr.responseJSON.errors) {
                                var first = Object.values(xhr.responseJSON.errors)[0];
                                if (Array.isArray(first)) msg = first[0];
                            }
                        } else if (xhr.status === 0) msg = '{{ __("pos.sync_offline") }}';
                        else if (xhr.status === 419) msg = '{{ __("pos.sync_refresh") }}';
                        else if (xhr.status >= 500) msg = '{{ __("pos.sync_server_error") }}';
                        if (typeof show_toastr === 'function') show_toastr('error', msg);
                        if (navigator.onLine) setTimeout(posSyncPending, 5000);
                    }
                });
            } catch (e) { posUpdatePendingBadge(); }
        }
        window.addEventListener('online', function () { posUpdateOfflineBanner(); posSyncPending(); });
        window.addEventListener('offline', posUpdateOfflineBanner);
        setInterval(posUpdateCurrentTime, 1000);
        $(document).ready(function () {
            posUpdateCurrentTime();
            posUpdateOfflineBanner();
            posUpdatePendingBadge();
            if (navigator.onLine) posSyncPending();
            $(document).on('click', '#pos-pending-badge', function () {
                if (navigator.onLine && $(this).hasClass('bg-warning')) posSyncPending();
            });
            var payBtn = document.getElementById('pos-pay-btn');
            if (payBtn) {
                payBtn.addEventListener('click', function (e) {
                    if (!navigator.onLine) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        var itemsCount = 0;
                        var subTotalInclTax = 0;
                        var taxTotal = 0;
                        $('#tbody [data-product-id]').each(function () {
                            var qty = parseInt($(this).find('input.input-number').val() || 1, 10);
                            var price = parseFloat($(this).attr('data-price')) || 0;
                            var tax = parseFloat($(this).attr('data-tax')) || 0;
                            var lineSubtotal = (price + price * tax / 100) * qty;
                            subTotalInclTax += lineSubtotal;
                            taxTotal += price * qty * tax / 100;
                            itemsCount += qty;
                        });
                        var discount = parseFloat($('#discount_hidden').val()) || 0;
                        var total = Math.max(0, subTotalInclTax - discount);
                        var fmt = function (n) {
                            var v = Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                            return (typeof posCurrencySymbol !== 'undefined' && posCurrencySymbol ? posCurrencySymbol + v : v);
                        };
                        var canGivePosCredit = @json($canGivePosCredit ?? false);
                        var payOpts = '<option value="cash">{{ __("pos.payment_cash") }}</option><option value="card">{{ __("pos.payment_card") }}</option>' + (canGivePosCredit ? '<option value="credit">{{ __("pos.payment_credit") }}</option>' : '') + '<option value="mixed">{{ __("Mixed Payment") }}</option><option value="visa">{{ __("pos.payment_visa") }}</option><option value="mpesa">{{ __("pos.payment_mpesa") }}</option><option value="bank_transfer">{{ __("pos.payment_bank_transfer") }}</option><option value="cheque">{{ __("pos.payment_cheque") }}</option>';
                        var creditTermsSelectOpts = @json($posCreditTermOptions ?? []);
                        var creditDaysHtml = creditTermsSelectOpts && Object.keys(creditTermsSelectOpts).length ? '<label class="form-label small mb-1">{{ __("Credit Terms") }}</label><select class="form-select form-select-sm" id="payment_credit_terms" name="credit_terms"><option value="">{{ __("Select Credit Terms") }}</option>' + Object.keys(creditTermsSelectOpts).map(function(k){ return '<option value="'+k+'">'+creditTermsSelectOpts[k]+'</option>'; }).join('') + '</select>' : '<label class="form-label small mb-1">{{ __("Credit Days") }}</label><input type="number" min="1" class="form-control form-control-sm" id="payment_credit_terms" name="credit_terms" placeholder="{{ __("Enter days") }}">';
                        var html = '<div class="card border-0 shadow-sm pos-payment-modal-content"><div class="card-body p-3">' +
                            '<p class="text-warning small mb-2"><span class="ti ti-wifi-off me-1"></span>{{ __("pos.offline_banner") }}</p>' +
                            '<h6 class="card-title mb-2">{{ __("Process Payment") }}</h6>' +
                            '<div class="card bg-light mb-2"><div class="card-body py-2 px-3 small">' +
                            '<h6 class="card-subtitle mb-2 text-muted small">{{ __("Order Summary") }}</h6>' +
                            '<div class="d-flex justify-content-between mb-1"><span>{{ __("Items") }} (' + $('#tbody [data-product-id]').length + '):</span><span>' + $('#tbody [data-product-id]').length + '</span></div>' +
                            '<div class="d-flex justify-content-between mb-1"><span>{{ __("pos.sub_total") }}:</span><span>' + fmt(subTotalInclTax) + '</span></div>' +
                            '<div class="d-flex justify-content-between mb-1"><span>{{ __("Tax") }}:</span><span>' + fmt(taxTotal) + '</span></div>' +
                            '<div class="d-flex justify-content-between mb-1"><span>{{ __("pos.total") }}:</span><strong>' + fmt(total) + '</strong></div>' +
                            '</div></div>' +
                            '<div class="mb-2"><label class="form-label small mb-1">{{ __("Payment Method") }}</label><select class="form-select form-select-sm" id="payment_method" name="payment_method">' + payOpts + '</select></div>' +
                            '<div class="mb-2" id="single_payment_wrap"><label class="form-label small mb-1" id="payment_amount_label">{{ __("pos.cash_received") }}</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" id="payment_amount" value="' + total + '" placeholder="{{ __("pos.enter_amount") }}"></div>' +
                            '<div class="mb-2 d-none" id="change_wrap"><div class="alert alert-success mb-0 py-1 small"><strong>{{ __("Change") }}:</strong> <span id="change_display">0</span></div><input type="hidden" id="payment_change" name="change_amount" value="0"></div>' +
                            '<div class="mb-2 d-none" id="mixed_payment_wrap"><label class="form-label small mb-1">{{ __("Mixed Payment") }}</label><div id="mixed_payment_rows"></div><button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="mixed_add_row">{{ __("Add Payment") }}</button><div class="mt-2 small"><span>{{ __("pos.total_paid") }}:</span> <strong id="mixed_total_paid">0</strong> <span class="ms-2">{{ __("pos.remaining") }}:</span> <strong id="mixed_remaining">0</strong></div></div>' +
                            '<div class="mb-2 d-none" id="transaction_id_wrap"><label class="form-label small mb-1">{{ __("Transaction ID") }}</label><input type="text" class="form-control form-control-sm" id="payment_transaction_id" name="transaction_id" placeholder="{{ __("Transaction ID") }}"></div>' +
                            '<div class="mb-2 d-none" id="credit_days_wrap">' + creditDaysHtml + '</div>' +
                            '<div class="mb-3"><label class="form-label small mb-1">{{ __("Notes") }}</label><textarea class="form-control form-control-sm" id="payment_notes" name="notes" rows="2" placeholder="{{ __("Notes") }}"></textarea></div>' +
                            '<button type="button" id="pos-offline-pay-confirm" class="btn btn-success btn-sm w-100 rounded">{{ __("pos.pay_button") }}</button>' +
                            '</div></div>';
                        $('#commonModal .modal-title').html('{{ __("pos.pos_invoice") }}');
                        $('.modal-dialog').removeClass('modal-xl modal-lg modal-md modal-sm').addClass('modal-sm');
                        $('#commonModal .body').attr('data-offline-total', total).html(html);
                        $('#commonModal').modal('show');
                        (function (totalRaw) {
                            var payOptsRow = '<option value="cash">{{ __("pos.payment_cash") }}</option><option value="card">{{ __("pos.payment_card") }}</option>' + (canGivePosCredit ? '<option value="credit">{{ __("pos.payment_credit") }}</option>' : '') + '<option value="visa">{{ __("pos.payment_visa") }}</option><option value="mpesa">{{ __("pos.payment_mpesa") }}</option><option value="bank_transfer">{{ __("pos.payment_bank_transfer") }}</option><option value="cheque">{{ __("pos.payment_cheque") }}</option>';
                            function togglePaymentFields() {
                                var method = $('#payment_method').val();
                                var isMixed = method === 'mixed';
                                var isCash = method === 'cash';
                                var isCard = /^(card|visa|mpesa|bank_transfer|cheque)$/.test(method);
                                var isCredit = method === 'credit';
                                $('#single_payment_wrap').toggleClass('d-none', isMixed);
                                $('#mixed_payment_wrap').toggleClass('d-none', !isMixed);
                                $('#transaction_id_wrap').toggleClass('d-none', !isCard);
                                $('#credit_days_wrap').toggleClass('d-none', !isCredit);
                                $('#payment_amount_label').text(isCash ? '{{ __("pos.cash_received") }}' : '{{ __("Amount") }}');
                                if (isCash) { updateChange(); } else { $('#change_wrap').addClass('d-none'); $('#payment_change').val('0'); }
                                if (isMixed && $('#mixed_payment_rows .mixed-row').length === 0) { addMixedRow(); }
                                if (isMixed) updateMixedTotals();
                            }
                            function updateChange() {
                                if ($('#payment_method').val() !== 'cash') return;
                                var amount = parseFloat($('#payment_amount').val()) || 0;
                                var change = amount - totalRaw;
                                if (change > 0) {
                                    $('#change_wrap').removeClass('d-none');
                                    $('#change_display').text(fmt(change));
                                    $('#payment_change').val(change.toFixed(2));
                                } else {
                                    $('#change_wrap').addClass('d-none');
                                    $('#payment_change').val('0');
                                }
                            }
                            function addMixedRow() {
                                var row = '<div class="mixed-row input-group input-group-sm mb-1"><select class="form-select form-select-sm mixed-method">' + payOptsRow + '</select><input type="number" step="0.01" min="0" class="form-control form-control-sm mixed-amount" placeholder="{{ __("pos.enter_amount") }}"><button type="button" class="btn btn-outline-danger btn-sm mixed-remove">&times;</button></div>';
                                $('#mixed_payment_rows').append(row);
                                $(document).off('input.posoffline change.posoffline', '.mixed-amount').on('input.posoffline change.posoffline', '.mixed-amount', updateMixedTotals);
                                $(document).off('click.posoffline', '.mixed-remove').on('click.posoffline', '.mixed-remove', function() { $(this).closest('.mixed-row').remove(); updateMixedTotals(); });
                                updateMixedTotals();
                            }
                            function updateMixedTotals() {
                                var sum = 0;
                                $('.mixed-amount').each(function() { sum += parseFloat($(this).val()) || 0; });
                                $('#mixed_total_paid').text(fmt(Math.max(0, sum)));
                                $('#mixed_remaining').text(fmt(Math.max(0, totalRaw - sum)));
                            }
                            $('#payment_method').off('change.posoffline').on('change.posoffline', togglePaymentFields);
                            $('#payment_amount').off('input.posoffline change.posoffline').on('input.posoffline change.posoffline', updateChange);
                            $('#mixed_add_row').off('click.posoffline').on('click.posoffline', addMixedRow);
                            togglePaymentFields();
                            updateChange();
                        })(total);
                        return false;
                    }
                }, true);
            }
            $(document).on('click', '#pos-offline-pay-confirm', function () {
                var requiredTotal = parseFloat($('#commonModal .body').attr('data-offline-total')) || 0;
                var method = ($('#payment_method').length ? $('#payment_method').val() : '') || 'cash';
                if (method === 'mixed') {
                    var totalPaid = 0;
                    $('.mixed-amount').each(function() { totalPaid += parseFloat($(this).val()) || 0; });
                    if (requiredTotal > 0 && totalPaid < requiredTotal) {
                        if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("pos.amount_cannot_less_than_total") }}', 'error');
                        return;
                    }
                } else {
                    var enteredAmount = parseFloat($('#payment_amount').val()) || 0;
                    if (requiredTotal > 0 && enteredAmount < requiredTotal) {
                        if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("pos.amount_cannot_less_than_total") }}', 'error');
                        return;
                    }
                }
                var receiptData = null;
                if (posSaveOfflineSale()) {
                    var payAmt = method === 'mixed' ? 0 : (parseFloat($('#payment_amount').val()) || 0);
                    if (method === 'mixed') {
                        $('.mixed-row').each(function() { payAmt += parseFloat($(this).find('.mixed-amount').val()) || 0; });
                    }
                    receiptData = {
                        items: [],
                        subTotal: 0,
                        taxTotal: 0,
                        discount: parseFloat($('#discount_hidden').val()) || 0,
                        total: requiredTotal,
                        customer: ($('#vc_name_hidden').val() || '').trim() || 'Walk-in-customer',
                        paymentMethod: (method === 'mixed' ? 'Mixed Payment' : (method || 'cash').replace(/_/g, ' ')),
                        amount: payAmt,
                        change: method === 'cash' ? (parseFloat($('#payment_change').val()) || 0) : 0,
                        transactionId: ($('#payment_transaction_id').val() || '').trim(),
                        creditTerms: ($('#payment_credit_terms').val() || '').trim(),
                        notes: ($('#payment_notes').val() || '').trim(),
                        date: new Date().toLocaleDateString(),
                        warehouseName: ($('.warehouse_select option:selected').text() || '').trim()
                    };
                    $('#tbody [data-product-id]').each(function () {
                        var name = $(this).attr('data-name') || '';
                        var qty = parseInt($(this).find('input.input-number').val() || 1, 10);
                        var price = parseFloat($(this).attr('data-price')) || 0;
                        var tax = parseFloat($(this).attr('data-tax')) || 0;
                        var subtotal = (price + price * tax / 100) * qty;
                        receiptData.subTotal += subtotal;
                        receiptData.taxTotal += price * qty * tax / 100;
                        receiptData.items.push({ name: name, qty: qty, price: price, tax: tax, subtotal: subtotal });
                    });
                    posClearCartDom();
                    posUpdatePendingBadge();
                    var fmt = function (n) {
                        var v = Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        return (typeof posCurrencySymbol !== 'undefined' && posCurrencySymbol ? posCurrencySymbol + v : v);
                    };
                    var esc = function (s) { return $('<div>').text(s || '').html(); };
                    var s = typeof posPrintSettings !== 'undefined' ? posPrintSettings : {};
                    var companyName = (s.company_name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var companyBlock = (companyName || s.company_address) ? ('<div class="text-left">' + companyName + '<br>' + esc(s.mail_from_address) + '<br>' + esc(s.company_address) + '<br>' + esc(s.company_city) + (s.company_city && (s.company_state || s.company_zipcode) ? ', ' : '') + esc(s.company_state) + (s.company_state && s.company_zipcode ? ', ' : '') + esc(s.company_zipcode) + '<br>' + esc(s.company_country) + '<br>' + esc(s.company_telephone) + '<br></div>') : '';
                    var itemRows = '';
                    receiptData.items.forEach(function (it) {
                        var taxAmt = it.price * it.qty * it.tax / 100;
                        itemRows += '<div class="mt-2"><div class="p-0"><b>' + esc(it.name) + '</b></div>' +
                            '<div class="d-flex product-border"><div>{{ __("pos.quantity_colon") }}</div><div class="text-end ms-auto">' + it.qty + '</div></div>' +
                            '<div class="d-flex product-border"><div>{{ __("pos.price_colon") }}</div><div class="text-end ms-auto">' + fmt(it.price) + '</div></div>' +
                            '<div class="d-flex product-border"><div>{{ __("pos.tax_colon") }}</div><div class="text-end ms-auto">' + it.tax + '</div></div>' +
                            '<div class="d-flex product-border mb-2"><div>{{ __("pos.tax_amount_colon") }}</div><div class="text-end ms-auto">' + fmt(taxAmt) + '</div></div>' +
                            '<div class="d-flex product-border mb-2"><div>{{ __("pos.sub_total_colon") }}</div><div class="text-end ms-auto">' + fmt(it.subtotal) + '</div></div></div>';
                    });
                    var paySection = '<div class="product-border mb-2 mt-3 p-2"><div class="text-black fw-bold mb-2">{{ __("Payment") }}</div>' +
                        '<div class="d-flex product-border mb-1"><div>{{ __("Payment Method") }}:</div><div class="text-end ms-auto text-capitalize">' + esc(receiptData.paymentMethod) + '</div></div>' +
                        '<div class="d-flex product-border mb-1"><div>{{ __("Amount") }}:</div><div class="text-end ms-auto">' + fmt(receiptData.amount) + '</div></div>';
                    if (receiptData.change > 0) {
                        paySection += '<div class="d-flex product-border mb-1"><div><b>{{ __("Change") }}</b>:</div><div class="text-end ms-auto"><b>' + fmt(receiptData.change) + '</b></div></div>';
                    }
                    if (receiptData.transactionId) {
                        paySection += '<div class="d-flex product-border mb-1"><div>{{ __("Transaction ID") }}:</div><div class="text-end ms-auto">' + esc(receiptData.transactionId) + '</div></div>';
                    }
                    if (receiptData.creditTerms) {
                        paySection += '<div class="d-flex product-border mb-1"><div>{{ __("Credit Days") }}:</div><div class="text-end ms-auto">' + esc(receiptData.creditTerms) + '</div></div>';
                    }
                    if (receiptData.notes) {
                        paySection += '<div class="product-border mb-1 p-1 small"><div>{{ __("Notes") }}:</div><div>' + esc(receiptData.notes) + '</div></div>';
                    }
                    paySection += '</div>';
                    var printareaHtml = '<div class="pt-0 pb-3 modal-body pos-module" id="printarea">' +
                        '<table class="table pos-module-tbl"><tbody>' +
                        '<div class="text-center"><h3>' + companyName + '</h3></div><br>' +
                        '<div class="text-left"><b>Offline</b></div>' +
                        companyBlock +
                        '<div class="mt-2 no-print"><span class="text-warning small"><span class="ti ti-wifi-off me-1"></span>{{ __("pos.offline_banner") }}</span></div><br>' +
                        '<div>' + (receiptData.customer ? '{{ __("pos.name") }}:  ' + esc(receiptData.customer) : '') + '</div>' +
                        '<div>{{ __("pos.date_of_pos") }}:  ' + esc(receiptData.date) + '</div>' +
                        (receiptData.warehouseName ? '<div class="product-border">{{ __("pos.warehouse_name") }}:  ' + esc(receiptData.warehouseName) + '</div>' : '') +
                        '</tbody></table>' +
                        '<div class="text-black text-left fs-5 mt-0 mb-0">{{ __("Items") }}</div>' +
                        itemRows +
                        '<div class="d-flex product-border mb-2 mt-4"><div><b>{{ __("pos.discount_colon") }}</b></div><div class="text-end ms-auto">' + fmt(receiptData.discount) + '</div></div>' +
                        '<div class="d-flex product-border mb-2"><div><b>{{ __("pos.total_colon") }}</b></div><div class="text-end ms-auto">' + fmt(receiptData.total) + '</div></div>' +
                        paySection +
                        '<h5 class="text-center mt-3 font-label">{{ __("pos.thank_you") }}</h5></div>' +
                        '<div class="justify-content-center pt-2 modal-footer"><a href="#" id="print" class="btn btn-primary btn-sm text-right float-right mb-3">{{ __("pos.print") }}</a></div>';
                    $('#commonModal .modal-title').html('{{ __("pos.pos_invoice") }}');
                    $('.modal-dialog').removeClass('modal-xl modal-lg modal-md modal-sm').addClass('modal-sm');
                    $('#commonModal .body').attr('data-offline-total', '').html(printareaHtml);
                    $('#commonModal').modal('show');
                    if (typeof show_toastr === 'function') show_toastr('success', '{{ __("pos.saved_for_sync") }}');
                } else {
                    if (typeof show_toastr === 'function') show_toastr('warning', '{{ __("pos.no_data_found") }}');
                }
            });
            $(document).on('click', '#commonModal #print', function () {
                var print_div = document.getElementById('printarea');
                if (!print_div) return;
                $('.row').addClass('d-none');
                $('.toast').addClass('d-none');
                $('#commonModal #print').addClass('d-none');
                window.print();
                $('.row').removeClass('d-none');
                $('#commonModal #print').removeClass('d-none');
                $('.toast').removeClass('d-none');
            });
        });
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('{{ asset("sw-pos.js") }}').catch(function () {});
        }
    </script>

    <script>

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        $( document ).ready(function() {

            var abc = $( "#warehouse_name_hidden" ).val($('.warehouse_select').val());
            $( "#vc_name_hidden" ).val($('.customer_select').val());
            $( "#warehouse_name_hidden" ).val($('.warehouse_select').val());
            $( "#discount_hidden").val($('.discount').val());
            $( "#quotation_id").val($('.quotation').val());
            $(function () {
                fillRetailCategorySelect();
            });

            function fillRetailCategorySelect() {
                var $sel = $('#pos-retail-category');
                if (!$sel.length) return;
                $.get(posCategoriesJsonUrl, function (data) {
                    var currentVal = $sel.val();
                    $sel.find('option:not(:first)').remove();
                    (data || []).forEach(function (item) {
                        if (item.id !== 0) {
                            $sel.append($('<option></option>').val(item.id).text(item.name));
                        }
                    });
                    $sel.val(currentVal || '0');
                    var ware_id = $( "#warehouse" ).val();
                    searchProducts(posSearchProductsUrl, '', $sel.val() || '0', ware_id, 'name');
                });
            }

            $('#pos-retail-category').on('change', function () {
                var cat = $(this).val() || '0';
                var ware_id = $( "#warehouse" ).val();
                searchProducts(posSearchProductsUrl, '', cat, ware_id, 'name');
            });

            if ($('.barcodeScanner').length > 0) {
                var url = $('.barcodeScanner').data('url');
                var ware_id = $( "#warehouse" ).val();
                var cat = $( "#pos-retail-category" ).val() || '0';
                searchProducts(url, '', cat, ware_id, 'sku');
            }

            if ($('.searchproduct').length > 0) {
                var url = $('.searchproduct').data('url');
                var ware_id = $( "#warehouse" ).val();
                var cat = $( "#pos-retail-category" ).val() || '0';
                searchProducts(url, '', cat, ware_id, 'name');
            }

            $( '#warehouse' ).change(function() {
                var ware_id = $( "#warehouse" ).val();
                var cat = $( "#pos-retail-category" ).val() || '0';
                searchProducts(posSearchProductsUrl, '', cat, ware_id, 'name');
            });

            var restaurantBranchIds = @json($restaurantBranchIds ?? []);
            var posRestaurantLayoutUrl = '{{ route("pos.restaurant.layout") }}';
            var posBranchOpenShiftsUrl = '{{ route("pos.branch.open.shifts") }}';
            var posCurrentUserId = {{ auth()->id() ?? 'null' }};

            $('.pos-mode-btn').on('click', function () {
                var mode = $(this).data('mode');
                $('.pos-mode-btn').removeClass('active');
                $(this).addClass('active');
                $('#pos-retail-panel').toggleClass('d-none', mode !== 'retail');
                $('#pos-restaurant-panel').toggleClass('d-none', mode !== 'restaurant');
                if (mode === 'restaurant') {
                    var bid = $('#pos-restaurant-branch').val();
                    $('#branch_id').val(bid || '');
                    if (bid) {
                        loadRestaurantTables(bid);
                        loadBranchShifts(bid);
                    } else {
                        loadBranchShifts(null);
                    }
                }
            });

            function loadBranchShifts(branchId) {
                $('#pos_shift_id_hidden').val('');
                if (!branchId) return;
                $.ajax({
                    url: posBranchOpenShiftsUrl,
                    data: { branch_id: branchId },
                    dataType: 'json',
                    success: function (res) {
                        var shifts = res && res.shifts ? res.shifts : [];
                        var currentUserShiftId = null;
                        shifts.forEach(function (s) {
                            if (s.is_current_user) currentUserShiftId = s.id;
                        });
                        $('#pos_shift_id_hidden').val(currentUserShiftId || '');
                    }
                });
            }

            $('#pos-restaurant-branch').on('change', function () {
                var branchId = $(this).val();
                $('#branch_id').val(branchId);
                $('#pos_restaurant_table_id').val('');
                $('#pos_table_id_hidden').val('');
                $('#pos_table_id').val('');
                $('#pos-selected-table').text('—');
                if (branchId) {
                    loadRestaurantTables(branchId);
                    loadBranchShifts(branchId);
                    $('#pos-restaurant-placeholder').addClass('d-none');
                    $('#pos-restaurant-order').removeClass('d-none');
                    var sk = $( "#empty_cart" ).val() || sessionKey;
                    $.ajax({
                        type: 'POST',
                        url: posEmptyCartUrl,
                        data: { session_key: sk },
                        success: function () {},
                        complete: function () {
                            $.get('{{ route("pos.warehouses.by.branch") }}', { branch_id: branchId }, function (data) {
                                var ids = Object.keys(data);
                                if (ids.length) {
                                    var wh = ids[0];
                                    $('#warehouse').val(wh);
                                    $('#warehouse_name_hidden').val(wh);
                                    $('#vc_name_hidden').val('');
                                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                                    getProductCategoriesRestaurant();
                                }
                            });
                            $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                            $('#pos-restaurant-total').text('0');
                            $('#pos-pay-btn-restaurant').prop('disabled', false);
                        }
                    });
                } else {
                    $('#pos-restaurant-order').addClass('d-none');
                    $('#pos-restaurant-placeholder').removeClass('d-none');
                    $('#pos-table-grid').html('<p class="text-muted small mb-0">{{ __("pos.select_branch_first") }}</p>');
                    loadBranchShifts(null);
                }
            });

            if ($('#pos-restaurant-panel').length && !$('#pos-restaurant-panel').hasClass('d-none')) {
                var initRestBranch = $('#pos-restaurant-branch').val();
                if (initRestBranch) loadBranchShifts(initRestBranch);
            }

            function loadRestaurantTables(branchId) {
                $.get(posRestaurantLayoutUrl, { branch_id: branchId }, function (res) {
                    var html = '';
                    (res.areas || []).forEach(function (area) {
                        html += '<div class="mb-2"><small class="text-muted d-block mb-1">' + area.name + '</small><div class="d-flex flex-wrap gap-2">';
                        (area.tables || []).forEach(function (t) {
                            var statusClass = t.status === 'occupied' ? 'btn-warning' : (t.status === 'reserved' ? 'btn-secondary' : 'btn-outline-primary');
                            html += '<button type="button" class="btn ' + statusClass + ' btn-sm pos-table-btn" data-id="' + t.id + '" data-name="' + (t.name || '') + '">' + t.name + '</button>';
                        });
                        html += '</div></div>';
                    });
                    $('#pos-table-grid').html(html || '<p class="text-muted small mb-0">{{ __("pos.no_data_found") }}</p>');
                });
            }

            var posTableUpdateStatusUrl = '{{ url("pos-table") }}';
            $(document).on('click', '.pos-table-btn', function () {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#pos_restaurant_table_id').val(id);
                $('#pos_restaurant_table_name').val(name);
                $('#pos_table_id_hidden').val(id);
                $('#pos_order_type_hidden').val('dine_in');
                $('#pos-selected-table').text(name || ('Table ' + id));
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/status',
                    method: 'PATCH',
                    data: { status: 'occupied', _token: $('meta[name="csrf-token"]').attr('content') },
                    success: function () {
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId) loadRestaurantTables(branchId);
                    }
                });
            });

            function getProductCategoriesRestaurant() {
                $.get(posCategoriesUrl, function (data) {
                    $('#categories-listing-restaurant').html(data);
                });
            }

            $(document).on('click', '#pos-restaurant-panel .category-select', function () {
                var $panel = $('#pos-restaurant-panel');
                $panel.find('.category-select').parent().removeClass('cat-active');
                $(this).parent().addClass('cat-active');
                var cat = $(this).data('cat-id');
                var warehouse_id = $('#warehouse').val();
                searchProducts(posSearchProductsUrl, '', cat, warehouse_id, 'name', 'product-listing-restaurant');
            });

            $(document).on('click', '#pos-pay-btn-restaurant', function (e) {
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                if (!tableId) {
                    e.preventDefault();
                    e.stopPropagation();
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table_required") }}', 'error');
                    return false;
                }
                var url = $(this).data('url');
                var title = $(this).data('title') || '{{ __("pos.pos_invoice") }}';
                var data = {};
                if ($('#vc_name_hidden').length) data['vc_name'] = $('#vc_name_hidden').val();
                if ($('#warehouse_name_hidden').length) data['warehouse_name'] = $('#warehouse_name_hidden').val();
                if ($('#discount_hidden').length) data['discount'] = $('#discount_hidden').val();
                if ($('#quotation_id').length) data['quotation_id'] = $('#quotation_id').val();
                if ($('#pos_order_type_hidden').length) data['pos_order_type'] = $('#pos_order_type_hidden').val();
                if ($('#pos_table_id_hidden').length) data['pos_table_id'] = $('#pos_table_id_hidden').val();
                if ($('#pos_shift_id_hidden').length) data['pos_shift_id'] = $('#pos_shift_id_hidden').val();
                $('#commonModal .modal-title').html(title);
                $('.modal-dialog').removeClass('modal-xl modal-lg modal-md modal-sm').addClass('modal-sm');
                $.ajax({
                    url: url,
                    data: data,
                    success: function (html) {
                        $('#commonModal .body').html(html);
                        $('#commonModal').modal('show');
                        if (typeof taskCheckbox === 'function') taskCheckbox();
                        if (typeof common_bind === 'function') common_bind('#commonModal');
                        if (typeof validation === 'function') validation();
                        if (typeof commonLoader === 'function') commonLoader();
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            $(document).on('click', '#pos-empty-cart-restaurant', function () {
                if (!confirm('{{ __("pos.remove_all_items") }}')) return;
                var sk = $( "#empty_cart" ).val() || sessionKey;
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: { session_key: sk },
                    success: function () {
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#pos-restaurant-total').text('0');
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        show_toastr('success', '{{ __("Cart is empty!") }}', 'success');
                    }
                });
            });

            $(document).on('click', '#pos-print-kitchen-slip', function () {
                var tableName = $('#pos-selected-table').text();
                var branchId = $('#pos-restaurant-branch').val();
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var rows = [];
                var items = [];
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var name = $(this).attr('data-name') || $(this).find('.name').text();
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || 1, 10);
                    var $n = $(this).find('.notes');
                    var note = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    rows.push({ name: name, qty: qty, note: note });
                    var id = $(this).attr('data-product-id');
                    var price = parseFloat($(this).attr('data-price')) || 0;
                    var discount = parseFloat($(this).attr('data-discount')) || 0;
                    if (id && qty > 0) items.push({ id: id, quantity: qty, price: price, discount: discount, description: note });
                });
                if (rows.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                function doPrint() {
                    var w = window.open('', '_blank', 'width=380,height=400');
                    w.document.write('<html><head><title>{{ __("pos.kitchen_slip") }}</title><style>body{font-family:monospace;padding:12px;font-size:14px;}h3{margin:0 0 8px;}table{width:100%;}td{padding:4px 0;}td:last-child{text-align:right;}.note{font-size:12px;color:#555;font-style:italic;}</style></head><body>');
                    w.document.write('<h3>{{ __("pos.kitchen_slip") }}</h3>');
                    w.document.write('<p><strong>{{ __("pos.table") }}:</strong> ' + (tableName || '—') + '</p>');
                    w.document.write('<p><small>' + new Date().toLocaleString() + '</small></p>');
                    w.document.write('<table><thead><tr><th>{{ __("pos.name") }}</th><th>{{ __("pos.qty") }}</th><th>{{ __("Note") }}</th></tr></thead><tbody>');
                    rows.forEach(function(r) {
                        var note = (r.note || '').replace(/</g, '&lt;');
                        w.document.write('<tr><td>' + (r.name || '').replace(/</g, '&lt;') + '</td><td>' + r.qty + '</td><td class="note">' + note + '</td></tr>');
                    });
                    w.document.write('</tbody></table></body></html>');
                    w.document.close();
                    w.focus();
                    w.print();
                    w.close();
                }
                var orderTypeMain = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                if (items.length > 0 && branchId && tableId && shiftId) {
                    $.ajax({
                        type: 'POST',
                        url: '{{ route("pos.send.to.production") }}',
                        data: { _token: '{{ csrf_token() }}', branch_id: branchId, table_id: tableId, shift_id: shiftId, order_type: orderTypeMain, items: items },
                        success: function (res) {
                            if (res.success) {
                                doPrint();
                                $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="8">{{ __("pos.no_data_found") }}</td></tr>');
                                $('#pos-restaurant-total').text('0');
                                $('#pos-pay-btn-restaurant').prop('disabled', true);
                            }
                        },
                        error: function (xhr) {
                            var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
                            show_toastr('{{ __("Error") }}', msg, 'error');
                        }
                    });
                } else {
                    doPrint();
                }
            });

            function posGetRestaurantTotal() {
                var totalText = $('#pos-restaurant-total').text().replace(/,/g, '').replace(/[^\d.-]/g, '');
                var total = parseFloat(totalText) || 0;
                if (total <= 0) {
                    $('#tbody-restaurant tr[data-product-id]').each(function () {
                        var st = parseFloat($(this).attr('data-subtotal')) || 0;
                        if (st === 0) {
                            var subText = $(this).find('.subtotal').text().replace(/,/g, '').replace(/[^\d.-]/g, '');
                            st = parseFloat(subText) || 0;
                        }
                        total += st;
                    });
                }
                return total;
            }
            function posFormatAmount(v) {
                var symbol = (typeof site_currency_symbol !== 'undefined') ? site_currency_symbol : '';
                var pos = (typeof site_currency_symbol_position !== 'undefined') ? site_currency_symbol_position : 'pre';
                return pos === 'pre' ? symbol + Number(v).toFixed(2) : Number(v).toFixed(2) + symbol;
            }
            $(document).on('click', '#pos-split-bill-restaurant', function () {
                var total = posGetRestaurantTotal();
                if (total <= 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                $('#pos-split-total-display').text(posFormatAmount(total));
                $('#pos-split-parts-input').val(2).attr('data-total', total);
                var n = 2;
                $('#pos-split-per-person').text(posFormatAmount(total / n));
                $('#posSplitBillModal').modal('show');
            });
            $(document).on('input change', '#pos-split-parts-input', function () {
                var total = parseFloat($(this).attr('data-total')) || parseFloat($(this).data('total')) || 0;
                var n = parseInt($(this).val(), 10) || 2;
                if (n < 2) n = 2;
                $(this).val(n);
                $('#pos-split-per-person').text(posFormatAmount(total / n));
            });
            $(document).on('click', '#pos-split-apply-btn', function () {
                var total = parseFloat($('#pos-split-parts-input').attr('data-total')) || 0;
                var n = parseInt($('#pos-split-parts-input').val(), 10) || 2;
                var perPart = total / n;
                $('#posSplitBillModal').modal('hide');
                var msg = '{{ __("pos.split_result") }}'.replace(':count', n).replace(':amount', posFormatAmount(perPart));
                show_toastr('{{ __("pos.split_bill") }}', msg, 'success');
            });

            $(document).on('click', '#pos-manage-tables-btn', function () {
                var branchId = $('#pos-restaurant-branch').val();
                if (!branchId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_branch_first") }}', 'error');
                    return;
                }
                $('#pos-table-management-body').html('<p class="text-muted small">{{ __("Loading...") }}</p>');
                $('#posTableManagementModal').modal('show');
                $.get(posRestaurantLayoutUrl, { branch_id: branchId }, function (res) {
                    var html = '<div class="list-group list-group-flush">';
                    (res.areas || []).forEach(function (area) {
                        (area.tables || []).forEach(function (t) {
                            var statusClass = t.status === 'occupied' ? 'warning' : (t.status === 'reserved' ? 'secondary' : 'success');
                            var statusLabel = t.status === 'occupied' ? '{{ __("pos.occupied") }}' : (t.status === 'reserved' ? '{{ __("pos.reserved") }}' : '{{ __("pos.available") }}');
                            html += '<div class="list-group-item d-flex justify-content-between align-items-center">';
                            html += '<span><strong>' + (t.name || 'Table ' + t.id) + '</strong> <span class="badge bg-' + statusClass + ' ms-2">' + statusLabel + '</span></span>';
                            if (t.status === 'occupied' || t.status === 'reserved') {
                                html += '<button type="button" class="btn btn-sm btn-outline-success pos-table-set-available" data-id="' + t.id + '">{{ __("pos.set_available") }}</button>';
                            }
                            html += '</div>';
                        });
                    });
                    html += '</div>';
                    if (!res.areas || res.areas.length === 0 || (res.areas.every(function(a) { return !(a.tables && a.tables.length); }))) {
                        html = '<p class="text-muted small mb-0">{{ __("pos.no_data_found") }}</p>';
                    }
                    $('#pos-table-management-body').html(html);
                });
            });
            $(document).on('click', '.pos-table-set-available', function () {
                var id = $(this).data('id');
                var $btn = $(this);
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/status',
                    method: 'PATCH',
                    data: { status: 'available', _token: $('meta[name="csrf-token"]').attr('content') },
                    success: function () {
                        $btn.closest('.list-group-item').find('.badge').removeClass('bg-warning bg-secondary').addClass('bg-success').text('{{ __("pos.available") }}');
                        $btn.remove();
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            function toggleRestaurantOptions(branchId) {
                if (restaurantBranchIds.indexOf(parseInt(branchId, 10)) !== -1) {
                    $( '#pos-restaurant-options' ).removeClass('d-none');
                    $.get('{{ route("pos-table.by.branch") }}', { branch_id: branchId }, function(data) {
                        var $sel = $( '#pos_table_id' );
                        $sel.empty().append('<option value="">{{ __("Select Table") }}</option>');
                        $.each(data, function(i, t) {
                            $sel.append($('<option></option>').val(t.id).text(t.name));
                        });
                    });
                } else {
                    $( '#pos-restaurant-options' ).addClass('d-none');
                    $( '#pos_order_type' ).val('');
                    $( '#pos_table_id' ).empty();
                    $( '#pos_order_type_hidden' ).val('');
                    $( '#pos_table_id_hidden' ).val('');
                }
            }
            $( '#pos_order_type' ).change(function() { $( '#pos_order_type_hidden' ).val($(this).val()); });
            $( '#pos_table_id' ).change(function() { $( '#pos_table_id_hidden' ).val($(this).val()); });

            // On load: if branch is pre-selected (e.g. single branch), load restaurant options & warehouses
            var initBranchId = $( '#branch_id' ).val();
            if (initBranchId) {
                toggleRestaurantOptions(initBranchId);
                $.get('{{ route("pos.warehouses.by.branch") }}', { branch_id: initBranchId }, function(data) {
                    var $warehouse = $( '#warehouse' );
                    $warehouse.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                    $.each(data, function(id, name) {
                        $warehouse.append($('<option></option>').val(id).text(name));
                    });
                });
            }

            // Multi-branch: filter warehouses when branch changes
            $( '#branch_id' ).change(function() {
                var branchId = $(this).val();
                toggleRestaurantOptions(branchId);
                var session_key = $( "#empty_cart" ).val();
                $.ajax({
                    type: 'GET',
                    url: '{{ route("pos.warehouses.by.branch") }}',
                    data: { branch_id: branchId },
                    success: function (data) {
                        var $warehouse = $( '#warehouse' );
                        $warehouse.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(data, function(id, name) {
                            $warehouse.append($('<option></option>').val(id).text(name));
                        });
                        $( "#warehouse_name_hidden" ).val('');
                        // Empty cart when branch changes
                        $.ajax({
                            type: 'POST',
                            url: '{{ route("warehouse-empty-cart") }}',
                            data: { session_key: session_key },
                            success: function () {
                                $( "#tbody" ).empty();
                                $("#tbody").html(posEmptyCartHtmlRetail);
                                $('#pos-cart-count').text('(0)');
                            }
                        });
                    }
                });
            });

            function posUpdateCustomerCard() {
                var $sel = $('#customer option:selected');
                var name = $sel.text();
                var email = $sel.data('email') || '';
                var contact = $sel.data('contact') || '';
                var type = $sel.data('type') || 'customer';
                $('#pos-customer-name').text(name || '');
                $('#pos-customer-email').text(email);
                $('#pos-customer-phone').text(contact);
                $('#pos-customer-badge').text(type === 'walk-in' ? '{{ __("Walk-in") }}' : '{{ __("Customer") }}');
                $('#vc_name_hidden').val($('#customer').val());
                // Show Edit only when Walk-in customer is selected (retail: add new customer)
                var isWalkIn = ($('#customer').val() || '').toString() === 'Walk-in-customer' || type === 'walk-in';
                $('#pos-customer-edit').toggleClass('d-none', !isWalkIn);
                // Fetch and show customer balance when a real customer is selected
                posFetchCustomerBalance();
            }
            function posFetchCustomerBalance() {
                var vc = ($('#vc_name_hidden').val() || $('#customer').val() || '').toString().trim();
                if (vc === '' || vc === 'Walk-in-customer') {
                    $('#pos-customer-balance-wrap').addClass('d-none');
                    $('#pos-customer-balance').text('0');
                    return;
                }
                $.get('{{ route("pos.customer.balance") }}', { vc_name: vc }, function(res) {
                    if (res.success) {
                        var bal = parseFloat(res.balance) || 0;
                        $('#pos-customer-balance').text(res.formatted || '0').attr('data-raw', bal);
                        if (bal > 0) {
                            $('#pos-customer-balance-wrap').removeClass('d-none');
                        } else {
                            $('#pos-customer-balance-wrap').addClass('d-none');
                        }
                    } else {
                        $('#pos-customer-balance-wrap').addClass('d-none');
                    }
                }).fail(function() {
                    $('#pos-customer-balance-wrap').addClass('d-none');
                });
            }
            $(document).on('click', '#pos-pay-balance-btn', function(e) {
                e.preventDefault();
                var vc = ($('#vc_name_hidden').val() || '').toString().trim();
                var balanceNum = parseFloat($('#pos-customer-balance').attr('data-raw')) || 0;
                $('#pos-pay-balance-vc-name').val(vc);
                $('#pos-pay-balance-amount').val(balanceNum > 0 ? balanceNum.toFixed(2) : '');
                $('#pos-pay-balance-error').addClass('d-none').text('');
                var $inv = $('#pos-pay-balance-invoices');
                var $totalDue = $('#pos-pay-balance-total-due');
                $inv.html('<p class="text-muted small mb-0">{{ __("Loading...") }}</p>');
                $totalDue.text('0');
                $.get('{{ route("pos.customer.outstanding.invoices") }}', { vc_name: vc }, function(res) {
                    if (res.success && res.invoices && res.invoices.length) {
                        var html = '<div class="small">';
                        res.invoices.forEach(function(inv) {
                            html += '<label class="d-flex align-items-center gap-2 py-1 border-bottom border-light"><input type="checkbox" name="invoice_ids[]" value="' + inv.id + '" class="form-check-input pos-pay-inv-cb" checked> <span class="flex-grow-1">' + (inv.ref_number || inv.invoice_id) + '</span> <span class="text-end">' + inv.due_formatted + '</span></label>';
                        });
                        html += '</div>';
                        $inv.html(html);
                        $totalDue.text(res.formatted || '0');
                    } else {
                        $inv.html('<p class="text-muted small mb-0">{{ __("No outstanding invoices.") }}</p>');
                        $totalDue.text(res.formatted || '0');
                    }
                }).fail(function() {
                    $inv.html('<p class="text-danger small mb-0">{{ __("Error loading invoices.") }}</p>');
                });
                var $sel = $('#pos-pay-balance-account');
                $sel.empty().append('<option value="">{{ __("Loading...") }}</option>');
                $.get('{{ route("pos.bank.accounts") }}', function(res) {
                    $sel.empty().append('<option value="">{{ __("Select account") }}</option>');
                    if (res.success && res.accounts && res.accounts.length) {
                        $.each(res.accounts, function(i, a) {
                            $sel.append($('<option></option>').val(a.id).text(a.label));
                        });
                    }
                });
                $('#posPayBalanceModal').modal('show');
            });
            $(document).on('submit', '#pos-pay-balance-form', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $err = $('#pos-pay-balance-error');
                var $submit = $('#pos-pay-balance-submit');
                $err.addClass('d-none').text('');
                $submit.prop('disabled', true);
                var data = $form.serializeArray();
                if (!$('#pos-pay-balance-payment-method').length || !$('#pos-pay-balance-payment-method').val()) {
                    data.push({ name: 'payment_method', value: 'cash' });
                }
                $.ajax({
                    url: '{{ route("pos.customer.pay.balance") }}',
                    type: 'POST',
                    data: $.param(data),
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function(res) {
                        if (res.success) {
                            $('#pos-customer-balance').text(res.formatted || '0').attr('data-raw', res.balance);
                            if (res.balance <= 0) {
                                $('#pos-customer-balance-wrap').addClass('d-none');
                            }
                            $('#posPayBalanceModal').modal('hide');
                            if ($('#liveToast').length) {
                                var t = new bootstrap.Toast(document.getElementById('liveToast'));
                                $('#liveToast .toast-body').text(res.message || '{{ __("Payment successfully added.") }}');
                                t.show();
                            }
                            if (res.receipt) {
                                var r = res.receipt;
                                var win = window.open('', '_blank', 'width=400,height=500');
                                win.document.write('<html><head><title>{{ __("Payment Receipt") }}</title><style>body{font-family:system-ui;padding:16px;} table{width:100%;} th{text-align:left;} .text-end{text-align:right;}</style></head><body>');
                                win.document.write('<h4>{{ __("Payment Receipt") }}</h4>');
                                win.document.write('<p><strong>{{ __("Customer") }}:</strong> ' + (r.customer_name || '') + '</p>');
                                win.document.write('<p><strong>{{ __("Date") }}:</strong> ' + (r.date || '') + '</p>');
                                win.document.write('<p><strong>{{ __("Payment method") }}:</strong> ' + (r.payment_method || '') + '</p>');
                                win.document.write('<p><strong>{{ __("Amount paid") }}:</strong> ' + (r.amount_formatted || '') + '</p>');
                                if (r.invoices_paid && r.invoices_paid.length) {
                                    win.document.write('<p><strong>{{ __("Applied to invoices") }}:</strong></p><ul>');
                                    r.invoices_paid.forEach(function(i) { win.document.write('<li>' + (i.ref_number || '') + ': ' + (i.amount || 0) + '</li>'); });
                                    win.document.write('</ul>');
                                }
                                win.document.write('<p><strong>{{ __("Remaining balance") }}:</strong> ' + (r.new_balance_formatted || '') + '</p>');
                                win.document.write('<p class="mt-4"><button onclick="window.print()">{{ __("Print") }}</button></p>');
                                win.document.write('</body></html>');
                                win.document.close();
                            }
                        } else {
                            $err.text(res.message || '{{ __("Error.") }}').removeClass('d-none');
                        }
                    },
                    error: function(xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : (xhr.statusText || '{{ __("Error.") }}');
                        $err.text(msg).removeClass('d-none');
                    },
                    complete: function() {
                        $submit.prop('disabled', false);
                    }
                });
            });
            $(document).on('click', '#pos-customer-change', function(e) {
                e.preventDefault();
                $('#customer').removeClass('d-none').focus();
            });
            $(document).on('click', '#pos-customer-edit', function(e) {
                e.preventDefault();
                $('#pos-new-customer-form')[0].reset();
                $('#pos-new-customer-error').addClass('d-none').text('');
                $('#posNewCustomerModal').modal('show');
            });
            $(document).on('submit', '#pos-new-customer-form', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $err = $('#pos-new-customer-error');
                var $submit = $('#pos-new-customer-submit');
                $err.addClass('d-none').text('');
                $submit.prop('disabled', true);
                $.ajax({
                    url: '{{ route("customer.store.from.pos") }}',
                    type: 'POST',
                    data: $form.serialize(),
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function(res) {
                        if (res.success && res.customer) {
                            var c = res.customer;
                            var $opt = $('<option></option>').attr('value', c.name).text(c.name)
                                .attr('data-email', c.email || '').attr('data-contact', c.contact || '').attr('data-type', 'customer');
                            $('#customer').append($opt).val(c.name).addClass('d-none');
                            posUpdateCustomerCard();
                            $('#posNewCustomerModal').modal('hide');
                            $form[0].reset();
                        } else {
                            $err.text(res.message || '{{ __("Error saving customer.") }}').removeClass('d-none');
                        }
                    },
                    error: function(xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : (xhr.statusText || '{{ __("Error saving customer.") }}');
                        $err.text(msg).removeClass('d-none');
                    },
                    complete: function() {
                        $submit.prop('disabled', false);
                    }
                });
            });
            $(document).on('change blur', '#customer', function() {
                posUpdateCustomerCard();
                $('#customer').addClass('d-none');
            });
            $( '.customer_select' ).change(function() {
                $( "#vc_name_hidden" ).val($(this).val());
                posUpdateCustomerCard();
            });
            posUpdateCustomerCard();
            $( '.warehouse_select' ).change(function() {
                $( "#warehouse_name_hidden" ).val($(this).val());
                var ware_id = $( "#warehouse" ).val();
                var clearCartAndLoad = function() {
                    $( "#tbody" ).empty();
                    $("#tbody").html(posEmptyCartHtmlRetail);
                    $('#pos-cart-count').text('(0)');
                    $('.no-found').removeClass('d-none');
                    $('#pos-pay-btn').prop('disabled', true);
                    $('.btn-empty button').removeClass('btn-clear-cart');
                    searchProducts(posSearchProductsUrl, '', '0', ware_id, 'name');
                };
                if (!navigator.onLine) {
                    clearCartAndLoad();
                    return;
                }
                var session_key = $( "#empty_cart" ).val();
                $.ajax({
                    type: 'POST',
                    url: '{{route('warehouse-empty-cart')}}',
                    data: { 'session_key': session_key },
                    success: function (data) { clearCartAndLoad(); },
                    error: function() { clearCartAndLoad(); }
                });
            });




            $(document).on('click', '#clearinput', function (e) {
                var IDs = [];
                $(this).closest('div').find("input").each(function () {
                    IDs.push('#' + this.id);
                });
                $(IDs.toString()).val('');
            });

            function posDebounce(fn, ms) {
                var t;
                return function() {
                    var self = this, args = arguments;
                    clearTimeout(t);
                    t = setTimeout(function() { fn.apply(self, args); }, ms);
                };
            }
            var searchProductDebounced = posDebounce(function() {
                var url = $(this).data('url');
                var value = this.value;
                var ware_id = $( "#warehouse" ).val();
                var cat = $( "#pos-retail-category" ).val() || '0';
                if (value != '') {
                    searchProducts(url, value, cat, ware_id, 'name');
                }
            }, 280);
            var searchProductRestaurantDebounced = posDebounce(function() {
                var url = $(this).data('url');
                var value = this.value;
                var ware_id = $( "#warehouse" ).val();
                var cat = $('#pos-restaurant-panel .cat-active').length ? $('#pos-restaurant-panel .cat-active').first().children().data('cat-id') : '0';
                searchProducts(url, value, cat, ware_id, 'name', 'product-listing-restaurant');
            }, 280);
            var barcodeSearchDebounced = posDebounce(function() {
                var url = $(this).data('url');
                var value = this.value;
                var ware_id = $( "#warehouse" ).val();
                var cat = $( "#pos-retail-category" ).val() || '0';
                if (value != '') {
                    searchProducts(url, value, cat, ware_id, 'sku');
                }
            }, 280);
            $(document).on('keyup', 'input.searchproduct', searchProductDebounced);
            $(document).on('keyup', 'input.searchproduct-restaurant', searchProductRestaurantDebounced);
            $(document).on('keyup', 'input.barcodeScanner', barcodeSearchDebounced);


            var posSearchPage = 1;
            var posLastSearchParams = {};
            function searchProducts(url, value, cat_id, war_id, type, targetId, appendPage) {
                war_id = war_id || '0';
                type = type || 'name';
                targetId = targetId || 'product-listing';
                var page = (appendPage === true) ? (posSearchPage + 1) : 1;
                if (appendPage !== true) { posSearchPage = 1; posLastSearchParams = { url: url, value: value, cat_id: cat_id, war_id: war_id, type: type, targetId: targetId }; }
                var cacheKey = posProductCacheKey(war_id, cat_id, value, type);
                function renderProducts(html, append) {
                    var $target = $('#' + targetId);
                    if (append) {
                        $target.find('.pos-load-more-wrap').remove();
                        $target.append(html);
                    } else {
                        $target.html(html);
                    }
                }
                function showLoading() {
                    $('#' + targetId).html('<div class="col-12 text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>{{ __("Loading...") }}</div>');
                }
                function showOfflineNoProducts() {
                    renderProducts('<div class="card card-body col-12 text-center"><h5>{{ __("pos.offline_no_products") }}</h5><small>{{ __("pos.offline_load_first") }}</small></div>');
                }
                function maybeAddLoadMore(htmlStr) {
                    var $tmp = $('<div>').append($.parseHTML(htmlStr));
                    var count = $tmp.find('.toacart').length;
                    if (count >= 50 && posLastSearchParams.url) {
                        var loadMoreHtml = '<div class="col-12 pos-load-more-wrap text-center py-2"><button type="button" class="btn btn-outline-primary btn-sm pos-load-more-products">{{ __("pos.load_more") }}</button></div>';
                        $('#' + targetId).find('.pos-load-more-wrap').remove();
                        $('#' + targetId).append(loadMoreHtml);
                    }
                }
                if (!navigator.onLine) {
                    var cached = posGetCachedProducts(cacheKey);
                    if (cached) renderProducts(cached);
                    else showOfflineNoProducts();
                    return;
                }
                if (appendPage !== true) showLoading();
                $.ajax({
                    type: 'GET',
                    url: url,
                    data: { 'search': value, 'cat_id': cat_id, 'war_id': war_id, 'session_key': session_key, 'type': type, 'page': page },
                    success: function(data) {
                        if (appendPage === true) {
                            posSearchPage = page;
                            renderProducts(data, true);
                            maybeAddLoadMore(data);
                        } else {
                            posSetCachedProducts(cacheKey, data);
                            renderProducts(data);
                            maybeAddLoadMore(data);
                        }
                    },
                    error: function() {
                        var cached = posGetCachedProducts(cacheKey);
                        if (cached) renderProducts(cached);
                        else showOfflineNoProducts();
                    }
                });
            }
            $(document).on('click', '.pos-load-more-products', function() {
                var p = posLastSearchParams;
                if (p.url) searchProducts(p.url, p.value, p.cat_id, p.war_id, p.type, p.targetId, true);
            });

            $(document).on('click', '.toacart', function () {
                var $el = $(this);
                var $btn = $el.find('.pos-add-cart-btn, button').first();
                var isRestaurant = $el.closest('#pos-restaurant-panel').length > 0;
                var tbodySel = isRestaurant ? '#tbody-restaurant' : '#tbody';
                var rowIdPrefix = isRestaurant ? 'product-id-r-' : 'product-id-';
                var totalSel = isRestaurant ? '#pos-restaurant-total' : '#displaytotal, .totalamount';

                if (!navigator.onLine && $el.data('product-id')) {
                    var id = $el.data('product-id');
                    var name = $el.data('product-name') || '';
                    var price = parseFloat($el.data('product-price')) || 0;
                    var taxRate = parseFloat($el.data('product-tax')) || 0;
                    var imgSrc = $el.data('product-image') || '';
                    var tax = price * taxRate / 100;
                    var subtotal = price + tax;
                    var qty = 1;
                    var $row = $(tbodySel + ' tr[id="' + rowIdPrefix + id + '"]');
                    if ($row.length) {
                        qty = parseInt($row.find('input.input-number').val() || 1, 10) + 1;
                        subtotal = (price + tax) * qty;
                        $row.find('input.input-number').val(qty);
                        $row.attr('data-quantity', qty).attr('data-subtotal', subtotal);
                        $row.find('.subtotal').text(addCommas(subtotal));
                    } else {
                        var row = isRestaurant ? ('<tr data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '"><td class="cart-images"><img alt="" src="' + imgSrc + '" class="card-image avatar shadow hover-shadow-lg"></td><td class="name">' + (name.replace(/</g, '&lt;')) + '</td><td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" title="{{ __("pos.quantity") }}" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus"></span></td><td class="tax">-</td><td class="price">' + addCommas(price) + '</td><td class="subtotal">' + addCommas(subtotal) + '</td><td class="action-btn"><a href="#" class="btn btn-sm bg-danger pos-offline-remove" data-id="' + id + '" title="{{ __("pos.delete") }}"><i class="ti ti-trash text-white"></i></a></td></tr>') : ('<div class="pos-cart-card-item card border rounded-2 mb-2 shadow-sm" data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '"><div class="card-body p-2"><div class="d-flex justify-content-between align-items-start"><div class="flex-grow-1 min-w-0"><h6 class="mb-0 fw-bold name">' + (name.replace(/</g, '&lt;')) + '</h6></div><div class="action-btn"><a href="#" class="btn btn-sm btn-danger p-1 pos-offline-remove" data-id="' + id + '"><i class="ti ti-trash"></i></a></div></div><div class="d-flex align-items-center justify-content-between mt-2"><span class="quantity buttons_added d-inline-flex align-items-center border rounded"><input type="button" value="-" class="minus btn btn-sm btn-outline-secondary border-0 py-0"><input type="number" step="1" min="1" name="quantity" class="input-number form-control form-control-sm border-0 text-center" style="width:2.5rem;" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus btn btn-sm btn-outline-secondary border-0 py-0"></span><div class="fw-bold subtotal">' + addCommas(subtotal) + '</div></div><small class="text-muted tax">Tax: -</small></div></div>');
                        $(tbodySel + ' .no-found').addClass('d-none');
                        $(tbodySel).append(row);
                        if (!isRestaurant) {
                            $('#pos-pay-btn').prop('disabled', false);
                            $('.btn-empty button').addClass('btn-clear-cart');
                        } else {
                            $('#pos-pay-btn-restaurant').prop('disabled', false);
                        }
                    }
                    var total = 0;
                    $(posCartItemSelector(tbodySel)).each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                    $(totalSel).text(addCommas(total));
                    if (!isRestaurant) $('#pos-cart-count').text('(' + $(posCartItemSelector('#tbody')).length + ')');
                    return;
                }
                var sum = 0;
                var wasDisabled = $btn.length && $btn.prop('disabled');
                if ($btn.length && !wasDisabled) {
                    $btn.prop('disabled', true).addClass('pos-add-cart-loading');
                    var $icon = $btn.find('i');
                    if ($icon.length) $icon.removeClass('ti-plus').addClass('ti-loader ti-spin');
                }
                var addToCartUrl = $el.data('url');
                var taxRate = $el.data('product-tax');
                if (taxRate !== undefined && taxRate !== '' && !isNaN(parseFloat(taxRate))) {
                    addToCartUrl += (addToCartUrl.indexOf('?') !== -1 ? '&' : '?') + 'tax_rate=' + encodeURIComponent(taxRate);
                }
                $.ajax({
                    url: addToCartUrl,
                    success: function (data) {
                        if (data.code == '200') {
                            if (isRestaurant) {
                                var existing = $(tbodySel + ' [id="' + rowIdPrefix + data.product.id + '"]');
                                if (existing.length || (data.carttotal && !data.carthtml)) {
                                    existing.find('input.input-number').val(data.product.quantity);
                                    existing.find('.subtotal').text(addCommas(data.product.subtotal));
                                    existing.attr('data-subtotal', data.product.subtotal);
                                } else if (data.carthtml) {
                                    var $row = $(data.carthtml).attr('id', rowIdPrefix + data.product.id).attr('data-subtotal', data.product.subtotal);
                                    $(tbodySel + ' .no-found').addClass('d-none');
                                    $(tbodySel).append($row);
                                    $('#pos-pay-btn-restaurant').prop('disabled', false);
                                }
                                sum = 0;
                                $(posCartItemSelector(tbodySel)).each(function() { sum += parseFloat($(this).attr('data-subtotal')) || 0; });
                                $('#pos-restaurant-total').text(addCommas(sum));
                            } else {
                                if ('carttotal' in data) {
                                    if (data.carthtml) {
                                        $('#tbody').append(data.carthtml);
                                        $('.no-found').addClass('d-none');
                                    }
                                    $.each(data.carttotal, function (key, value) {
                                        var $row = $('#product-id-' + value.id);
                                        if ($row.length) {
                                            $row.attr('data-subtotal', value.subtotal);
                                            $row.find('.subtotal').text(addCommas(value.subtotal));
                                            $row.find('input[name="quantity"]').val(value.quantity);
                                        }
                                        sum += value.subtotal;
                                    });
                                    $('#displaytotal').text(addCommas(sum));
                                    $('.totalamount').text(addCommas(sum));
                                    $('#pos-cart-count').text('(' + $('#tbody [data-product-id]').length + ')');
                                    $('.discount').val('');
                                } else if (data.carthtml) {
                                    $('#tbody').append(data.carthtml);
                                    $('.no-found').addClass('d-none');
                                    $('#pos-cart-count').text('(' + $('#tbody [data-product-id]').length + ')');
                                    $('.carttable #product-id-' + data.product.id + ' input[name="quantity"]').val(data.product.quantity);
                                    sum = 0;
                                    $('#tbody [data-product-id]').each(function() { sum += parseFloat($(this).attr('data-subtotal')) || 0; });
                                    $('#displaytotal').text(addCommas(sum));
                                    $('.totalamount').text(addCommas(sum));
                                }
                                $('#pos-pay-btn').prop('disabled', false);
                                $('.btn-empty button').addClass('btn-clear-cart');
                            }
                        }
                    },
                    error: function (data) {
                        data = data.responseJSON || {};
                        show_toastr('{{ __("Error") }}', data.error || '{{ __("pos.error") }}', 'error');
                    },
                    complete: function() {
                        if ($btn.length && !wasDisabled) {
                            setTimeout(function() {
                                $btn.prop('disabled', false).removeClass('pos-add-cart-loading');
                                var $icon = $btn.find('i');
                                if ($icon.length) $icon.removeClass('ti-loader ti-spin').addClass('ti-plus');
                            }, 800);
                        }
                    }
                });
            });

            $(document).on('click', '.pos-offline-remove', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                var $row = $(this).closest('[data-product-id]');
                var inRestaurant = $row.closest('#carthtml-restaurant').length > 0;
                var tbodySel = inRestaurant ? '#tbody-restaurant' : '#tbody';
                var totalSel = inRestaurant ? '#pos-restaurant-total' : '#displaytotal, .totalamount';
                $row.remove();
                if ($(posCartItemSelector(tbodySel)).length === 0) {
                    $(tbodySel).html(posEmptyCartHtml(tbodySel));
                    if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                    else { $('#pos-cart-count').text('(0)'); $('#pos-pay-btn').prop('disabled', true); $('.btn-empty button').removeClass('btn-clear-cart'); }
                } else if (!inRestaurant) $('#pos-cart-count').text('(' + $('#tbody [data-product-id]').length + ')');
                var total = 0;
                $(posCartItemSelector(tbodySel)).each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                $(totalSel).text(addCommas(total));
            });

            $(document).on('change keyup', '#carthtml input[name="quantity"], #carthtml-restaurant input[name="quantity"]', function (e) {
                e.preventDefault();
                var ele = $(this);
                var inRestaurant = ele.closest('#carthtml-restaurant').length > 0;
                var tbodySel = inRestaurant ? '#tbody-restaurant' : '#tbody';
                var rowIdPrefix = inRestaurant ? 'product-id-r-' : 'product-id-';
                var quantity = parseInt(ele.closest('span').find('input[name="quantity"]').val(), 10) || 0;
                if (!navigator.onLine) {
                    var $row = ele.closest('[data-product-id]');
                    if ($row.length && quantity > 0) {
                        var price = parseFloat($row.attr('data-price')) || 0;
                        var taxRate = parseFloat($row.attr('data-tax')) || 0;
                        var tax = price * taxRate / 100;
                        var subtotal = (price + tax) * quantity;
                        $row.attr('data-subtotal', subtotal);
                        $row.find('.subtotal').text(addCommas(subtotal));
                        var total = 0;
                        $(posCartItemSelector(tbodySel)).each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                        if (inRestaurant) $('#pos-restaurant-total').text(addCommas(total));
                        else { $('#displaytotal').text(addCommas(total)); $('.totalamount').text(addCommas(total)); $('#pos-cart-count').text('(' + $('#tbody [data-product-id]').length + ')'); }
                    } else if (quantity === 0) {
                        ele.closest('[data-product-id]').remove();
                        if ($(posCartItemSelector(tbodySel)).length === 0) {
                            $(tbodySel).html(posEmptyCartHtml(tbodySel));
                            if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                            else { $('#pos-cart-count').text('(0)'); $('#pos-pay-btn').prop('disabled', true); $('.btn-empty button').removeClass('btn-clear-cart'); }
                        } else if (!inRestaurant) $('#pos-cart-count').text('(' + $('#tbody [data-product-id]').length + ')');
                    }
                    return;
                }
                var sum = 0;
                var discount = $('.discount').val();
                if (quantity != null && quantity != 0) {
                    $.ajax({
                        url: ele.data('url'),
                        method: "patch",
                        data: {
                            id: ele.attr("data-id"),
                            quantity: quantity,
                            discount: discount,
                            session_key: session_key
                        },
                        success: function (data) {
                            if (data.code == '200') {
                                if (quantity == 0) {
                                    var $tr = ele.closest('[data-product-id]');
                                    $tr.remove();
                                    if ($(posCartItemSelector(tbodySel)).length === 0) {
                                        $(tbodySel).html(posEmptyCartHtml(tbodySel));
                                        if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                                        else { $('#pos-pay-btn').prop('disabled', true); $('.btn-empty button').removeClass('btn-clear-cart'); }
                                    }
                                }
                                $.each(data.product, function (key, value) {
                                    sum += value.subtotal;
                                    $('#' + rowIdPrefix + value.id + ' .subtotal').text(addCommas(value.subtotal));
                                });
                                if (inRestaurant) $('#pos-restaurant-total').text(addCommas(sum));
                                else { $('#displaytotal').text(addCommas(sum)); $('.totalamount').text(data.discount); }
                            }
                        },
                        error: function (data) {
                            data = data.responseJSON;
                            show_toastr('{{ __("Error") }}', data.error, 'error');
                        }
                    });
                }
            });

            $(document).on('click', '.remove-from-cart', function (e) {
                e.preventDefault();
                var ele = $(this);
                if (!navigator.onLine) {
                    var $row = ele.closest('[data-product-id]');
                    if ($row.length && confirm('{{ __("pos.are_you_sure") }}')) {
                        $row.remove();
                        if ($('#tbody [data-product-id]').length === 0) {
                            $('#tbody').html(posEmptyCartHtmlRetail);
                            $('#pos-cart-count').text('(0)');
                            $('#pos-pay-btn').prop('disabled', true);
                            $('.btn-empty button').removeClass('btn-clear-cart');
                        }
                        var total = 0;
                        $('#tbody [data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal') || $(this).attr('data-price') * parseInt($(this).find('input.input-number').val() || 1, 10)) || 0; });
                        $('#pos-cart-count').text('(' + $('#tbody [data-product-id]').length + ')');
                        $('#displaytotal').text(addCommas(total));
                        $('.totalamount').text(addCommas(total));
                    }
                    return;
                }
                var sum = 0;
                if (confirm('{{ __("pos.are_you_sure") }}')) {
                    ele.closest(".row").hide(250, function () {
                        ele.closest(".row").parent().parent().remove();
                    });
                    if (ele.closest(".row").is(":last-child")) {
                        $('#pos-pay-btn').prop('disabled', true);
                        $('.btn-empty button').removeClass('btn-clear-cart');
                    }
                    $.ajax({
                        url: ele.data('url'),
                        method: "DELETE",
                        data: {
                            id: ele.attr("data-id"),
                            // session_key: session_key
                        },
                        success: function (data) {
                            if (data.code == '200') {

                                $.each(data.product, function (key, value) {
                                    sum += value.subtotal;
                                    $('#product-id-' + value.id + ' .subtotal').text(addCommas(value.subtotal));
                                });

                                $('#displaytotal').text(addCommas(sum));

                                show_toastr('success', data.success, 'success')
                            }
                        },
                        error: function (data) {
                            data = data.responseJSON;
                            show_toastr('{{ __("Error") }}', data.error, 'error');
                        }
                    });
                }
            });

            $(document).on('click', '.btn-clear-cart', function (e) {
                e.preventDefault();
                if (confirm('{{ __("pos.remove_all_items") }}')) {
                    if (!navigator.onLine) {
                        posClearCartDom();
                        return;
                    }
                    $.ajax({
                        url: $(this).data('url'),
                        data: {
                            session_key: session_key
                        },
                        success: function (data) {
                            location.reload();
                        },
                        error: function (data) {
                            data = data.responseJSON;
                            show_toastr('{{ __("Error") }}', data.error, 'error');
                        }
                    });
                }
            });

            $(document).on('click', '.btn-done-payment', function (e) {
                e.preventDefault();
                var ele = $(this);
                $.ajax({
                    url: ele.data('url') || '{{ route("pos.data.store") }}',
                    method: 'GET',
                    data: (function() {
                        var d = {
                            vc_name: $('#vc_name_hidden').val(),
                            warehouse_name: $('#warehouse_name_hidden').val(),
                            discount: $('#discount_hidden').val(),
                            quotation_id: $('#quotation_id').val(),
                            payment_method: $('#payment_method').length ? $('#payment_method').val() : 'cash',
                            pos_shift_id: $('#pos_shift_id_hidden').length ? $('#pos_shift_id_hidden').val() || '' : ''
                        };
                        if (!posIsRetail && $('#pos_order_type_hidden').length) { d.pos_order_type = $('#pos_order_type_hidden').val() || ''; d.pos_table_id = $('#pos_table_id_hidden').length ? $('#pos_table_id_hidden').val() || '' : ''; }
                        return d;
                    })(),
                    beforeSend: function () { ele.prop('disabled', true); },
                    success: function (data) {
                        if (data.code == 200) {
                            $(document).trigger('pos-payment-completed', [data]);
                            show_toastr('success', data.success, 'success');
                        }
                    },
                    error: function (xhr) {
                        var data = xhr.responseJSON || {};
                        show_toastr('{{ __("Error") }}', data.error || data.success || '{{ __("pos.error") }}', 'error');
                        ele.prop('disabled', false);
                    }
                });
            });

            $(document).on('pos-payment-completed', function (e, data) {
                var sk = $('#empty_cart').val() || sessionKey;
                var emptyData = { session_key: sk };
                var tid = $('#pos_table_id_hidden').val();
                var sid = $('#pos_shift_id_hidden').val();
                if (sk === 'pos-restaurant' && tid && sid) {
                    emptyData.table_id = tid;
                    emptyData.shift_id = sid;
                }
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: emptyData,
                    success: function () {
                        $('#tbody').html(posEmptyCartHtmlRetail);
                        $('#pos-cart-count').text('(0)');
                        $('#tbody-restaurant').html(posEmptyCartHtmlRestaurant);
                        $('#displaytotal, .totalamount').text('0');
                        $('#pos-restaurant-total').text('0');
                        $('#pos-pay-btn').prop('disabled', true);
                        $('.btn-empty button').removeClass('btn-clear-cart');
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId && typeof loadRestaurantTables === 'function') loadRestaurantTables(branchId);
                        $('#pos_table_id_hidden').val('');
                        $('#pos_restaurant_table_id').val('');
                        $('#pos_restaurant_table_name').val('');
                        $('#pos-selected-table').text('—');
                        $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                        if (data && data.pos_id) {
                            var receiptUrl = '{{ url("printview/pos") }}?pos_id=' + data.pos_id;
                            $.get(receiptUrl, function (html) {
                                $('#commonModal .modal-title').html('{{ __("pos.pos_invoice") }}');
                                $('#commonModal .modal-dialog').removeClass('modal-xl').addClass('modal-sm');
                                $('#commonModal .body').html(html);
                                $('#commonModal').modal('show');
                            });
                        } else {
                            if ($('#commonModal').length) $('#commonModal').modal('hide');
                        }
                    }
                });
            });

            $(document).on('click', '.category-select', function (e) {
                if ($(this).closest('#pos-restaurant-panel').length) return;
                var cat = $(this).data('cat-id');
                $('#pos-retail-panel .category-select').parent().removeClass('cat-active');
                $('#pos-retail-panel .category-select').find('.card-title').removeClass('text-white').addClass('text-dark');
                $('#pos-retail-panel .category-select').find('.card-title').parent().removeClass('text-white').addClass('text-dark');
                $(this).find('.card-title').removeClass('text-dark').addClass('text-white');
                $(this).find('.card-title').parent().removeClass('text-dark').addClass('text-white');
                $(this).parent().addClass('cat-active');
                var warehouse_id = $('#warehouse').val();
                searchProducts(posSearchProductsUrl, '', cat, warehouse_id, 'name');
            });
            $(document).on('keyup', '.discount', function () {
                var discount = parseFloat($('.discount').val()) || 0;
                $( "#discount_hidden" ).val(discount);
                if (!navigator.onLine) {
                    var subtotal = 0;
                    $('#tbody [data-product-id]').each(function() {
                        var p = parseFloat($(this).attr('data-price')) || 0;
                        var t = parseFloat($(this).attr('data-tax')) || 0;
                        var q = parseInt($(this).find('input.input-number').val() || 1, 10);
                        subtotal += (p + p * t / 100) * q;
                    });
                    var total = Math.max(0, subtotal - discount);
                    $('.totalamount').text(addCommas(total));
                    return;
                }
                $.ajax({
                    url: "{{route('cartdiscount')}}",
                    method: 'POST',
                    data: {discount: discount},
                    success: function (data) {
                        $('.totalamount').text(data.total);
                    },
                    error: function (data) {
                        data = data.responseJSON || {};
                        show_toastr('{{ __("Error") }}', data.error || '{{ __("pos.error") }}', 'error');
                    }
                });
            })

        });




    </script>
    <script>
        var site_currency_symbol_position = '{{ \App\Models\Utility::getValByName('site_currency_symbol_position') }}';
        var site_currency_symbol = '{{ \App\Models\Utility::getValByName('site_currency_symbol') }}';
    </script>
    {{-- Open Shift from POS --}}
    <script>
        $(function () {
            var $btn = $('#pos-open-shift-btn');
            var $modal = $('#posOpenShiftModal');
            var $form = $('#pos-open-shift-form');
            if ($btn.length && $modal.length) {
                $btn.on('click', function () { $modal.modal('show'); });
                $form.on('submit', function (e) {
                    e.preventDefault();
                    var $submit = $('#pos-open-shift-submit').prop('disabled', true);
                    $.ajax({
                        url: '{{ route("pos-shift.open-from-pos") }}',
                        method: 'POST',
                        data: { branch_id: $form.find('[name=branch_id]').val(), opening_balance: $form.find('[name=opening_balance]').val() || 0, _token: $form.find('[name=_token]').val() },
                        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Accept': 'application/json' },
                        success: function () {
                            if (typeof show_toastr === 'function') show_toastr('success', '{{ __("Shift opened successfully.") }}', 'success');
                            $modal.modal('hide');
                            window.location.reload();
                        },
                        error: function (xhr) {
                            var msg = (xhr.responseJSON && xhr.responseJSON.error) || '{{ __("pos.error") }}';
                            if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', msg, 'error');
                            $submit.prop('disabled', false);
                        }
                    });
                });
            }
        });
        $(document).on('click', '.pos-open-drawer-btn', function () {
            var w = window.open('', '_blank', 'width=300,height=200');
            if (!w) { if (typeof show_toastr === 'function') show_toastr('{{ __("Error") }}', '{{ __("pos.open_drawer_allow_popup") }}', 'error'); else alert('{{ __("pos.open_drawer_allow_popup") }}'); return; }
            w.document.write('<html><head><title>{{ __("pos.open_drawer") }}</title><style>body{font-family:monospace;padding:20px;text-align:center;font-size:16px;}</style></head><body><p>{{ __("pos.open_drawer") }}</p><p><small>' + new Date().toLocaleString() + '</small></p></body></html>');
            w.document.close();
            w.focus();
            w.print();
            w.onafterprint = function() { w.close(); };
        });
    </script>
    {{-- PWA Install: button always visible when not standalone; click = native prompt or fallback --}}
    <script>
        (function () {
            var installBtn = document.getElementById('pos-install-app-btn');
            if (!installBtn) return;
            var deferredPrompt = null;
            var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true
                || document.referrer.indexOf('android-app://') === 0;

            function isIos() {
                return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            }
            function showToast(title, msg, type) {
                if (typeof show_toastr === 'function') show_toastr(title, msg, type || 'info');
                else alert(title + ': ' + msg);
            }

            if (isStandalone) {
                installBtn.classList.add('d-none');
                return;
            }

            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferredPrompt = e;
            });

            installBtn.addEventListener('click', function () {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(function (choice) {
                        if (choice.outcome === 'accepted') installBtn.classList.add('d-none');
                        deferredPrompt = null;
                    });
                    return;
                }
                if (isIos()) {
                    showToast('{{ __("pos.install_app") }}', '{{ __("pos.install_app_ios_instructions") }}', 'info');
                    return;
                }
                showToast('{{ __("pos.install_app") }}', '{{ __("pos.install_app_browser_hint") }}', 'info');
            });
        })();
    </script>
</body>
</html> 