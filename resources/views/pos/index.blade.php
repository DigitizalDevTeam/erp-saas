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
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}?v=5">
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
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}?v=4" id="main-style-link">

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
        .pos-category-scroll-wrap {
            position: relative;
            padding: 0 3.25rem;
        }



        .pos-category-scroll {
            overflow-x: auto;
            flex-wrap: nowrap;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scroll-snap-type: x proximity;
            padding: 2px 0;
        }
        .pos-category-scroll::-webkit-scrollbar {
            height: 4px;
        }
        .pos-category-scroll::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }
        .pos-category-btn {
            white-space: nowrap;
            flex-shrink: 0;
            scroll-snap-align: start;
        }
        .pos-category-scroll-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 2;
            /* min-width: 2.75rem;
            min-height: 2.75rem; */
            padding: 4px 5px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
            background-color: #000 !important;
            border-color: #000 !important;
            color: #fff !important;
        }
        .pos-category-scroll-arrow:hover,
        .pos-category-scroll-arrow:focus {
            background-color: #222 !important;
            border-color: #222 !important;
            color: #fff !important;
        }
        .pos-category-scroll-arrow i {
            font-size: 1.35rem;
            line-height: 1;
            color: #fff !important;
        }
        .pos-category-scroll-prev {
            left: 0;
        }
        .pos-category-scroll-next {
            right: 0;
        }
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
        .pos-print-header .header-sep { border-bottom: 1px dotted #333; margin: 10px 0; }
        .pos-print-header .logo-box { max-width: 120px; height: 70px; margin: 0 auto 8px; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff; }
        .pos-print-header .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .pos-print-header .doc-title { font-size: 1.25rem; font-weight: bold; text-align: center; margin: 8px 0; }
        .pos-print-header .doc-row { display: flex; justify-content: space-between; margin-bottom: 6px; }
        .pos-print-header .doc-row .left, .pos-print-header .doc-row .right { flex: 1; }
        .pos-print-header .doc-row .right { text-align: right; }
        .pos-print-header .customer-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .pos-print-header .customer-row .cust-type { font-weight: bold; }
        .pos-print-header .customer-row .cust-name { font-weight: bold; color: #333; }
        .pos-print-header .customer-details { font-size: 0.9rem; color: #555; margin-top: 4px; }
        .product-border { border-bottom: 1px solid #ddd; padding: 2px 0; }
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
            #commonModal #printarea { position: static !important; width: 100%; padding: 0.5in; padding-bottom: 0.6in !important; font-size: 12pt; }
            #commonModal #printarea .pos-module-tbl { width: 100%; }
            #commonModal #printarea .product-border { border-bottom: 1px solid #ddd !important; padding: 2px 0; }
            #commonModal #printarea .view-qrcode-receipt, #commonModal #printarea .view-qrcode-receipt * { visibility: visible !important; }
            #commonModal #printarea .view-qrcode-receipt img { display: block !important; visibility: visible !important; }
        }
      /* ================= POS Top Bar Responsive (FINAL) ================= */

.pos-top-bar {
    padding: 0.6rem 1rem;
    border-radius: 0.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

@media (max-width: 767.98px) {

    /* Main top bar - stack */
    .pos-top-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
        padding: 10px 12px !important;
    }

    /* LEFT group: brand + OB + closing balance */
    .pos-top-bar > div:first-child {
        display: flex !important;
        flex-wrap: wrap !important;
        align-items: center !important;
        gap: 6px !important;
        width: 100%;
    }

    .pos-top-bar .pos-brand {
        font-size: 1rem !important;
        width: 100%;
        margin-bottom: 2px;
    }

    /* OB / Closing Balance chips */
    .pos-top-bar > div:first-child > span,
    .pos-top-bar > div:first-child > a {
        font-size: 0.78rem !important;
        padding: 4px 8px !important;
        background: rgba(255,255,255,0.18) !important;
        border-radius: 4px;
        white-space: nowrap;
        border: none;
        color: #fff !important;
    }

    /* RIGHT group: time + badges + icons */
    .pos-top-bar > div:last-child {
        display: flex !important;
        flex-wrap: wrap !important;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 6px !important;
        width: 100%;
        border-top: 1px solid rgba(255,255,255,0.25);
        padding-top: 8px;
    }

    /* Time */
    .pos-top-bar #pos-current-time {
        font-size: 0.78rem !important;
        min-width: auto !important;
    }

    /* Badges */
    .pos-top-bar .badge {
        font-size: 0.68rem !important;
        padding: 3px 6px !important;
    }

    /* Buttons — compact */
    .pos-top-bar .btn-sm {
        padding: 5px 7px !important;
        font-size: 0.72rem !important;
        line-height: 1.2;
    }

    /* Icon-only on mobile (hide text) */
    .pos-top-bar .btn-sm .d-none.d-sm-inline {
        display: none !important;
    }

    /* Reports dropdown */
    .pos-top-bar #pos-reports-dropdown {
        padding: 5px 7px !important;
    }

    /* Make icon buttons uniform circle-ish */
    .pos-top-bar > div:last-child > a.btn-sm {
        padding: 5px 7px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* Branch / Warehouse / Order Type stacking (retail + restaurant) */
    .pos-restaurant-top .row.g-1,
    #pos-restaurant-panel .row.g-1,
    #pos-retail-panel .row.g-1 {
        flex-direction: column !important;
    }
    .pos-restaurant-top .col-6,
    #pos-restaurant-panel .col-6,
    #pos-retail-panel .col-6 {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        margin-bottom: 8px;
    }

    /* Full-width selects */
    .pos-restaurant-top select,
    #pos-restaurant-panel select,
    #pos-retail-panel select {
        width: 100% !important;
        font-size: 14px !important;
    }

    /* Page padding */
    .pos-page.container-fluid.px-2 {
        padding-left: 6px !important;
        padding-right: 6px !important;
    }

    /* Category arrows */
    .pos-category-scroll-wrap {
        padding: 0 2rem !important;
    }
    .pos-category-scroll-arrow {
        min-width: 2rem !important;
        min-height: 2rem !important;
        padding: 2px 4px !important;
    }

    /* Cart/Products stacking */
    #pos-retail-panel .col-lg-9,
    #pos-retail-panel .col-lg-3 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        width: 100% !important;
    }
    #pos-retail-panel .border-right {
        border-right: none !important;
    }

    /* Search bar */
    .pos-search-bar .col-md-4 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        margin-bottom: 6px;
    }
}

/* Very small phones */
@media (max-width: 375.98px) {
    .pos-top-bar .pos-brand {
        font-size: 0.9rem !important;
    }
    .pos-top-bar > div:first-child > span,
    .pos-top-bar > div:first-child > a {
        font-size: 0.7rem !important;
        padding: 3px 6px !important;
    }
    .pos-top-bar .btn-sm {
        padding: 4px 6px !important;
    }
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
                <div class="d-flex align-items-center flex-wrap gap-2 pos-top-bar__left">
                    <span class="text-white pos-brand">{{ __('POS Retail') }}</span
                        @if($userOpenShift)
                            <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('OB') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->opening_balance ?? 0) }}</span>
                            @if(isset($userOpenShift->closing_balance) && $userOpenShift->closing_balance !== null && $userOpenShift->closing_balance !== '')
                                <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.closing_balance') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->closing_balance) }}</span>
                            @else
                                <a href="#" class="btn btn-sm btn-light pos-add-closing-balance-btn" data-url="{{ route('pos.add_closing_balance') }}" data-title="{{ __('pos.add_closing_balance') }}" data-bs-toggle="tooltip" title="{{ __('pos.add_closing_balance') }}">{{ __('pos.add_closing_balance') }}</a>
                            @endif
                        @else
                            <button type="button" class="btn btn-sm btn-light ms-2" id="pos-open-shift-btn" title="{{ __('pos.open_shift') }}">
                                <i class="ti ti-login"></i> {{ __('pos.open_shift') }}
                            </button>
                        @endif
                    </div>
                    <div class="d-flex align-items-center gap-2 pos-top-bar__right">
                    <span id="pos-current-time" class="text-white opacity-90 align-middle" style="min-width: 5rem;">--:--:--</span>
                        <span id="pos-status-badge" class="badge bg-success align-middle" style="font-size: 0.75rem;">{{ __('pos.online') }}</span>
                        <span id="pos-pending-badge" class="badge bg-warning text-dark d-none" style="font-size: 0.8rem; cursor: pointer;" data-msg="{{ __('pos.pending_sync_label') }}" title="{{ __('pos.sync_now') }}"></span>
                        <button type="button" id="pos-install-app-btn" class="btn btn-sm btn-light" title="{{ __('pos.install_app') }}">
                            <i class="ti ti-download" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('pos.install_app') }}</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-light pos-open-drawer-btn" title="{{ __('pos.open_drawer') }}">
                            <i class="ti ti-cash" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('pos.open_drawer') }}</span>
                        </button>
                        @if (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager'))
                        <div class="dropdown d-inline-block">
                            <button type="button" class="btn btn-sm btn-light dropdown-toggle" id="pos-reports-dropdown" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('Reports & more') }}">
                                <i class="ti ti-report" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('Reports') }}</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="pos-reports-dropdown">
                                <li><h6 class="dropdown-header">{{ __('Reports') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.report') }}" target="_blank"><i class="ti ti-summary me-2"></i>{{ __('pos.pos_summary') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.shift') }}" target="_blank"><i class="ti ti-clock-hour-4 me-2"></i>{{ __('pos.shift_report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.balances') }}" target="_blank"><i class="ti ti-wallet me-2"></i>{{ __('pos.pos_balances') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.daily.pos') }}" target="_blank"><i class="ti ti-calendar me-2"></i>{{ __('POS Daily/Monthly Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.branch') }}" target="_blank"><i class="ti ti-building-store me-2"></i>{{ __('POS by Branch') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.vs.purchase') }}" target="_blank"><i class="ti ti-chart-bar me-2"></i>{{ __('Pos VS Purchase Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.day-summary', ['date' => date('Y-m-d')]) }}" target="_blank"><i class="ti ti-calendar-event me-2"></i>{{ __('Day Summary') }}</a></li>
                                <li><a class="dropdown-item pos-eod-link" href="#" data-date="{{ date('Y-m-d') }}" data-bs-toggle="modal" data-bs-target="#posEodWarningModal"><i class="ti ti-calendar-off me-2"></i>{{ __('pos.day_closed') }} / {{ __('EOD Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.restaurant.sales-by-category') }}" target="_blank"><i class="ti ti-report me-2"></i>{{ __('Sales by Categories') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.low-stock') }}" target="_blank"><i class="ti ti-box me-2"></i>{{ __('Minimum Stock Alert') }}</a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#payReceiptsModal"><i class="ti ti-receipt me-2"></i>{{ __('pos.pay_receipts') }}</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">{{ __('Other') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.barcode') }}" target="_blank"><i class="ti ti-barcode me-2"></i>{{ __('pos.pos_product_barcode') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.setting') }}" target="_blank"><i class="ti ti-settings me-2"></i>{{ __('pos.barcode_setting') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.print.setting') }}" target="_blank"><i class="ti ti-printer me-2"></i>{{ __('Print') }} {{ __('Setting') }}</a></li>
                            </ul>
                        </div>
                        @endif
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
                                    <option value="{{ $c->name }}" data-email="{{ e($c->email ?? '') }}" data-contact="{{ e($c->contact ?? '') }}" data-type="{{ (($c->name === 'Walk-in-customer') || !empty($c->is_walk_in)) ? 'walk-in' : 'customer' }}" {{ (($customer ?? '') === $c->name || (($customer ?? '') === '' && $c->name === 'Walk-in-customer')) ? 'selected' : '' }}>{{ $c->name }}</option>
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
                                    <span class="badge bg-light text-dark border" id="pos-customer-badge">{{ ($selCustomer && ($selCustomer->name === 'Walk-in-customer' || !empty($selCustomer->is_walk_in))) ? __('Walk-in') : __('Customer') }}</span>
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
                                                data-price-type="{{ $details['price_type'] ?? 'exclusive' }}"
                                                data-quantity="{{ $details['quantity'] }}" data-tax="{{ $details['tax'] ?? 0 }}"
                                                data-subtotal="{{ $details['subtotal'] }}" data-options-amount="{{ $details['options_amount'] ?? 0 }}"
                                                data-discount="{{ $details['discount'] ?? 0 }}"
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

    {{-- EOD Report: warn that all open shifts will be closed --}}
    <div class="modal fade" id="posEodWarningModal" tabindex="-1" aria-labelledby="posEodWarningModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posEodWarningModalLabel">{{ __('pos.day_closed') }} / {{ __('EOD Report') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0 text-warning"><i class="ti ti-alert-triangle me-2"></i>{{ __('pos.eod_close_all_shifts_warning') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <form id="pos-eod-confirm-form" action="{{ route('pos.close-all-shifts-and-eod') }}" method="post" class="d-inline">
                        @csrf
                        <input type="hidden" name="date" id="pos-eod-date-input" value="{{ date('Y-m-d') }}">
                        <button type="submit" class="btn btn-primary">{{ __('OK') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Pay Receipts: search by amount, customer, POS number; show results and print --}}
    <div class="modal fade" id="payReceiptsModal" tabindex="-1" aria-labelledby="payReceiptsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="payReceiptsModalLabel">{{ __('pos.pay_receipts') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-0">{{ __('pos.pos_number') }}</label>
                            <input type="text" class="form-control form-control-sm" id="pay-receipts-pos-number" placeholder="#POS00071">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">{{ __('Customer Name') }}</label>
                            <input type="text" class="form-control form-control-sm" id="pay-receipts-customer" placeholder="">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-0">{{ __('Amount') }}</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" id="pay-receipts-amount" placeholder="">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" class="btn btn-primary btn-sm w-100" id="pay-receipts-search">{{ __('Search') }}</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('pos.pos_number') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('Total') }}</th>
                                    <th width="80">{{ __('Print') }}</th>
                                </tr>
                            </thead>
                            <tbody id="pay-receipts-tbody">
                                <tr><td colspan="5" class="text-center text-muted py-3">{{ __('pos.search_receipts_hint') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
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
                            <label class="form-label">{{ __('Operational shift') }}</label>
                            <select name="operational_shift" class="form-select" required>
                                @foreach(\App\Support\AttendanceShiftCatalog::operationalShiftOptions() as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.opening_balance') }}</label>
                            <input type="text" name="opening_balance" class="form-control" inputmode="decimal" autocomplete="off" value="0" placeholder="0">
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
                        <input type="hidden" name="pos_shift_id" id="pos-pay-balance-shift-id" value="">
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
    <script src="{{ asset('js/shift-close-count.js') }}"></script>
    <script>
        (function($) {
            var searchUrl = '{{ route("pos.search.pay.receipts") }}';
            var printBaseUrl = '{{ url("printview/pos") }}';
            var posPrintSource = 'retail';
            function runPayReceiptsSearch() {
                var btn = $('#pay-receipts-search').prop('disabled', true);
                var tbody = $('#pay-receipts-tbody');
                var params = {
                    pos_number: $('#pay-receipts-pos-number').val(),
                    customer_name: $('#pay-receipts-customer').val(),
                    amount: $('#pay-receipts-amount').val()
                };
                var shiftId = $('#pos_shift_id_hidden').val();
                if (shiftId) {
                    params.shift_id = shiftId;
                }
                $.get(searchUrl, params).done(function(res) {
                    if (res.success && res.data && res.data.length) {
                        var rows = res.data.map(function(r) {
                            return '<tr><td>' + (r.pos_number || '') + '</td><td>' + (r.date || '') + '</td><td>' + (r.customer_name || '') + '</td><td>' + (r.total || '') + '</td><td><button class="btn btn-sm btn-outline-primary pay-receipt-print" data-id="'+r.id+'"><i class="ti ti-printer"></i></button></td></tr>';
                        });
                        tbody.html(rows.join(''));
                    } else {
                        tbody.html('<tr><td colspan="5" class="text-center text-muted py-3">{{ __("pos.no_data_found_short") }}</td></tr>');
                    }
                }).fail(function() {
                    tbody.html('<tr><td colspan="5" class="text-center text-danger py-3">{{ __("Error") }}</td></tr>');
                }).always(function() { btn.prop('disabled', false); });
            }
            $(document).on('click', '#pay-receipts-search', runPayReceiptsSearch);
            $('#payReceiptsModal').on('shown.bs.modal', runPayReceiptsSearch);
        })(jQuery);
    </script>
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
@php
    $logoPath = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::getValByName('company_logo_dark');
    $pos_logo = \App\Models\Utility::getValByName('pos_logo');
    $headerLogo = (isset($pos_logo) && $pos_logo) ? \App\Models\Utility::get_file('pos_logo/') . $pos_logo : $logoPath . '/' . ($company_logo ?? 'logo-dark.png');
@endphp

    <script>
        var POS_PENDING_KEY = 'pos_pending_sales';
        var POS_CACHE_PRODUCTS = 'pos_cache_products';
        var POS_CACHE_CATEGORIES = 'pos_cache_categories';
        var POS_PRODUCT_HTML_VER = 'pv8';
        var posSyncUrl = '{{ route("pos.sync") }}';
        var posCurrencySymbol = @json(Auth::user()->currencySymbol() ?? '');
        var posPrintSettings = @json(\App\Models\Utility::settings());
        var posLogo = "{{ $headerLogo }}";
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
            return POS_PRODUCT_HTML_VER + '|' + (warehouse || '0') + '|' + (cat || '0') + '|' + (search || '') + '|' + (type || 'name');
        }
        (function posMigrateProductLocalCache() {
            try {
                var verKey = 'pos_product_html_ver';
                if (localStorage.getItem(verKey) === POS_PRODUCT_HTML_VER) {
                    return;
                }
                localStorage.removeItem(POS_CACHE_PRODUCTS);
                localStorage.setItem(verKey, POS_PRODUCT_HTML_VER);
            } catch (e) {}
        })();
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
                var priceType = ($(this).attr('data-price-type') || 'exclusive').toLowerCase();
                var optionsAmount = Math.max(0, parseFloat($(this).attr('data-options-amount')) || 0);
                var subtotal;
                if (priceType === 'inclusive' && tax > 0) {
                    subtotal = (price * qty) + optionsAmount;
                } else {
                    subtotal = ((price + price * tax / 100) * qty) + optionsAmount;
                }
                var discount = parseFloat($(this).attr('data-discount')) || 0;
                var netPrice = subtotal - discount;
                cart[id] = {
                    id: parseInt(id, 10),
                    name: $(this).attr('data-name') || '',
                    price: price,
                    quantity: qty,
                    tax: tax,
                    subtotal: subtotal,
                    options_amount: optionsAmount,
                    discount: discount,
                    netPrice: netPrice,
                    price_type: priceType,
                    measurement_json: decodeURIComponent($(this).attr('data-measurement-json') || '')
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
                            var priceType = ($(this).attr('data-price-type') || 'exclusive').toLowerCase();
                            var lineSubtotal, lineTax;
                            if (priceType === 'inclusive' && tax > 0) {
                                lineSubtotal = price * qty;
                                lineTax = lineSubtotal - (lineSubtotal / (1 + tax / 100));
                            } else {
                                lineTax = price * qty * tax / 100;
                                lineSubtotal = (price + price * tax / 100) * qty;
                            }
                            subTotalInclTax += lineSubtotal;
                            taxTotal += lineTax;
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
                        var priceType = ($(this).attr('data-price-type') || 'exclusive').toLowerCase();
                        var optionsAmount = Math.max(0, parseFloat($(this).attr('data-options-amount')) || 0);
                        var subtotal;
                        if (priceType === 'inclusive' && tax > 0) {
                            subtotal = (price * qty) + optionsAmount;
                        } else {
                            subtotal = ((price + price * tax / 100) * qty) + optionsAmount;
                        }
                        receiptData.subTotal += subtotal;
                        receiptData.taxTotal += priceType === 'inclusive' && tax > 0
                            ? (subtotal - (subtotal / (1 + tax / 100)))
                            : ((price * qty * tax / 100) + (optionsAmount - (optionsAmount / (1 + tax / 100))));
                        receiptData.items.push({ name: name, qty: qty, price: price, tax: tax, subtotal: subtotal, price_type: priceType });
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
                    var totalTax = 0;
                    var discountPerItem = receiptData.discount > 0 && receiptData.items.length > 0 ? receiptData.discount / receiptData.items.length : 0;
                    receiptData.items.forEach(function (it) {
                        var taxAmt = (it.price_type === 'inclusive' && it.tax > 0)
                            ? (it.subtotal - (it.subtotal / (1 + it.tax / 100)))
                            : (it.price * it.qty * it.tax / 100);
                        totalTax += taxAmt;
                        itemRows += '<div class="mt-2"><div class="p-0"><b>' + esc(it.name) + '</b></div>' +
                            '<div class="d-flex product-border"><div>{{ __("pos.quantity_colon") }}</div><div class="text-end ms-auto">' + it.qty + '</div></div>' +
                            '<div class="d-flex product-border"><div>{{ __("pos.price_colon") }}</div><div class="text-end ms-auto">' + fmt(it.price) + '</div></div>' +
                            (discountPerItem > 0 ? '<div class="d-flex product-border"><div>{{ __("pos.discount_colon") }}</div><div class="text-end ms-auto">' + fmt(discountPerItem) + '</div></div>' : '') +
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
                    var printareaHtml = '<div class="pt-0 pb-3 modal-body pos-module position-relative" id="printarea">' +
                        '<div class="receipt-copy-wrapper receipt-copy">' +
                        '<div class="pos-print-header">' +
                        '<div class="text-center">' +
                        '<div class="logo-box">' +
                        '<img src="' + (posLogo ? posLogo : '{{ asset(Storage::url("uploads/logo/")) }}/{{ \App\Models\Utility::getValByName("company_logo_dark") ?? "logo-dark.png" }}') + '" alt="Logo" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';">' +
                        '<span style="display:none; color:#22c55e; font-weight:bold;">LOGO</span>' +
                        '</div>' +
                        '<div>' + companyName + '</div>' +
                        '<div>' + esc(s.company_address || s.mail_from_address) + '</div>' +
                        '<div>' + esc(s.company_city) + (s.company_city && (s.company_state || s.company_zipcode) ? ', ' : '') + esc(s.company_state) + (s.company_state && s.company_zipcode ? ', ' : '') + esc(s.company_zipcode) + '</div>' +
                        (s.vat_gst_number_switch == 'on' && s.vat_number ? '<div>' + (s.tax_type || 'VAT') + ' NR: ' + s.vat_number + '</div>' : '') +
                        '<div>Phone: ' + esc(s.company_telephone) + '</div>' +
                        '<div>E-mail: ' + esc(s.mail_from_address) + '</div>' +
                        '</div>' +
                        '<div class="mt-1" style="font-size: 1.3rem;text-align: center;"><b>{{ __("Retail") }} - {{ __("Cash Sale") }}</b></div>' +
                        '<div class="header-sep"></div>' +
                        '<div class="doc-row">' +
                        '<div class="left">' +
                        '<div>Date: ' + esc(receiptData.date) + '</div>' +
                        '<div class="mt-1">{{ __("pos.branch_name") }}:<br>-</div>' +
                        '</div>' +
                        '<div class="right">' +
                        '<div>{{ __("Original") }}</div>' +
                        '<div class="mt-1">DOC NR<br><strong>OFFLINE</strong></div>' +
                        '</div>' +
                        '</div>' +
                        '<div class="header-sep"></div>' +
                        '<div class="customer-row">' +
                        '<div class="cust-name">' + esc(receiptData.customer) + '</div>' +
                        '</div>' +
                        '<div class="header-sep"></div>' +
                        '</div>' +
                        '<div class="mt-2 no-print"><span class="text-warning small"><span class="ti ti-wifi-off me-1"></span>{{ __("pos.offline_banner") }}</span></div>' +
                        '<div class="text-black text-left fs-5 mt-0 mb-0">{{ __("Items") }}</div>' +
                        itemRows +
                        '<div class="d-flex product-border mb-2 mt-4"><div><b>{{ __("pos.sub_total_colon") }}</b></div><div class="text-end ms-auto">' + fmt(receiptData.subTotal) + '</div></div>' +
                        (totalTax > 0 ? '<div class="d-flex product-border mb-2"><div><b>{{ __("pos.tax_colon") }}</b></div><div class="text-end ms-auto">' + fmt(totalTax) + '</div></div>' : '') +
                        '<div class="d-flex product-border mb-2"><div><b>{{ __("pos.total_colon") }}</b></div><div class="text-end ms-auto">' + fmt(receiptData.total) + '</div></div>' +
                        paySection +
                        '<div class="text-center mt-3 small text-muted">' +
                        '<div>{{ __("pos.document_processed_by_computer") }}</div>' +
                        '<div class="mt-1">{{ __("pos.thank_you") }}</div>' +
                        '</div>' +
                        '</div>' +
                        '</div>' +
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
            navigator.serviceWorker.register('{{ asset("sw-pos.js") }}?v=3').catch(function () {});
        }
    </script>

    <script>

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        function parsePosWarehousesResponse(data) {
            var list = (data && data.warehouses) ? data.warehouses : (data || {});
            if (list.warehouses || list.default_warehouse_id) {
                list = list.warehouses || {};
            }
            var ids = Object.keys(list || {});
            var def = data && data.default_warehouse_id != null ? String(data.default_warehouse_id) : '';
            var preferred = (def && ids.indexOf(def) !== -1) ? def : (ids[0] || null);
            return { list: list || {}, ids: ids, preferred: preferred };
        }

        $( document ).ready(function() {

            var abc = $( "#warehouse_name_hidden" ).val($('.warehouse_select').val());
            $( "#vc_name_hidden" ).val($('.customer_select').val());
            $( "#warehouse_name_hidden" ).val($('.warehouse_select').val());
            $( "#discount_hidden").val($('.discount').val());
            $( "#quotation_id").val($('.quotation').val());
            $(function () {
                fillRetailCategorySelect();
                loadRestaurantCategories(true);
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

            var posRestaurantCategoryTree = { categories: [], children_by_parent: {} };
            var posRestaurantActiveParentId = '0';
            var posRestaurantActiveSubId = '0';

            function getRestaurantCategoryChildren(parentId) {
                parentId = String(parentId || '0');
                var map = posRestaurantCategoryTree.children_by_parent || {};
                return map[parentId] || map[parseInt(parentId, 10)] || [];
            }

            function restaurantCategoryHasChildren(parentId) {
                if (!parentId || parentId === '0') {
                    return false;
                }
                var cat = (posRestaurantCategoryTree.categories || []).find(function (c) {
                    return String(c.id) === String(parentId);
                });
                if (cat && typeof cat.has_children !== 'undefined') {
                    return !!cat.has_children;
                }
                return getRestaurantCategoryChildren(parentId).length > 0;
            }

            function escapeRestaurantCategoryLabel(text) {
                return String(text || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            }

            function buildRestaurantCategoryButton(id, name, level) {
                var active = (level === 'parent' && String(id) === String(posRestaurantActiveParentId))
                    || (level === 'sub' && String(id) === String(posRestaurantActiveSubId));
                return '<button type="button" class="btn btn-sm pos-category-btn ' + (active ? 'btn-primary active' : 'btn-outline-primary') + '" data-level="' + level + '" data-id="' + id + '">' + escapeRestaurantCategoryLabel(name) + '</button>';
            }

            function scrollPosCategoryBar($scrollEl, direction) {
                if (!$scrollEl.length) {
                    return;
                }
                var el = $scrollEl[0];
                if (el && el.scrollBy) {
                    el.scrollBy({ left: direction * 200, behavior: 'smooth' });
                }
            }

            function getRestaurantCategoryId() {
                var $subWrap = $('#pos-restaurant-subcategory-scroll-wrap');
                if ($subWrap.length && !$subWrap.hasClass('d-none') && posRestaurantActiveSubId !== '0') {
                    return posRestaurantActiveSubId;
                }
                return posRestaurantActiveParentId || '0';
            }

            function getRestaurantWarehouseId() {
                var wh = $('#pos-restaurant-warehouse').val()
                    || $('#pos_restaurant_warehouse_hidden').val()
                    || $('#warehouse').val()
                    || $('#warehouse_name_hidden').val()
                    || '0';
                return wh ? String(wh) : '0';
            }

            function reloadRestaurantProductsByCategory() {
                var wh = getRestaurantWarehouseId();
                if (wh && wh !== '0') {
                    searchProducts(posSearchProductsUrl, '', getRestaurantCategoryId(), wh, 'name', 'product-listing-restaurant');
                }
            }

            function renderRestaurantSubcategoryBar(parentId) {
                var $wrap = $('#pos-restaurant-subcategory-scroll-wrap');
                var $bar = $('#pos-restaurant-subcategory-bar');
                if (!$wrap.length || !$bar.length) {
                    return;
                }
                parentId = String(parentId || '0');
                if (parentId === '0' || !restaurantCategoryHasChildren(parentId)) {
                    $wrap.addClass('d-none');
                    posRestaurantActiveSubId = '0';
                    $bar.empty();
                    return;
                }
                var html = buildRestaurantCategoryButton('0', '{{ __("All subcategories") }}', 'sub');
                getRestaurantCategoryChildren(parentId).forEach(function (item) {
                    html += buildRestaurantCategoryButton(item.id, item.name, 'sub');
                });
                $bar.html(html);
                $wrap.removeClass('d-none');
            }

            function renderRestaurantCategoryBar() {
                var $bar = $('#pos-restaurant-category-bar');
                if (!$bar.length) {
                    return;
                }
                var html = buildRestaurantCategoryButton('0', '{{ __("All Categories") }}', 'parent');
                (posRestaurantCategoryTree.categories || []).forEach(function (item) {
                    if (!item.parent_id) {
                        html += buildRestaurantCategoryButton(item.id, item.name, 'parent');
                    }
                });
                $bar.html(html);
                renderRestaurantSubcategoryBar(posRestaurantActiveParentId);
            }

            function resetRestaurantCategories(skipProductReload) {
                posRestaurantActiveParentId = '0';
                posRestaurantActiveSubId = '0';
                renderRestaurantCategoryBar();
                if (!skipProductReload) {
                    reloadRestaurantProductsByCategory();
                }
            }

            function loadRestaurantCategories(skipProductReload) {
                if (!$('#pos-restaurant-category-bar').length) {
                    return;
                }
                $.get(posCategoriesJsonUrl, function (data) {
                    if (data && data.categories) {
                        posRestaurantCategoryTree = data;
                    } else if (Array.isArray(data)) {
                        posRestaurantCategoryTree = { categories: data, children_by_parent: {} };
                    }
                    renderRestaurantCategoryBar();
                    if (!skipProductReload) {
                        reloadRestaurantProductsByCategory();
                    }
                });
            }

            $('#pos-retail-category').on('change', function () {
                var cat = $(this).val() || '0';
                var ware_id = $( "#warehouse" ).val();
                searchProducts(posSearchProductsUrl, '', cat, ware_id, 'name');
            });

            $(document).on('click', '#pos-restaurant-panel .pos-category-scroll-prev', function () {
                scrollPosCategoryBar($(this).closest('.pos-category-scroll-wrap').find('.pos-category-scroll'), -1);
            });
            $(document).on('click', '#pos-restaurant-panel .pos-category-scroll-next', function () {
                scrollPosCategoryBar($(this).closest('.pos-category-scroll-wrap').find('.pos-category-scroll'), 1);
            });
            $(document).on('click', '#pos-restaurant-panel .pos-category-btn[data-level="parent"]', function () {
                posRestaurantActiveParentId = String($(this).data('id') || '0');
                posRestaurantActiveSubId = '0';
                renderRestaurantCategoryBar();
                reloadRestaurantProductsByCategory();
            });
            $(document).on('click', '#pos-restaurant-panel .pos-category-btn[data-level="sub"]', function () {
                posRestaurantActiveSubId = String($(this).data('id') || '0');
                renderRestaurantSubcategoryBar(posRestaurantActiveParentId);
                reloadRestaurantProductsByCategory();
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
                        if (typeof loadRestaurantTables === 'function') {
                            loadRestaurantTables(branchId);
                        }
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
                                var parsed = parsePosWarehousesResponse(data);
                                var $whSelect = $('#pos-restaurant-warehouse');
                                if ($whSelect.length) {
                                    $whSelect.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                                    $.each(parsed.list, function (id, name) {
                                        $whSelect.append($('<option></option>').val(id).text(name));
                                    });
                                }
                                var ids = parsed.ids;
                                if (ids.length) {
                                    var wh = parsed.preferred;
                                    $('#warehouse').val(wh);
                                    $('#warehouse_name_hidden').val(wh);
                                    if ($whSelect.length) $whSelect.val(wh);
                                    $('#pos_restaurant_warehouse_hidden').val(wh);
                                    $('#vc_name_hidden').val('');
                                    resetRestaurantCategories(true);
                                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                                }
                                $('#pos-restaurant-warehouse-hint').text(ids.length ? '' : '{{ __("No warehouses for this branch") }}').toggle(ids.length === 0);
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

            $('#pos-restaurant-warehouse').on('change', function () {
                var wh = $(this).val();
                $('#pos_restaurant_warehouse_hidden').val(wh || '');
                if (wh) {
                    resetRestaurantCategories(true);
                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                }
            });

            if ($('#pos-restaurant-panel').length && !$('#pos-restaurant-panel').hasClass('d-none')) {
                var initRestBranch = $('#pos-restaurant-branch').val();
                if (initRestBranch) {
                    loadBranchShifts(initRestBranch);
                    $('#pos-restaurant-placeholder').addClass('d-none');
                    $('#pos-restaurant-order').removeClass('d-none');
                    $('#pos-restaurant-order-right').removeClass('d-none');
                    $.get('{{ route("pos.warehouses.by.branch") }}', { branch_id: initRestBranch }, function (data) {
                        var parsed = parsePosWarehousesResponse(data);
                        var $whSelect = $('#pos-restaurant-warehouse');
                        $whSelect.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(parsed.list, function (id, name) {
                            $whSelect.append($('<option></option>').val(id).text(name));
                        });
                        var ids = parsed.ids;
                        if (ids.length) {
                            var wh = parsed.preferred;
                            $whSelect.val(wh);
                            $('#pos_restaurant_warehouse_hidden').val(wh);
                            $('#warehouse').val(wh);
                            $('#warehouse_name_hidden').val(wh);
                            resetRestaurantCategories(true);
                            searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                        }
                        $('#pos-restaurant-warehouse-hint').text(ids.length ? '' : '{{ __("No warehouses for this branch") }}').toggle(ids.length === 0);
                    });
                }
            }

            function posEscapeHtml(str) {
                return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
            function renderRestaurantTableButton(t) {
                var statusClass = t.status === 'occupied' ? 'btn-warning' : (t.status === 'reserved' ? 'btn-secondary' : 'btn-outline-primary');
                var name = posEscapeHtml(t.name || ('Table ' + t.id));
                var customer = (t.customer_name && String(t.customer_name).trim()) ? posEscapeHtml(t.customer_name) : '—';
                var guestsVal = (t.current_guests != null && t.current_guests !== '') ? String(t.current_guests) : '—';
                var amount = (t.cart_total_formatted && String(t.cart_total_formatted).trim()) ? posEscapeHtml(t.cart_total_formatted) : '—';
                return '<button type="button" class="btn ' + statusClass + ' btn-sm pos-table-btn pos-table-card" data-id="' + t.id + '" data-name="' + posEscapeHtml(t.name || '') + '" data-status="' + (t.status || '') + '">' +
                    '<span class="pos-table-card__name">' + name + '</span>' +
                    '<span class="pos-table-card__row"><span class="pos-table-card__label">{{ __("pos.guest_name") }}</span><span class="pos-table-card__value">' + customer + '</span></span>' +
                    '<span class="pos-table-card__row"><span class="pos-table-card__label">{{ __("pos.table_amount") }}</span><span class="pos-table-card__value">' + amount + '</span></span>' +
                    '<span class="pos-table-card__row"><span class="pos-table-card__label">{{ __("pos.table_guests") }}</span><span class="pos-table-card__value">' + guestsVal + '</span></span>' +
                    '</button>';
            }
            function loadRestaurantTables(branchId) {
                var shiftId = $('#pos_shift_id_hidden').val() || '';
                $.get(posRestaurantLayoutUrl, { branch_id: branchId, shift_id: shiftId }, function (res) {
                    var html = '';
                    (res.areas || []).forEach(function (area) {
                        html += '<div class="mb-2"><small class="text-muted d-block mb-1">' + area.name + '</small><div class="d-flex flex-wrap gap-2 pos-table-grid-inner">';
                        (area.tables || []).forEach(function (t) {
                            html += renderRestaurantTableButton(t);
                        });
                        html += '</div></div>';
                    });
                    $('#pos-table-grid').html(html || '<p class="text-muted small mb-0">{{ __("pos.no_data_found") }}</p>');
                });
            }

            function posRestaurantToggleTableGridPanel(forceOpen) {
                var $wrap = $('#pos-restaurant-table-grid-wrap');
                if (!$wrap.length || $('#pos-restaurant-order-type').val() !== 'dine_in') {
                    return;
                }
                if (typeof forceOpen === 'boolean') {
                    $wrap.toggleClass('d-none', !forceOpen);
                } else {
                    $wrap.toggleClass('d-none');
                }
                var expanded = !$wrap.hasClass('d-none');
                $('#pos-open-table-panel-btn')
                    .attr('aria-expanded', expanded ? 'true' : 'false')
                    .toggleClass('active btn-primary', expanded)
                    .toggleClass('btn-outline-primary', !expanded);
            }

            $(document).on('click', '#pos-open-table-panel-btn', function () {
                posRestaurantToggleTableGridPanel();
            });

            function posRestaurantToggleOrderTypeUI(orderType) {
                var isDineIn = orderType === 'dine_in';
                $('#pos-restaurant-table-block').toggleClass('d-none', !isDineIn);
                $('#pos-open-table-panel-btn').toggleClass('d-none', !isDineIn);
                $('#pos-restaurant-guest-block').toggleClass('d-none', isDineIn);
                $('#pos-restaurant-table-grid-wrap').addClass('d-none');
                $('#pos-open-table-panel-btn')
                    .attr('aria-expanded', 'false')
                    .removeClass('active btn-primary')
                    .addClass('btn-outline-primary');
                $('#pos-restaurant-customer-grid-wrap').toggleClass('d-none', isDineIn);
                $('#pos-restaurant-manage-tables-wrap').toggleClass('d-none', !isDineIn);
            }

            $('#pos-restaurant-order-type').on('change', function () {
                var orderType = $(this).val() || 'dine_in';
                $('#pos_order_type_hidden').val(orderType);
                posRestaurantToggleOrderTypeUI(orderType);
            });

            if ($('#pos-restaurant-order-type').length) {
                posRestaurantToggleOrderTypeUI($('#pos-restaurant-order-type').val() || 'dine_in');
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
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.open_shift_required_to_open_table") }}', 'error');
                    return;
                }
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/status',
                    method: 'PATCH',
                    data: { status: 'occupied', shift_id: shiftId, _token: $('meta[name="csrf-token"]').attr('content') },
                    success: function () {
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
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
                var hadSentLines = $('#tbody-restaurant tr[data-product-id][data-is-sent="1"]').length > 0;
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    if ($(this).attr('data-is-sent') === '1') return;
                    var name = $(this).attr('data-name') || $(this).find('.name').text();
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || $(this).attr('data-quantity') || 1, 10);
                    var $n = $(this).find('.notes');
                    var note = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    rows.push({ name: name, qty: qty, note: note });
                    var id = $(this).attr('data-product-id');
                    var price = parseFloat($(this).attr('data-price')) || 0;
                    var discount = parseFloat($(this).attr('data-discount')) || 0;
                    if (id && qty > 0) items.push({ id: id, quantity: qty, price: price, discount: discount, description: note });
                });
                if (items.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                function doPrintFromJobs(printJobs, isAddon, orderRef) {
                    var jobs = Array.isArray(printJobs) ? printJobs : [];
                    if (jobs.length === 0) {
                        // Fallback: print only the unsent rows we collected (never full order).
                        var w = window.open('', '_blank', 'width=380,height=400');
                        w.document.write('<html><head><title>{{ __("pos.kitchen_slip") }}</title><style>body{font-family:monospace;padding:12px;font-size:14px;}h3{margin:0 0 8px;}table{width:100%;}td{padding:4px 0;}td:last-child{text-align:right;}.note{font-size:12px;color:#555;font-style:italic;}.addon-banner{font-weight:bold;font-size:16px;margin:8px 0;border:2px solid #000;padding:6px;text-align:center;}</style></head><body>');
                        w.document.write('<h3>{{ __("pos.kitchen_slip") }}</h3>');
                        if (isAddon) {
                            w.document.write('<div class="addon-banner">{{ __("pos.kitchen_slip_addon") }}</div>');
                        }
                        w.document.write('<p><strong>{{ __("pos.table") }}:</strong> ' + (tableName || '—') + '</p>');
                        if (orderRef) {
                            w.document.write('<p><strong>{{ __("pos.kitchen_slip_order_ref") }}</strong> ' + String(orderRef).replace(/</g, '&lt;') + '</p>');
                        }
                        w.document.write('<p><small>' + new Date().toLocaleString() + '</small></p>');
                        w.document.write('<table><thead><tr><th>{{ __("pos.name") }}</th><th>{{ __("pos.qty") }}</th><th>{{ __("Note") }}</th></tr></thead><tbody>');
                        rows.forEach(function(r) {
                            var note = (r.note || '').replace(/</g, '&lt;');
                            var optHtml = '';
                            function appendSlipLabels(list) {
                                (Array.isArray(list) ? list : []).forEach(function (opt) {
                                    var label = typeof opt === 'string' ? opt : (opt && opt.name ? opt.name : '');
                                    label = String(label || '').replace(/</g, '&lt;');
                                    if (label) optHtml += '<br>+ ' + label;
                                });
                            }
                            appendSlipLabels(r.options);
                            appendSlipLabels(r.ingredients);
                            w.document.write('<tr><td>' + (r.name || '').replace(/</g, '&lt;') + optHtml + '</td><td>' + r.qty + '</td><td class="note">' + note + '</td></tr>');
                        });
                        w.document.write('</tbody></table></body></html>');
                        w.document.close();
                        w.focus();
                        w.print();
                        w.close();
                        return;
                    }
                    var delay = 0;
                    jobs.forEach(function(job) {
                        if (!job || !Array.isArray(job.rows) || job.rows.length === 0) return;
                        (function(jobTitle, jobRows, d, addon, ref) {
                            setTimeout(function() {
                                var w = window.open('', '_blank', 'width=380,height=400');
                                w.document.write('<html><head><title>' + (jobTitle || '{{ __("pos.kitchen_slip") }}') + '</title><style>body{font-family:monospace;padding:12px;font-size:14px;}h3{margin:0 0 8px;}table{width:100%;}td{padding:4px 0;}td:last-child{text-align:right;}.note{font-size:12px;color:#555;font-style:italic;}.addon-banner{font-weight:bold;font-size:16px;margin:8px 0;border:2px solid #000;padding:6px;text-align:center;}</style></head><body>');
                                w.document.write('<h3>' + (jobTitle || '{{ __("pos.kitchen_slip") }}') + '</h3>');
                                if (addon) {
                                    w.document.write('<div class="addon-banner">{{ __("pos.kitchen_slip_addon") }}</div>');
                                }
                                w.document.write('<p><strong>{{ __("pos.table") }}:</strong> ' + (tableName || '—') + '</p>');
                                if (ref) {
                                    w.document.write('<p><strong>{{ __("pos.kitchen_slip_order_ref") }}</strong> ' + String(ref).replace(/</g, '&lt;') + '</p>');
                                }
                                w.document.write('<p><small>' + new Date().toLocaleString() + '</small></p>');
                                w.document.write('<table><thead><tr><th>{{ __("pos.name") }}</th><th>{{ __("pos.qty") }}</th></tr></thead><tbody>');
                                jobRows.forEach(function(r) {
                                    var optHtml = '';
                                    function appendSlipLabels(list) {
                                        (Array.isArray(list) ? list : []).forEach(function (opt) {
                                            var label = typeof opt === 'string' ? opt : (opt && opt.name ? opt.name : '');
                                            label = String(label || '').replace(/</g, '&lt;');
                                            if (label) optHtml += '<br>+ ' + label;
                                        });
                                    }
                                    appendSlipLabels(r.options);
                                    appendSlipLabels(r.ingredients);
                                    w.document.write('<tr><td>' + (r.name || '').replace(/</g, '&lt;') + optHtml + '</td><td>' + (r.qty || '') + '</td></tr>');
                                });
                                w.document.write('</tbody></table></body></html>');
                                w.document.close();
                                w.focus();
                                w.print();
                                w.close();
                            }, d);
                        })(job.title || '{{ __("pos.kitchen_slip") }}', job.rows, delay, isAddon, orderRef);
                        delay += 400;
                    });
                }
                var orderTypeMain = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                var isNonDineInMain = (orderTypeMain === 'delivery' || orderTypeMain === 'takeaway');
                if (!branchId || !shiftId || (!isNonDineInMain && !tableId)) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_branch_first") }}', 'error');
                    return;
                }
                var printData = { _token: '{{ csrf_token() }}', branch_id: branchId, shift_id: shiftId, order_type: orderTypeMain, items: items, kitchen_slip_only: 1 };
                if (isNonDineInMain) {
                    printData.guest_name = ($('#pos-guest-name').val() || '').trim();
                    printData.guest_email = ($('#pos-guest-email').val() || '').trim();
                    printData.guest_phone = ($('#pos-guest-phone').val() || '').trim();
                    var existingPosIdMain = $('#pos_restaurant_pos_id').val();
                    if (existingPosIdMain) printData.pos_id = existingPosIdMain;
                } else {
                    printData.table_id = tableId;
                }
                $.ajax({
                    type: 'POST',
                    url: '{{ route("pos.send.to.production") }}',
                    data: printData,
                    success: function (res) {
                        if (res.success) {
                            $('#tbody-restaurant tr[data-product-id]').each(function () {
                                if ($(this).attr('data-is-sent') === '1') return;
                                $(this).attr('data-is-sent', '1').attr('data-status', 'pending')
                                    .removeClass('pos-cart-row--draft').addClass('pos-cart-row--sent pos-cart-row-sent');
                            });
                            doPrintFromJobs(res.print_jobs, hadSentLines, res.pos_id || $('#pos_restaurant_pos_id').val() || '');
                            if (res.pos_id) {
                                $('#pos_restaurant_pos_id').val(res.pos_id);
                            }
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
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
                $.get(posRestaurantLayoutUrl, { branch_id: branchId, for_manage: 1 }, function (res) {
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
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.open_shift_required_to_open_table") }}', 'error');
                    return;
                }
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/status',
                    method: 'PATCH',
                    data: { status: 'available', shift_id: shiftId, _token: $('meta[name="csrf-token"]').attr('content') },
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

            // On load: if branch is pre-selected (e.g. single branch), load restaurant options & warehouses and current user's open shift (for retail shift report)
            var initBranchId = $( '#branch_id' ).val();
            if (initBranchId) {
                toggleRestaurantOptions(initBranchId);
                loadBranchShifts(initBranchId);
                $.get('{{ route("pos.warehouses.by.branch") }}', { branch_id: initBranchId }, function(data) {
                    var parsed = parsePosWarehousesResponse(data);
                    var $warehouse = $( '#warehouse' );
                    var ids = parsed.ids;
                    if (ids.length) {
                        var prevVal = $warehouse.val();
                        $warehouse.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(parsed.list, function(id, name) {
                            $warehouse.append($('<option></option>').val(id).text(name));
                        });
                        var wh = ids.indexOf(String(prevVal)) !== -1 ? prevVal : parsed.preferred;
                        $warehouse.val(wh);
                        $( "#warehouse_name_hidden" ).val(wh);
                    }
                });
            }

            // Multi-branch: filter warehouses when branch changes; also load current user's open shift for this branch (retail)
            $( '#branch_id' ).change(function() {
                var branchId = $(this).val();
                if (!branchId) {
                    return;
                }
                toggleRestaurantOptions(branchId);
                loadBranchShifts(branchId);
                var session_key = $( "#empty_cart" ).val();
                $.ajax({
                    type: 'GET',
                    url: '{{ route("pos.warehouses.by.branch") }}',
                    data: { branch_id: branchId },
                    success: function (data) {
                        var parsed = parsePosWarehousesResponse(data);
                        var $warehouse = $( '#warehouse' );
                        var ids = parsed.ids;
                        if (!ids.length) {
                            return;
                        }
                        $warehouse.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(parsed.list, function(id, name) {
                            $warehouse.append($('<option></option>').val(id).text(name));
                        });
                        var wh = parsed.preferred;
                        $warehouse.val(wh);
                        $( "#warehouse_name_hidden" ).val(wh);
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
                // Show Edit only when default Walk-in customer is selected (add new POS walk-in)
                var isWalkIn = ($('#customer').val() || '').toString() === 'Walk-in-customer';
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
                $('#pos-pay-balance-shift-id').val($('#pos_shift_id_hidden').length ? ($('#pos_shift_id_hidden').val() || '') : '');
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
                            if (res.receipt_html) {
                                $('#commonModal .modal-title').html('{{ __("pos.pos_invoice") }}');
                                $('#commonModal .modal-dialog').removeClass('modal-xl modal-lg modal-md').addClass('modal-sm');
                                $('#commonModal .body').html(res.receipt_html);
                                $('#commonModal').modal('show');
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
                                .attr('data-email', c.email || '').attr('data-contact', c.contact || '').attr('data-type', 'walk-in');
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
                var ware_id = getRestaurantWarehouseId();
                var cat = getRestaurantCategoryId();
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
                function showProductsLoadError(xhr) {
                    var msg = '{{ __("pos.products_load_failed") }}';
                    if (xhr && xhr.status === 419) {
                        msg = '{{ __("pos.sync_refresh") }}';
                    } else if (xhr && xhr.status >= 500) {
                        msg = '{{ __("pos.sync_server_error") }}';
                    }
                    renderProducts('<div class="card card-body col-12 text-center"><h5 class="mb-2">' + msg + '</h5><button type="button" class="btn btn-sm btn-primary pos-retry-products">{{ __("pos.retry_load_products") }}</button></div>');
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
                    cache: false,
                    headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' },
                    data: { 'search': value, 'cat_id': cat_id, 'war_id': war_id, 'session_key': session_key, 'type': type, 'page': page, '_': Date.now() },
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
                    error: function(xhr) {
                        if (!navigator.onLine) {
                            var cached = posGetCachedProducts(cacheKey);
                            if (cached) renderProducts(cached);
                            else showOfflineNoProducts();
                            return;
                        }
                        var cached = posGetCachedProducts(cacheKey);
                        if (cached) renderProducts(cached);
                        else showProductsLoadError(xhr);
                    }
                });
            }
            $(document).on('click', '.pos-retry-products', function() {
                var p = posLastSearchParams;
                if (p.url) searchProducts(p.url, p.value, p.cat_id, p.war_id, p.type, p.targetId);
            });
            $(document).on('click', '.pos-load-more-products', function() {
                var p = posLastSearchParams;
                if (p.url) searchProducts(p.url, p.value, p.cat_id, p.war_id, p.type, p.targetId, true);
            });

            var posMeasureState = null;
            function posMeasurementNeeded($el) {
                var unitType = (($el.data('unit-type') || '') + '').toLowerCase();
                return ['sm', 'cbm', 'volume'].indexOf(unitType) !== -1;
            }
            function posOpenMeasureModal($el, onDone) {
                var unitType = (($el.data('unit-type') || '') + '').toLowerCase();
                if (['sm', 'cbm', 'volume'].indexOf(unitType) === -1) {
                    onDone(null);
                    return;
                }
                var dimUnit = (($el.data('dimension-unit') || 'cm') + '');
                posMeasureState = {$el: $el, onDone: onDone, unitType: unitType};
                $('.pos_measure_dim_unit').text(dimUnit);
                $('#pos_measure_divisor_wrap').toggle(unitType === 'cbm');
                $('#pos_measure_height_wrap').toggle(unitType !== 'sm');
                $('#pos_measure_divisor').val(parseFloat($el.data('volumetric-divisor') || 0) || '');
                $('#pos_measure_length').val('');
                $('#pos_measure_width').val('');
                $('#pos_measure_height').val('');
                $('#posMeasureModal').modal('show');
            }
            $(document).on('click', '#pos_measure_cancel', function () {
                if (posMeasureState && typeof posMeasureState.onDone === 'function') {
                    posMeasureState.onDone(false);
                }
                $('#posMeasureModal').modal('hide');
                posMeasureState = null;
            });
            $('#posMeasureModal').on('hidden.bs.modal', function () {
                if (posMeasureState && typeof posMeasureState.onDone === 'function') {
                    posMeasureState.onDone(false);
                    posMeasureState = null;
                }
            });
            $(document).on('click', '#pos_measure_apply', function () {
                if (!posMeasureState) return;
                var $el = posMeasureState.$el;
                var unitType = posMeasureState.unitType;
                var dimUnit = (($el.data('dimension-unit') || 'cm') + '');
                var divisor = parseFloat($('#pos_measure_divisor').val() || $el.data('volumetric-divisor') || 0);
                var l = parseFloat($('#pos_measure_length').val() || 0);
                var w = parseFloat($('#pos_measure_width').val() || 0);
                var h = unitType === 'sm' ? 0 : parseFloat($('#pos_measure_height').val() || 0);
                if (l <= 0 || w <= 0 || (unitType !== 'sm' && h <= 0)) {
                    show_toastr('{{ __("Error") }}', 'Valid dimensions are required.', 'error');
                    return;
                }
                var factor = 1;
                if (unitType === 'sm') factor = l * w;
                else if (unitType === 'cbm') {
                    if (divisor <= 0) divisor = 5000;
                    factor = (l * w * h) / divisor;
                } else factor = l * w * h;
                factor = parseFloat(factor.toFixed(4));
                if (factor <= 0) {
                    show_toastr('{{ __("Error") }}', 'Calculated value must be greater than zero.', 'error');
                    return;
                }
                var basePrice = parseFloat($el.data('product-price') || 0);
                var payload = {
                    unit_type: unitType,
                    dimension_unit: dimUnit,
                    length: l,
                    width: w,
                    height: h,
                    divisor: divisor || null,
                    factor: factor,
                    measured_price: parseFloat((basePrice * factor).toFixed(2)),
                    line_suffix: (Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8))
                };
                posMeasureState.onDone(payload);
                $('#posMeasureModal').modal('hide');
                posMeasureState = null;
            });

            function posFillVariantPickerSelect(vlist) {
                var $sel = $('#posVariantPickerSelect').empty();
                (vlist || []).forEach(function (v) {
                    var ps = (v.sale_price != null && v.sale_price !== '') ? (' — ' + v.sale_price) : '';
                    $sel.append($('<option/>').val(v.id).text((v.label || ((v.size || '') + ' / ' + (v.color || ''))) + ps).attr('data-sale-price', v.sale_price != null ? v.sale_price : ''));
                });
            }

            function posOpenVariantPickerThen($el, proceedFn) {
                var rawVL = $el.attr('data-variants');
                var vlist = [];
                try { vlist = rawVL ? JSON.parse(rawVL) : []; } catch (vex) { vlist = []; }
                var showModal = function (list) {
                    if (!list || !list.length) {
                        if (typeof show_toastr === 'function') {
                            show_toastr('{{ __("Error") }}', '{{ __("Select size / color variant for this product.") }}', 'error');
                        }
                        return;
                    }
                    posFillVariantPickerSelect(list);
                    $('#posVariantPickerModal').data('posVariantCtx', { $el: $el, proceed: proceedFn });
                    $('#posVariantPickerModal').modal('show');
                };
                if (vlist.length) {
                    showModal(vlist);
                    return;
                }
                var pid = $el.data('product-id');
                if (!pid) {
                    showModal([]);
                    return;
                }
                $.get('{{ url("productservice") }}/' + pid + '/variants-json', function (resp) {
                    var list = (resp && resp.variants) ? resp.variants : [];
                    if (list.length) {
                        try { $el.attr('data-variants', JSON.stringify(list)); } catch (e) {}
                    }
                    showModal(list);
                }).fail(function () {
                    showModal([]);
                });
            }

            $(document).on('click', '#posVariantPickerConfirm', function () {
                var $modal = $('#posVariantPickerModal');
                var d = $modal.data('posVariantCtx');
                $modal.modal('hide');
                if (!d || !d.$el || typeof d.proceed !== 'function') return;
                var vid = $('#posVariantPickerSelect').val();
                if (!vid) return;
                var salePrice = $('#posVariantPickerSelect option:selected').attr('data-sale-price');
                if (salePrice !== undefined && salePrice !== '' && !isNaN(parseFloat(salePrice))) {
                    d.$el.attr('data-product-price', parseFloat(salePrice));
                    d.$el.data('product-price', parseFloat(salePrice));
                }
                d.proceed(vid);
            });

            var posProductOptionsJsonBase = @json(url('/productservice'));

            function posProductHasOptions($el) {
                return String($el.attr('data-options-enabled')) === '1';
            }

            function posOpenOptionsModal($el, optsList, onDone) {
                if (!window.ErpProductOptions || typeof window.ErpProductOptions.open !== 'function') {
                    if (typeof show_toastr === 'function') {
                        show_toastr('{{ __("Error") }}', '{{ __("Options picker failed to load. Refresh the page.") }}', 'error');
                    }
                    return;
                }
                if (!optsList || !optsList.length) {
                    if (typeof show_toastr === 'function') {
                        show_toastr('{{ __("Error") }}', '{{ __("No options configured for this product.") }}', 'error');
                    }
                    return;
                }
                var item = {
                    product: {
                        name: $el.attr('data-product-name') || $el.data('productName') || '',
                        sale_price: parseFloat($el.attr('data-product-price') || $el.data('productPrice')) || 0,
                        purchase_price: parseFloat($el.attr('data-product-price') || $el.data('productPrice')) || 0
                    },
                    options: optsList
                };
                window.ErpProductOptions.open(item, function (selected) {
                    onDone(selected || []);
                }, { forPurchase: false });
            }

            function posLoadProductOptions($el, done) {
                var productId = $el.attr('data-product-id') || $el.data('productId');
                if (!productId) {
                    done(null, []);
                    return;
                }
                $.ajax({
                    url: posProductOptionsJsonBase + '/' + String(productId) + '/options-json',
                    type: 'GET',
                    dataType: 'json',
                    success: function (resp) {
                        if (resp && resp.options_enabled) {
                            $el.attr('data-options-enabled', '1');
                        }
                        var list = (resp && resp.options) ? resp.options : [];
                        if (list.length) {
                            try { $el.attr('data-options', JSON.stringify(list)); } catch (e) {}
                        }
                        done(resp, list);
                    },
                    error: function () {
                        done(null, []);
                    }
                });
            }

            function posOpenOptionsThen($el, onDone) {
                var rawO = $el.attr('data-options');
                var opts = [];
                try { opts = rawO ? JSON.parse(rawO) : []; } catch (e) { opts = []; }

                if (posProductHasOptions($el) && opts.length) {
                    posOpenOptionsModal($el, opts, onDone);
                    return;
                }

                if (!posProductHasOptions($el) && !opts.length) {
                    posLoadProductOptions($el, function (resp, list) {
                        if (resp && resp.options_enabled && list.length) {
                            posOpenOptionsModal($el, list, onDone);
                        } else {
                            onDone(null);
                        }
                    });
                    return;
                }

                if (posProductHasOptions($el)) {
                    posLoadProductOptions($el, function (resp, list) {
                        if (list.length) {
                            posOpenOptionsModal($el, list, onDone);
                        } else if (typeof show_toastr === 'function') {
                            show_toastr('{{ __("Error") }}', '{{ __("No options configured for this product.") }}', 'error');
                        }
                    });
                    return;
                }

                onDone(null);
            }

            $(document).on('click', '.toacart', function () {
                var $el = $(this);
                var $btn = $el.find('.pos-add-cart-btn, button').first();
                var isRestaurant = $el.closest('#pos-restaurant-panel').length > 0;
                var tbodySel = isRestaurant ? '#tbody-restaurant' : '#tbody';
                var rowIdPrefix = isRestaurant ? 'product-id-r-' : 'product-id-';
                var totalSel = isRestaurant ? '#pos-restaurant-total' : '#displaytotal, .totalamount';
                var runAddToCart = function (measureData, productVariantId, selectedOptions) {
                if (!navigator.onLine && $el.data('product-id')) {
                    if (String($el.attr('data-variants-enabled')) === '1' || posProductHasOptions($el)) {
                        if (typeof show_toastr === 'function') show_toastr('error', '{{ __("Variant products require online mode.") }}', 'error');
                        return;
                    }
                    var id = $el.data('product-id');
                    var name = $el.data('product-name') || '';
                    var price = measureData ? (parseFloat(measureData.measured_price) || 0) : (parseFloat($el.data('product-price')) || 0);
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
                        var row = isRestaurant ? ('<tr data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-price-type="exclusive" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '" data-measurement-json="' + (measureData ? encodeURIComponent(JSON.stringify(measureData)) : '') + '"><td class="cart-images"><img alt="" src="' + imgSrc + '" class="card-image avatar shadow hover-shadow-lg"></td><td class="name">' + (name.replace(/</g, '&lt;')) + '</td><td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" title="{{ __("pos.quantity") }}" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus"></span></td><td class="tax">-</td><td class="price">' + addCommas(price) + '</td><td class="subtotal">' + addCommas(subtotal) + '</td><td class="action-btn"><a href="#" class="btn btn-sm bg-danger pos-offline-remove" data-id="' + id + '" title="{{ __("pos.delete") }}"><i class="ti ti-trash text-white"></i></a></td></tr>') : ('<div class="pos-cart-card-item card border rounded-2 mb-2 shadow-sm" data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-price-type="exclusive" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '" data-measurement-json="' + (measureData ? encodeURIComponent(JSON.stringify(measureData)) : '') + '"><div class="card-body p-2"><div class="d-flex justify-content-between align-items-start"><div class="flex-grow-1 min-w-0"><h6 class="mb-0 fw-bold name">' + (name.replace(/</g, '&lt;')) + '</h6></div><div class="action-btn"><a href="#" class="btn btn-sm btn-danger p-1 pos-offline-remove" data-id="' + id + '"><i class="ti ti-trash"></i></a></div></div><div class="d-flex align-items-center justify-content-between mt-2"><span class="quantity buttons_added d-inline-flex align-items-center border rounded"><input type="button" value="-" class="minus btn btn-sm btn-outline-secondary border-0 py-0"><input type="number" step="1" min="1" name="quantity" class="input-number form-control form-control-sm border-0 text-center" style="width:2.5rem;" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus btn btn-sm btn-outline-secondary border-0 py-0"></span><div class="fw-bold subtotal">' + addCommas(subtotal) + '</div></div><small class="text-muted tax">Tax: -</small></div></div>');
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
                try {
                    var parsedAddToCartUrl = new URL(addToCartUrl, window.location.origin);
                    parsedAddToCartUrl.protocol = window.location.protocol;
                    parsedAddToCartUrl.host = window.location.host;
                    addToCartUrl = parsedAddToCartUrl.toString();
                } catch (e) {}
                var selectedWarId = getRestaurantWarehouseId();
                if (!selectedWarId || selectedWarId === '0') {
                    selectedWarId = '';
                    if (isRestaurant) {
                        selectedWarId = $('#pos-restaurant-warehouse').length ? ($('#pos-restaurant-warehouse').val() || '') : '';
                    }
                    if (!selectedWarId) {
                        selectedWarId = $('#warehouse').length ? ($('#warehouse').val() || '') : '';
                    }
                }
                if (selectedWarId && selectedWarId !== '0') {
                    addToCartUrl += (addToCartUrl.indexOf('?') !== -1 ? '&' : '?') + 'war_id=' + encodeURIComponent(selectedWarId);
                }
                var taxRate = $el.data('product-tax');
                if (taxRate !== undefined && taxRate !== '' && !isNaN(parseFloat(taxRate))) {
                    addToCartUrl += (addToCartUrl.indexOf('?') !== -1 ? '&' : '?') + 'tax_rate=' + encodeURIComponent(taxRate);
                }
                if (measureData) {
                    addToCartUrl += (addToCartUrl.indexOf('?') !== -1 ? '&' : '?') + 'measured_price=' + encodeURIComponent(measureData.measured_price);
                    addToCartUrl += '&line_suffix=' + encodeURIComponent(measureData.line_suffix);
                    addToCartUrl += '&measurement_json=' + encodeURIComponent(JSON.stringify(measureData));
                }
                if (productVariantId) {
                    addToCartUrl += (addToCartUrl.indexOf('?') !== -1 ? '&' : '?') + 'product_variant_id=' + encodeURIComponent(productVariantId);
                }
                if (selectedOptions && selectedOptions.length) {
                    addToCartUrl += (addToCartUrl.indexOf('?') !== -1 ? '&' : '?') + 'selected_options=' + encodeURIComponent(JSON.stringify(selectedOptions));
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
                                        var $row = $('#product-id-' + String(value.cart_line_key || value.id).replace(/_/g, '-'));
                                        if (!$row.length) {
                                            $row = $('#product-id-' + value.id);
                                        }
                                        if ($row.length) {
                                            $row.attr('data-subtotal', value.subtotal);
                                            if (value.options_amount != null) {
                                                $row.attr('data-options-amount', value.options_amount);
                                            }
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
                };
                var proceedAfterOptions = function (variantId, selectedOptions) {
                    if (posMeasurementNeeded($el)) {
                        posOpenMeasureModal($el, function (measureData) {
                            if (measureData === false) return;
                            runAddToCart(measureData, variantId || null, selectedOptions || null);
                        });
                        return;
                    }
                    runAddToCart(null, variantId || null, selectedOptions || null);
                };
                var proceedAfterVariant = function (variantId) {
                    posOpenOptionsThen($el, function (selectedOptions) {
                        proceedAfterOptions(variantId, selectedOptions);
                    });
                };
                if (navigator.onLine && String($el.attr('data-variants-enabled')) === '1') {
                    posOpenVariantPickerThen($el, proceedAfterVariant);
                    return;
                }
                proceedAfterVariant(null);
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
                        var priceType = ($row.attr('data-price-type') || 'exclusive').toLowerCase();
                        var optionsAmount = Math.max(0, parseFloat($row.attr('data-options-amount')) || 0);
                        var subtotal;
                        if (priceType === 'inclusive' && taxRate > 0) {
                            subtotal = (price * quantity) + optionsAmount;
                        } else {
                            var tax = price * taxRate / 100;
                            subtotal = ((price + tax) * quantity) + optionsAmount;
                        }
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
                var payload = (function() {
                    var d = {
                        vc_name: $('#vc_name_hidden').val(),
                        warehouse_name: $('#warehouse_name_hidden').val(),
                        discount: $('#discount_hidden').val(),
                        quotation_id: $('#quotation_id').val(),
                        payment_method: $('#payment_method').length ? $('#payment_method').val() : 'cash',
                        pos_shift_id: $('#pos_shift_id_hidden').length ? $('#pos_shift_id_hidden').val() || '' : '',
                        hotel_charge_enabled: $('#hotel_charge_enabled').length ? ($('#hotel_charge_enabled').is(':checked') ? 1 : 0) : 0,
                        hotel_folio_id: $('#hotel_folio_id').length ? ($('#hotel_folio_id').val() || '') : ''
                    };
                    if (d.hotel_charge_enabled && !d.hotel_folio_id) {
                        show_toastr('{{ __("Error") }}', '{{ __("Please select room folio.") }}', 'error');
                        return null;
                    }
                    if (!posIsRetail && $('#pos_order_type_hidden').length) { d.pos_order_type = $('#pos_order_type_hidden').val() || ''; d.pos_table_id = $('#pos_table_id_hidden').length ? $('#pos_table_id_hidden').val() || '' : ''; }
                    return d;
                })();
                if (!payload) {
                    console.warn('POS payment aborted: invalid payload.');
                    return;
                }
                $.ajax({
                    url: '{{ route("pos.data.store") }}',
                    method: 'GET',
                    data: payload,
                    beforeSend: function () {
                        ele.prop('disabled', true);
                    },
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
                            var receiptUrl = '{{ url("printview/pos") }}?pos_id=' + data.pos_id + '&source=retail';
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
                        var pt = ($(this).attr('data-price-type') || 'exclusive').toLowerCase();
                        if (pt === 'inclusive' && t > 0) {
                            subtotal += p * q;
                        } else {
                            subtotal += (p + p * t / 100) * q;
                        }
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
        window.posParseOpeningBalance = function (raw) {
            var amount = String(raw == null ? '' : raw).replace(/\s/g, '');
            if (amount === '') return 0;
            if (amount.indexOf(',') !== -1) {
                amount = amount.replace(/\./g, '').replace(',', '.');
            } else if (/^\d{1,3}(\.\d{3})+$/.test(amount)) {
                amount = amount.replace(/\./g, '');
            }
            if (!/^\d+(\.\d+)?$/.test(amount)) return null;
            var value = Math.round(parseFloat(amount) * 100) / 100;
            return isFinite(value) && value >= 0 ? value : null;
        };
        $(function () {
            var $btn = $('#pos-open-shift-btn');
            var $modal = $('#posOpenShiftModal');
            var $form = $('#pos-open-shift-form');
            if ($btn.length && $modal.length) {
                $btn.on('click', function () { $modal.modal('show'); });
                $form.on('submit', function (e) {
                    e.preventDefault();
                    var openingBalance = window.posParseOpeningBalance($form.find('[name=opening_balance]').val());
                    if (openingBalance === null) {
                        if (typeof show_toastr === 'function') show_toastr('{{ __("pos.error") }}', '{{ __("Enter a valid opening balance.") }}', 'error');
                        return;
                    }
                    var $submit = $('#pos-open-shift-submit').prop('disabled', true);
                    $.ajax({
                        url: '{{ route("pos-shift.open-from-pos") }}',
                        method: 'POST',
                        data: { branch_id: $form.find('[name=branch_id]').val(), operational_shift: $form.find('[name=operational_shift]').val(), opening_balance: openingBalance, _token: $form.find('[name=_token]').val() },
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
        $(document).on('click','.pay-receipt-print',function(){
            var pos_id = $(this).data('id');
            $('#commonModal .modal-dialog').addClass('modal-sm');
            $.get('{{url("printview/pos")}}', {
                pos_id: pos_id,
                source: 'retail',
                modal: 1
            }, function(html){
                $('#commonModal .modal-title').text('POS Receipt');
                $('#commonModal .body').html(html);
                $('#commonModal').modal('show');
            });
        });
        $(document).on('click', '.pos-eod-link', function (e) {
            e.preventDefault();
            var date = $(this).data('date');
            if (date) $('#pos-eod-date-input').val(date);
        });
        $(document).on('click', '.pos-add-closing-balance-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var url = $btn.data('url');
            var title = $btn.data('title');
            if (!url) return;
            $('#commonModal .modal-dialog').removeClass('modal-xl modal-lg modal-sm').addClass('modal-md');
            $('#commonModal .modal-title').html(title || '{{ __("pos.add_closing_balance") }}');
            $.ajax({
                url: url,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                success: function (html) {
                    $('#commonModal .body').html(html);
                    $('#commonModal').modal('show');
                    if (window.initShiftCloseCount) {
                        document.querySelectorAll('#commonModal .shift-close-count').forEach(function (el) {
                            window.initShiftCloseCount(el);
                        });
                    }
                    if (typeof common_bind === 'function') common_bind('#commonModal');
                    if (typeof validation === 'function') validation();
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
                    if (typeof show_toastr === 'function') show_toastr('{{ __("Error") }}', msg, 'error');
                    else alert(msg);
                }
            });
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
@include('pos.partials.variant-picker-modal')
@include('partials.product-options-picker')
<div class="modal fade" id="posMeasureModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Enter Dimensions') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">{{ __('Length') }} (<span class="pos_measure_dim_unit">cm</span>)</label>
                    <input type="number" step="0.0001" min="0" id="pos_measure_length" class="form-control">
                </div>
                <div class="mb-2">
                    <label class="form-label">{{ __('Width') }} (<span class="pos_measure_dim_unit">cm</span>)</label>
                    <input type="number" step="0.0001" min="0" id="pos_measure_width" class="form-control">
                </div>
                <div class="mb-2" id="pos_measure_height_wrap">
                    <label class="form-label">{{ __('Height') }} (<span class="pos_measure_dim_unit">cm</span>)</label>
                    <input type="number" step="0.0001" min="0" id="pos_measure_height" class="form-control">
                </div>
                <div class="mb-2" id="pos_measure_divisor_wrap" style="display:none;">
                    <label class="form-label">{{ __('Divisor') }}</label>
                    <input type="number" step="1" min="1" id="pos_measure_divisor" class="form-control" placeholder="4000 / 5000 / 6000">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="pos_measure_cancel">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="pos_measure_apply">{{ __('Apply') }}</button>
            </div>
        </div>
    </div>
</div>
</body>
</html> 