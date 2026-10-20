@php
    $logo = \App\Models\Utility::get_file('uploads/logo');
    $company_favicon = Utility::getValByName('company_favicon');
    $SITE_RTL = Utility::getValByName('SITE_RTL');
    $setting = \App\Models\Utility::colorset();
    $color = 'theme-3';
    if (!empty($setting['color'])) {
        $color = $setting['color'];
    }
    $themeColor = (isset($setting['color_flag']) && $setting['color_flag'] == 'true') ? 'theme-3' : $color;
    $tp = function (string $key, string $fallback) {
        $translated = __($key);
        return $translated === $key ? __($fallback) : $translated;
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ !empty($setting['title_text']) ? $setting['title_text'] : config('app.name', 'ERP SaaS') }} - {{ __('Restaurant POS') }}</title>
    <link rel="icon" href="{{ $logo . '/' . (!empty($company_favicon) ? $company_favicon : 'favicon.png') . '?' . time() }}" type="image" sizes="16x16">
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}?v=10">
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link">
    <style>
        .pos-top-bar > div {
            flex-shrink: 0;
        }
        .pos-top-bar .pos-brand { font-weight: 700; font-size: 1.25rem; letter-spacing: 0.02em; white-space: nowrap; }
        .pos-restaurant-controls-row {
            flex-wrap: nowrap;
        }
        .pos-restaurant-controls-main {
            scrollbar-width: thin;
        }
        .pos-cart-card .card-header { font-weight: 600; }
        .pos-cart-card .total-section { background: rgba(0,0,0,0.03); border-radius: 0.5rem; padding: 1rem; }
        #categories-listing{
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
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
        .pos-line-status{
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
        }
        .pos-line-status{
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
        }

/* ================= POS Top Bar Mobile Responsive (FINAL) ================= */
@media (max-width: 991.98px) {
    .pos-top-bar > div { flex-shrink: 1 !important; }
    .pos-top-bar {
        flex-wrap: wrap !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
        padding: 10px 12px !important;
    }
    .pos-top-bar > div:first-child {
        display: flex !important;
        flex-wrap: wrap !important;
        align-items: center !important;
        gap: 6px !important;
        width: 100% !important;
    }
    .pos-top-bar .pos-brand {
        font-size: 1rem !important;
        white-space: normal !important;
        line-height: 1.2;
    }
    .pos-top-bar > div:first-child > span,
    .pos-top-bar > div:first-child > a {
        font-size: 0.80rem !important;
        padding: 3px 7px !important;
        background: rgba(255,255,255,0.2);
        border-radius: 4px;
        white-space: nowrap;
        border: none !important;
        color: #fff !important;
        margin-left: 0 !important;
    }
    .pos-top-bar > div:last-child {
        display: flex !important;
        flex-wrap: wrap !important;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 5px !important;
        width: 100% !important;
        border-top: 1px solid rgba(255,255,255,0.25);
        padding-top: 8px;
    }
    .pos-top-bar #pos-current-time { font-size: 0.72rem !important; min-width: auto !important; }
    .pos-top-bar .badge { font-size: 0.65rem !important; padding: 3px 6px !important; }
    .pos-top-bar .btn-sm { padding: 5px 7px !important; font-size: 0.7rem !important; line-height: 1.2 !important; }
    .pos-top-bar .btn-sm span,
    .pos-top-bar .btn-sm .d-none.d-sm-inline { display: none !important; }
    .pos-top-bar > div:last-child > span.text-white.opacity-90 {
        font-size: 0.65rem !important;
        line-height: 1.1 !important;
        margin-left: 0 !important;
    }
    .pos-top-bar .btn-sm i,
    .pos-top-bar .btn-sm .ti { font-size: 1rem !important; }
    .pos-top-bar #pos-reports-dropdown { padding: 5px 7px !important; }
}
@media (max-width: 400px) {
    .pos-top-bar .pos-brand { font-size: 0.9rem !important; }
    .pos-top-bar > div:first-child > span,
    .pos-top-bar > div:first-child > a { font-size: 0.80rem !important; padding: 12px 5px !important;background: white !important; color: #000 !important; }
    .pos-top-bar .btn-sm { padding: 4px 6px !important; }
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
                <div class="mt-2 pos-top-bar bg-primary d-flex justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center flex-nowrap gap-2">
                        <span class="text-white pos-brand">{{ __('POS Restaurant') }}</span>
                        @if($userOpenShift)
                            <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('OB') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->opening_balance ?? 0) }}</span>
                            @if(isset($userOpenShift->closing_balance) && $userOpenShift->closing_balance !== null && $userOpenShift->closing_balance !== '')
                                <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.closing_balance') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->closing_balance) }}</span>
                            @else
                                <a href="#" class="btn btn-sm btn-light pos-add-closing-balance-btn" data-url="{{ route('pos.add_closing_balance') }}" data-title="{{ $tp('pos.add_closing_balance', 'Add Closing Balance') }}" data-bs-toggle="tooltip" title="{{ $tp('pos.add_closing_balance', 'Add Closing Balance') }}">{{ __('Closing Balance') }}</a>
                            @endif
                        @else
                            <button type="button" class="btn btn-sm btn-light ms-2" id="pos-open-shift-btn" title="{{ __('pos.open_shift') }}">
                                <i class="ti ti-login"></i> {{ __('pos.open_shift') }}
                            </button>
                        @endif
                    </div>
                    <div class="d-flex align-items-center flex-nowrap gap-2">
                        <span id="pos-current-time" class="text-white opacity-90 small align-middle" style="min-width: 3rem;">--:--:--</span>
                        <span id="pos-status-badge" class="badge bg-success align-middle" style="font-size: 0.75rem;">{{ __('pos.online') }}</span>
                        <span id="pos-pending-badge" class="badge bg-warning text-dark d-none" style="font-size: 0.8rem; cursor: pointer;" data-msg="{{ __('pos.pending_sync_label') }}" title="{{ __('pos.sync_now') }}"></span>
                        <button type="button" id="pos-fullscreen-toggle-btn" class="btn btn-sm btn-light m-0" title="{{ $tp('pos.fullscreen', 'Fullscreen') }}" aria-pressed="false">
                            <i class="ti ti-maximize pos-fullscreen-icon-enter m-0" style="font-size: 1.1rem;"></i>
                            <i class="ti ti-minimize pos-fullscreen-icon-exit d-none m-0" style="font-size: 1.1rem;"></i>
                        </button>
                        <button type="button" id="pos-install-app-btn" class="btn btn-sm btn-light  m-0" title="{{ $tp('pos.install_app', 'Install App') }}">
                            <i class="ti ti-download" style="font-size: 1.1rem;"></i>
                        </button>
                        @if(!empty($canChangePosPrintSettings))
                        <button type="button" id="global-printer-settings-btn" class="btn btn-sm btn-light m-0" title="{{ __('Printer Settings') }}" aria-label="{{ __('Printer Settings') }}">
                            <i class="ti ti-printer" style="font-size: 1.1rem;"></i> <span>{{ __('Print') }}</span>
                        </button>
                        @endif
                        <a href="{{ route('pos.restaurant.index') }}" class="btn btn-sm btn-light m-0" title="{{ __('POS Restaurant') }}"><i class="ti ti-building-store" style="font-size: 1.1rem;"></i></a>
                        <a href="{{ route('pos.index') }}" class="btn btn-sm btn-outline-light m-0" title="{{ __('POS Retail') }}"><i class="ti ti-shopping-cart" style="font-size: 1.1rem;"></i></a>
                        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light m-0" title="{{ __('pos.dashboard') }}"><i class="ti ti-home" style="font-size: 1.1rem;"></i></a>
                        
                        @if (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager') || (isset($posPinUser) && $posPinUser))
                        <div class="dropdown d-inline-block">
                            <button type="button" class="btn btn-sm btn-light dropdown-toggle m-0" id="pos-reports-dropdown" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('Reports & more') }}">
                                <i class="ti ti-report" style="font-size: 1.1rem;"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="pos-reports-dropdown">
                                @if (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager'))
                                <li><h6 class="dropdown-header">{{ __('Reports') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.report') }}" target="_blank"><i class="ti ti-summary me-2"></i>{{ __('pos.pos_summary') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.shift') }}" target="_blank"><i class="ti ti-clock-hour-4 me-2"></i>{{ __('pos.shift_report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.balances') }}" target="_blank"><i class="ti ti-wallet me-2"></i>{{ __('pos.pos_balances') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.daily.pos') }}" target="_blank"><i class="ti ti-calendar me-2"></i>{{ __('POS Daily/Monthly Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.branch') }}" target="_blank"><i class="ti ti-building-store me-2"></i>{{ __('POS by Branch') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.vs.purchase') }}" target="_blank"><i class="ti ti-chart-bar me-2"></i>{{ __('Pos VS Purchase Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.day-summary', ['date' => date('Y-m-d')]) }}" target="_blank"><i class="ti ti-calendar-event me-2"></i>{{ __('Day Summary') }}</a></li>
                                <li><a class="dropdown-item pos-eod-link" href="#" data-date="{{ date('Y-m-d') }}" data-bs-toggle="modal" data-bs-target="#posEodWarningModal"><i class="ti ti-calendar-off me-2"></i>{{ __('pos.day_closed') }} / {{ __('EOD Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.restaurant.sales-by-category') }}" target="_blank"><i class="ti ti-receipt me-2"></i>{{ __('Sales by Categories') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.low-stock') }}" target="_blank"><i class="ti ti-box me-2"></i>{{ __('Minimum Stock Alert') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos-shift.index') }}" target="_blank"><i class="ti ti-calendar me-2"></i>{{ __('POS Shifts') }}</a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#payReceiptsModal"><i class="ti ti-receipt me-2"></i>{{ __('pos.pay_receipts') }}</a></li>
                                @if(isset($posPinUser) && $posPinUser)
                                <li>
                                    <a class="dropdown-item" href="{{ route('pos.restaurant.switch-user') }}" title="{{ $tp('pos.switch_user', 'Switch User') }}">
                                        <i class="ti ti-user me-2"></i>{{ $tp('pos.switch_user', 'Switch User') }}
                                    </a>
                                </li>
                                @endif
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">{{ __('Other') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.barcode') }}" target="_blank"><i class="ti ti-barcode me-2"></i>{{ __('pos.pos_product_barcode') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.setting') }}" target="_blank"><i class="ti ti-settings me-2"></i>{{ __('pos.barcode_setting') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.print.setting') }}" target="_blank"><i class="ti ti-printer me-2"></i>{{ __('Print') }} {{ __('Setting') }}</a></li>
                                @endif
                            </ul>
                        </div>
                        @endif
                        <a href="#" id="pos-end-shift-btn" class="btn btn-sm btn-warning d-none" title="{{ __('pos.end_shift') }}"><i class="ti ti-logout"></i> <span class="d-none d-sm-inline">{{ __('pos.end_shift') }}</span></a>
                        @if(isset($posPinUser) && $posPinUser)
                        <span class="text-white opacity-90 small align-middle ms-1 text-center">{{ $tp('pos.logged_in_as', 'Logged in as') }} <br> <strong>{{ $posPinUser->name }}</strong></span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
@php
            $posMode = $posMode ?? 'restaurant';
            $showModeTabs = false;
            $canChangeShift = $canChangeShift ?? true;
            $userOpenShiftByBranch = $userOpenShiftByBranch ?? [];
            $has_closing_balance = ($userOpenShift && isset($userOpenShift->closing_balance) && $userOpenShift->closing_balance !== null && $userOpenShift->closing_balance !== '');
        @endphp
        {{-- Restaurant-only: no mode tabs, only restaurant panel --}}
        <div id="pos-retail-panel" class="pos-mode-panel d-none">
        <div class="mt-2 row mb-4 gy-2 main_div">
            @if(!$userOpenShift)
                <div class="disabled_div" id="pos-restaurant-no-opening-balance">
                    <h3>{{ __('pos.add_opening_balance_to_enable') }}</h3>
                </div>
            @endif
            <div class="disabled_div d-none" id="pos-restaurant-no-shift">
                <h3>{{ __('pos.open_shift_required_for_branch') }}</h3>
            </div>
            <div class="col-lg-8">
                <div class="sop-card pos-products-card card mb-0">
                    <div class="card-header p-2">
                        <div class="search-bar-left pos-search-bar">
                            <form>
                                <div class="row">
                                    <div class="col-md-6 pr-1">
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="ti ti-search"></i></span>
                                            </div>
                                            <input type="text" data-url="{{ route('search.products') }}"
                                                placeholder="{{ __('pos.search_product') }}"
                                                class="form-control pr-4 rounded-right searchproduct">
                                        </div>
                                    </div>

                                    <div class="col-md-6 pl-1">
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="ti ti-search"></i></span>
                                            </div>
                                            <input type="text" data-url="{{ route('search.products') }}"
                                                placeholder="{{ __('pos.search_barcode') }}"
                                                class="form-control pr-4 rounded-right barcodeScanner">
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <div class="right-content">
                            <div class="button-list b-bottom catgory-pad mb-3">
                                <div class="form-row m-0" id="categories-listing">
                                </div>
                            </div>
                            <div class="product-body-nop">
                                <div class="form-row" id="product-listing">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 ps-lg-0">
                <div class="card m-0 h-100 pos-cart-card pos-cart-panel">
                    <div class="card-header p-2">
                        <div class="row gy-2">
                            @php
                                $restaurantBranchIds = $restaurantBranchIds ?? [];
                                $defaultBranchId = '';
                                if (isset($branches) && $branches->count() === 1) {
                                    $defaultBranchId = $branches->keys()->first();
                                } elseif (isset($posPinUser) && !empty($posPinUser->pos_branch_id) && isset($branches) && $branches->has($posPinUser->pos_branch_id)) {
                                    $defaultBranchId = $posPinUser->pos_branch_id;
                                } elseif (isset($userOpenShift) && $userOpenShift && $userOpenShift->branch_id && isset($branches) && $branches->has($userOpenShift->branch_id)) {
                                    $defaultBranchId = $userOpenShift->branch_id;
                                }
                            @endphp
                            @if(!empty($posBranchAssignmentWarning))
                            <div class="col-12">
                                <div class="alert alert-warning py-2 mb-0 small">{{ $posBranchAssignmentWarning }}</div>
                            </div>
                            @elseif(isset($branches) && $branches->count() >= 1)
                            <div class="col-12">
                                <label class="form-label small text-muted mb-0">{{ __('Branch') }}@if(!empty($posBranchLocked)) <span class="text-muted">({{ __('Assigned') }})</span>@endif</label>
                                {{ Form::select('branch_id', $branches, $defaultBranchId, ['class' => 'form-control select branch_select', 'id' => 'branch_id', 'placeholder' => __('Select Branch'), 'readonly' => !empty($posBranchLocked), 'disabled' => !empty($posBranchLocked)]) }}
                                @if(!empty($posBranchLocked))<small class="text-muted d-block">{{ __('Assigned branch only') }}</small>@endif
                            </div>
                            @endif
                            <div id="pos-restaurant-options" class="col-12 row gy-1 {{ empty($restaurantBranchIds) ? 'd-none' : '' }}">
                                <div class="col-sm-6 pr-1">
                                    {{ Form::select('pos_order_type', ['dine_in' => __('pos.dine_in'), 'takeaway' => __('pos.takeaway'), 'delivery' => __('pos.delivery')], null, ['class' => 'form-control select', 'id' => 'pos_order_type', 'placeholder' => __('pos.select_order_type')]) }}
                                    {{ Form::hidden('pos_order_type_hidden', '', ['id' => 'pos_order_type_hidden']) }}
                                </div>
                                <div class="col-sm-6 pl-1">
                                    {{ Form::select('pos_table_id', [], null, ['class' => 'form-control select', 'id' => 'pos_table_id', 'placeholder' => __('Select Table')]) }}
                                    {{ Form::hidden('pos_table_id_hidden', '', ['id' => 'pos_table_id_hidden']) }}
                                </div>
                            </div>
                            <div class="col-sm-6 pr-1">
                                @php
                                    $customersForPos = $customersForPos ?? collect();
                                    $selCustomer = $customersForPos->firstWhere('name', $customer ?? 'Walk-in-customer') ?? $customersForPos->first();
                                @endphp
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
                            <div class="col-sm-6 pl-1">
                                <!-- {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label d-none d-sm-block']) }} -->
                                {{ Form::select('warehouse_id', $warehouses, $warehouseId, ['class' => 'form-control select warehouse_select ', 'id' => 'warehouse', 'required' => 'required', 'placeholder' => __('pos.select_warehouse') ]) }}
                                {{ Form::hidden('warehouse_name_hidden', '', ['id' => 'warehouse_name_hidden']) }}
                                {{ Form::hidden('quotation_id', $id, ['id' => 'quotation_id', 'class' => 'quotation']) }}
                            </div>
                        </div>
                    </div>
                    <div class="card-body carttable cart-product-list carttable-scroll" id="carthtml">
                        @php
                            $total = 0;
                            $netPrice = 0;
                            $discount = 0;
                        @endphp
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
                                <tbody id="tbody">
                                    @if (session($lastsegment) && !empty(session($lastsegment)) && count(session($lastsegment)) > 0)
                                        @foreach (session($lastsegment) as $id => $details)
                                            @php
                                                $product = \App\Models\ProductService::find($details['id']);
                                                $image_url =
                                                    !empty($product) && isset($product->pro_image)
                                                        ? $product->pro_image
                                                        : 'uploads/pro_image/';
                                                $total += $details['subtotal'];
                                                $netPrice += $details['netPrice'] ?? $details['subtotal'];
                                                $discount += $details['discount'] ?? 0;
                                            @endphp
                                            <tr data-product-id="{{ $id }}" id="product-id-{{ $id }}"
                                                data-name="{{ e($details['name']) }}" data-price="{{ $details['price'] }}"
                                                data-quantity="{{ $details['quantity'] }}" data-tax="{{ $details['tax'] ?? 0 }}"
                                                data-subtotal="{{ $details['subtotal'] }}" data-discount="{{ $details['discount'] ?? 0 }}"
                                                data-netprice="{{ $details['netPrice'] ?? $details['subtotal'] }}">
                                                <td class="cart-images">
                                                    <img alt="Image placeholder"
                                                        src="{{ asset(Storage::url('uploads/pro_image/' . $image_url)) }}"
                                                        class="card-image avatar rounded-circle-sale shadow hover-shadow-lg">
                                                </td>
                                                <td class="name">{{ $details['name'] }}</td>
                                                <td>
                                                    <span class="quantity buttons_added">
                                                        <input type="button" value="-" class="minus">
                                                        <input type="number" step="1" min="1"
                                                            max="" name="quantity"
                                                            title="{{ __('pos.quantity') }}" class="input-number"
                                                            data-url="{{ url('update-cart/') }}"
                                                            data-id="{{ $id }}" size="4"
                                                            value="{{ $details['quantity'] }}" placeholder="{{ __('pos.enter_quantity') }}">
                                                        <input type="button" value="+" class="plus">
                                                    </span>
                                                </td>
                                                <td>
                                                    @if (!empty($product->tax_id))
                                                        @php
                                                            $taxes = \Utility::tax($product->tax_id);
                                                        @endphp
                                                        @foreach ($taxes as $tax)
                                                            <span
                                                                class="badge bg-primary">{{ $tax->name . ' (' . $tax->rate . '%)' }}</span>
                                                            <br>
                                                        @endforeach
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td class="price text-right">
                                                    {{ Auth::user()->priceFormat($details['price']) }}</td>
                                                <td class="col-sm-3 mt-2">
                                                    <span
                                                        class="subtotal">{{ Auth::user()->priceFormat($details['subtotal']) }}</span>
                                                </td>
                                                <td class="col-sm-2 mt-2">
                                                <div class="action-btn">
                                                    <a href="#" class="btn btn-sm bg-danger bs-pass-para-pos"
                                                        data-confirm="{{ __('Are You Sure?') }}"
                                                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                        data-confirm-yes="delete-form-{{ $id }}"
                                                        title="{{ __('Delete') }}" data-id="{{ $id }}">
                                                        <i class="ti ti-trash text-white "
                                                            title="{{ __('Delete') }}"></i>
                                                    </a>
                                                    {!! Form::open(['method' => 'delete', 'url' => ['remove-from-cart'], 'id' => 'delete-form-' . $id]) !!}
                                                    <input type="hidden" name="session_key"
                                                        value="{{ $lastsegment }}">
                                                    <input type="hidden" name="id"
                                                        value="{{ $id }}">
                                                    {!! Form::close() !!}
                                                </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @else
                                        <tr class="text-center no-found">
                                            <td colspan="7">{{ __('pos.no_data_found') }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                        <div class="total-section pos-totals mt-3">
                            <div class="sub-total">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-3">
                                    <div class="sub-total-left flex-1" id="btn-pur">
                                        <div class="d-flex align-items-center gap-2 mb-3 discount-wrap">
                                            <span
                                                class="input-group-text bg-transparent">{{ \Auth::user()->currencySymbol() }}</span>
                                            {{ Form::number('discount', $discount, ['class' => ' form-control discount', 'required' => 'required', 'placeholder' => __('pos.discount')]) }}
                                            {{ Form::hidden('discount_hidden', $discount, ['id' => 'discount_hidden']) }}
                                        </div>
                                        <button type="button" id="pos-pay-btn" class="btn btn-primary rounded pos-pay-btn" data-ajax-popup="true"
                                            data-size="xl" data-align="centered" data-url="{{ route('pos.create') }}"
                                            data-title=""
                                            @if (session($lastsegment) && !empty(session($lastsegment)) && count(session($lastsegment)) > 0) @else disabled="disabled" @endif>
                                            {{ __('pos.pay') }}
                                        </button>
                                    </div>
                                    <div class="sub-total-right flex-1">
                                        <div class="d-flex align-items-center justify-content-sm-end mb-2">
                                            <p class="mb-0 text-dark">{{ __('pos.sub_total') }} :</p>
                                            <p class="mb-0 text-dark subtotal_price" id="displaytotal">
                                                {{ Auth::user()->priceFormat($total) }}</p>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-sm-end mb-3">
                                            <p class="mb-0 text-dark">{{ __('Total') }} :</p>
                                            <p class="mb-0 text-dark totalamount">
                                                {{ Auth::user()->priceFormat($netPrice) }}</p>
                                        </div>
                                        <div class="tab-content btn-empty d-flex justify-content-sm-end">
                                            <a href="#" class="btn btn-danger bs-pass-para-pos rounded m-0"
                                                data-toggle="tooltip" data-original-title="{{ __('pos.empty_cart') }}"
                                                data-confirm="{{ __('pos.are_you_sure') }}"
                                                data-text="{{ __('pos.confirm_undo') }}"
                                                data-confirm-yes="delete-form-emptycart">
                                                {{ __('pos.empty_cart') }}
                                            </a>
                                            {!! Form::open(['method' => 'post', 'url' => ['empty-cart'], 'id' => 'delete-form-emptycart']) !!}
                                            <input type="hidden" name="session_key" value="{{ $lastsegment }}"
                                                id="empty_cart">
                                            {!! Form::close() !!}
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
    </div>
        </div>{{-- /pos-retail-panel --}}

        <div id="pos-restaurant-panel" class="pos-mode-panel mt-2 position-relative" style="min-height:90vh">
            @if(!$userOpenShift)
                <div class="disabled_div" id="pos-restaurant-panel-no-opening-balance">
                    <h3>{{ __('pos.add_opening_balance_to_enable') }}</h3>
                </div>
            @endif
            <div class="disabled_div d-none" id="pos-restaurant-panel-no-shift">
                <h3>{{ __('pos.open_shift_and_balance') }}</h3>
                <p class="mb-0 mt-2 small">{{ __('pos.open_shift_click_above') }}</p>
            </div>
            @include('pos.restaurant')
        </div>{{-- /pos-restaurant-panel --}}
    </div>{{-- /pos-page --}}

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

    <div class="modal fade" id="commonModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true" style="z-index: 9999999;">
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
                        @if(!empty($posBranchLocked) && isset($branches) && $branches->count() === 1)
                            @php $onlyBranchId = $branches->keys()->first(); @endphp
                            <input type="hidden" name="branch_id" value="{{ $onlyBranchId }}">
                            <div class="mb-3">
                                <label class="form-label">{{ __('pos.branch') }}</label>
                                <div class="form-control-plaintext fw-semibold">{{ $branches[$onlyBranchId] ?? __('Assigned branch') }}</div>
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
                            <small class="text-muted">{{ __('Morning / Afternoon / Night — sales are tracked per shift.') }}</small>
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

    @include('pos.partials.variant-picker-modal')
    @include('pos.partials.warehouse-picker-modal')
    @include('partials.product-options-picker')

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
            var posPrintSource = 'restaurant';
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
    <script src="{{ asset('js/pos-modal-idle.js') }}"></script>

    <script>
        var POS_PENDING_KEY = 'pos_pending_sales';
        var POS_CACHE_PRODUCTS = 'pos_cache_products';
        var POS_CACHE_CATEGORIES = 'pos_cache_categories';
        var POS_PRODUCT_HTML_VER = 'pv14';
        var posSyncUrl = '{{ route("pos.sync") }}';
        var posEmptyCartUrl = '{{ route("warehouse-empty-cart") }}';
        var posRestaurantOrderUrl = '{{ route("pos.restaurant.order") }}';
        var posSearchProductsUrl = '{{ route("search.products") }}';
        var posCategoriesUrl = '{{ route("product.categories") }}';
        var posCategoriesJsonUrl = '{{ route("product.categories.json") }}';
        var sessionKey = '{{ $lastsegment ?? "pos" }}';
        var session_key = sessionKey;

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
        function posRoundMoney(n) {
            return Math.round((parseFloat(n) || 0) * 100) / 100;
        }
        /** Parse data-selected-options (handles HTML-entity-encoded JSON from blade esc()). */
        function posParseSelectedOptionsAttr(selAttr) {
            if (!selAttr || !String(selAttr).trim()) return null;
            var raw = String(selAttr).trim();
            try { return JSON.parse(raw); } catch (e1) {}
            try {
                var ta = document.createElement('textarea');
                ta.innerHTML = raw;
                var decoded = ta.value;
                if (decoded && decoded !== raw) {
                    return JSON.parse(decoded);
                }
            } catch (e2) {}
            try {
                return JSON.parse(raw.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&'));
            } catch (e3) {}
            return null;
        }
        /** Restaurant POS: option prices never affect cart money — main product price × qty only. */
        function posOptionsAmountFromSelected(selected, taxRate) {
            return 0;
        }
        function posResolveOptionsAmount($row, selected, taxRate) {
            return 0;
        }
        /** Round unit line (price+VAT) first, then × qty (options_amount ignored in restaurant POS) */
        function posLineSubtotal(price, taxRate, qty, priceType, unitSubtotal, optionsAmount) {
            qty = parseInt(qty, 10) || 0;
            if (qty <= 0) return 0;
            var unit;
            if (unitSubtotal != null && !isNaN(parseFloat(unitSubtotal)) && parseFloat(unitSubtotal) > 0) {
                unit = posRoundMoney(unitSubtotal);
            } else if ((priceType || '').toLowerCase() === 'inclusive' && (parseFloat(taxRate) || 0) > 0) {
                unit = posRoundMoney(price);
            } else {
                unit = posRoundMoney((parseFloat(price) || 0) * (1 + (parseFloat(taxRate) || 0) / 100));
            }
            return posRoundMoney(unit * qty);
        }
        function posGetCartFromDom() {
            var cart = {};
            $('#tbody tr[data-product-id]').each(function () {
                var $row = $(this);
                var id = $row.attr('data-product-id');
                var lineKey = $row.attr('data-cart-line-key') || String(id);
                var vId = parseInt($row.attr('data-product-variant-id'), 10);
                var qty = parseInt($row.find('input.input-number').val() || 1, 10);
                var price = parseFloat($row.attr('data-price')) || 0;
                var tax = parseFloat($row.attr('data-tax')) || 0;
                var priceType = ($row.attr('data-price-type') || 'exclusive').toLowerCase();
                var unitSub = parseFloat($row.attr('data-unit-subtotal'));
                var selRaw = $row.attr('data-selected-options') || '';
                var selOpts = posParseSelectedOptionsAttr(selRaw);
                var optionsAmount = posResolveOptionsAmount($row, selOpts, tax);
                var subtotal = posLineSubtotal(price, tax, qty, priceType, !isNaN(unitSub) ? unitSub : null, optionsAmount);
                var discount = parseFloat($row.attr('data-discount')) || 0;
                var netPrice = subtotal - discount;
                cart[lineKey] = {
                    id: parseInt(id, 10),
                    product_variant_id: (!isNaN(vId) && vId > 0) ? vId : null,
                    name: $row.attr('data-name') || '',
                    price: price,
                    quantity: qty,
                    tax: tax,
                    price_type: priceType,
                    unit_subtotal: !isNaN(unitSub) ? unitSub : null,
                    options_amount: optionsAmount,
                    subtotal: subtotal,
                    discount: discount,
                    netPrice: netPrice,
                    selected_options: selOpts,
                    selected_options_json: selOpts ? JSON.stringify(selOpts) : (selRaw || null)
                };
            });
            return cart;
        }
        function posLineDomId(product) {
            if (!product) return '';
            return String(product.cart_line_key || product.id || '').replace(/_/g, '-');
        }
        function posSaveOfflineSale() {
            var cart = posGetCartFromDom();
            if (Object.keys(cart).length === 0) return false;
            var pm = ($('#payment_method').length ? $('#payment_method').val() : null) || 'cash';
            var sale = {
                vc_name: $('#vc_name_hidden').val() || '',
                warehouse_name: $('#warehouse_name_hidden').val() || '',
                discount: $('#discount_hidden').val() || 0,
                payment_method: pm,
                pos_order_type: $('#pos_order_type_hidden').val() || '',
                pos_table_id: $('#pos_table_id_hidden').val() || '',
                pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                quotation_id: $('#quotation_id').val() || 0,
                date: new Date().toISOString().slice(0, 10),
                cart: cart
            };
            try {
                var pending = JSON.parse(localStorage.getItem(POS_PENDING_KEY) || '[]');
                pending.push(sale);
                localStorage.setItem(POS_PENDING_KEY, JSON.stringify(pending));
            } catch (e) { return false; }
            return true;
        }
        function posClearCartDom() {
            $('#tbody tr[data-product-id]').remove();
            $('#tbody').append('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
            $('#btn-pur button').attr('disabled', 'disabled');
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
                        var rows = '';
                        var subTotal = 0;
                        $('#tbody tr[data-product-id]').each(function () {
                            var name = $(this).attr('data-name') || '';
                            var qty = $(this).find('input.input-number').val() || 1;
                            var price = parseFloat($(this).attr('data-price')) || 0;
                            var tax = parseFloat($(this).attr('data-tax')) || 0;
                            var taxAmt = price * qty * tax / 100;
                            var subtotal = (price + price * tax / 100) * qty;
                            subTotal += subtotal;
                            rows += '<tr><td class="text-left">' + $('<div>').text(name).html() + '</td><td>' + qty + '</td><td class="text-right">' + (typeof addCommas === 'function' ? addCommas(price) : price) + '</td><td class="text-right">' + tax + '%</td><td class="text-right">' + (typeof addCommas === 'function' ? addCommas(taxAmt) : taxAmt) + '</td><td class="text-right">' + (typeof addCommas === 'function' ? addCommas(subtotal) : subtotal) + '</td></tr>';
                        });
                        var discount = parseFloat($('#discount_hidden').val()) || 0;
                        var total = Math.max(0, subTotal - discount);
                        var html = '<div class="card"><div class="card-body"><p class="text-muted small mb-3">{{ __("pos.offline_banner") }}</p><div class="table-responsive"><table class="table table-sm"><thead><tr><th class="text-left">{{ __("pos.items") }}</th><th>{{ __("pos.qty") }}</th><th class="text-right">{{ __("pos.price") }}</th><th class="text-right">{{ __("pos.tax") }}</th><th class="text-right">{{ __("pos.tax_amount") }}</th><th class="text-right">{{ __("pos.sub_total") }}</th></tr></thead><tbody>' + rows + '</tbody><tfoot><tr><td colspan="5" class="text-end">{{ __("pos.sub_total") }}</td><td class="text-right">' + (typeof addCommas === 'function' ? addCommas(subTotal) : subTotal) + '</td></tr><tr><td colspan="5" class="text-end">{{ __("pos.discount") }}</td><td class="text-right">' + (typeof addCommas === 'function' ? addCommas(discount) : discount) + '</td></tr><tr><td colspan="5" class="text-end"><strong>{{ __("pos.total") }}</strong></td><td class="text-right"><strong>' + (typeof addCommas === 'function' ? addCommas(total) : total) + '</strong></td></tr></tfoot></table></div><div class="d-flex align-items-center mt-4"><select class="form-control" id="payment_method"><option value="cash">{{ __("pos.payment_cash") }}</option><option value="visa">{{ __("pos.payment_visa") }}</option><option value="mpesa">{{ __("pos.payment_mpesa") }}</option><option value="bank_transfer">{{ __("pos.payment_bank_transfer") }}</option><option value="cheque">{{ __("pos.payment_cheque") }}</option></select><button type="button" id="pos-offline-pay-confirm" class="btn btn-success ms-2">{{ __("pos.pay_button") }}</button></div></div></div>';
                        $('#commonModal .modal-title').html('');
                        $('#commonModal .modal-dialog').addClass('modal-xl');
                        $('#commonModal .body').html(html);
                        $('#commonModal').modal('show');
                        return false;
                    }
                }, true);
            }
            $(document).on('click', '#pos-offline-pay-confirm', function () {
                if (posSaveOfflineSale()) {
                    posClearCartDom();
                    posUpdatePendingBadge();
                    $('#commonModal').modal('hide');
                    if (typeof show_toastr === 'function') show_toastr('success', '{{ __("pos.saved_for_sync") }}');
                } else {
                    if (typeof show_toastr === 'function') show_toastr('warning', '{{ __("pos.no_data_found") }}');
                }
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

            function syncRestaurantWarehouseSelection(wh) {
                wh = wh ? String(wh) : '';
                $('#pos_restaurant_warehouse_hidden').val(wh);
                if ($('#warehouse').length && wh) {
                    $('#warehouse').val(wh);
                }
                $('#warehouse_name_hidden').val(wh);
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

            $(function () {
                getProductCategories();
                loadRestaurantCategories(true);
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
                searchProducts(url, '', '0',ware_id, 'sku');
            }

            if ($('.searchproduct').length > 0) {
                var url = $('.searchproduct').data('url');
                var ware_id = $( "#warehouse" ).val();
                searchProducts(url, '', '0', ware_id, 'name');
            }

            $( '#warehouse' ).change(function() {
                var ware_id = $( "#warehouse" ).val();
                searchProducts(posSearchProductsUrl, '', '0', ware_id, 'name');
            });

            var restaurantBranchIds = @json($restaurantBranchIds ?? []);
            var posRestaurantLayoutUrl = '{{ route("pos.restaurant.layout") }}';
            var posRestaurantDeliveryTakeawayOrdersUrl = '{{ route("pos.restaurant.delivery-takeaway-orders") }}';
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
                if (!branchId) {
                    return;
                }
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
                        var canChange = {{ $canChangeShift ? 'true' : 'false' }};
                        var userShiftByBranch = @json($userOpenShiftByBranch);
                        var shiftIdToUse = currentUserShiftId || (userShiftByBranch[branchId] || null);
                        $('#pos_shift_id_hidden').val(shiftIdToUse || '');
                        if (!canChange && branchId) {
                            var myShiftId = shiftIdToUse;
                            if (!myShiftId) {
                                $('#pos-restaurant-no-shift').removeClass('d-none');
                                $('#pos-restaurant-panel-no-shift').removeClass('d-none');
                            } else {
                                $('#pos-restaurant-no-shift').addClass('d-none');
                                $('#pos-restaurant-panel-no-shift').addClass('d-none');
                            }
                        } else {
                            $('#pos-restaurant-no-shift').addClass('d-none');
                            $('#pos-restaurant-panel-no-shift').addClass('d-none');
                        }
                        var endShiftBtn = $('#pos-end-shift-btn');
                        var shiftIdForEnd = shiftIdToUse;
                        var hasClosingBalance = {{ $has_closing_balance ? 'true' : 'false' }};
                        if (shiftIdForEnd && hasClosingBalance) {
                            endShiftBtn.attr('href', '{{ url("pos-shift") }}/' + shiftIdForEnd + '/close').removeClass('d-none');
                        } else {
                            endShiftBtn.addClass('d-none').attr('href', '#');
                        }
                        var orderType = $('#pos-restaurant-order-type').val() || 'dine_in';
                        if ((orderType === 'delivery' || orderType === 'takeaway') && shiftIdToUse && branchId && typeof loadRestaurantDeliveryTakeawayCustomers === 'function') {
                            loadRestaurantDeliveryTakeawayCustomers(branchId, shiftIdToUse, orderType);
                        }
                        if (typeof loadRestaurantTables === 'function') {
                            loadRestaurantTables(branchId);
                        }
                    },
                    error: function () {}
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
                    $('#pos-restaurant-order-right').removeClass('d-none');
                    var sk = $( "#empty_cart" ).val() || sessionKey;
                    var emptyData = { session_key: sk };
                    if (sk === 'pos-restaurant' && typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) { emptyData.cart_key = posRestaurantCartKey; }
                    $.ajax({
                        type: 'POST',
                        url: posEmptyCartUrl,
                        data: emptyData,
                        success: function () {},
                        complete: function () {
                            $.get('{{ route("pos.warehouses.by.branch") }}', { branch_id: branchId }, function (data) {
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
                                    syncRestaurantWarehouseSelection(wh);
                                    resetRestaurantCategories(true);
                                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                                }
                                $('#pos-restaurant-warehouse-hint').text(ids.length ? '' : '{{ __("No warehouses for this branch") }}').toggle(ids.length === 0);
                            });
                            $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                            updateRestaurantCartTotals(0);
                            $('#pos-pay-btn-restaurant').prop('disabled', false);
                        }
                    });
                } else {
                    $('#pos-restaurant-warehouse').empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                    $('#pos_restaurant_warehouse_hidden').val('');
                    $('#pos-restaurant-order').addClass('d-none');
                    $('#pos-restaurant-order-right').addClass('d-none');
                    $('#pos-restaurant-placeholder').removeClass('d-none');
                    $('#pos-table-grid').html('<p class="text-muted small mb-0">{{ __("pos.select_branch_first") }}</p>');
                    loadBranchShifts(null);
                    $('#pos-restaurant-no-shift').addClass('d-none');
                    $('#pos-restaurant-panel-no-shift').addClass('d-none');
                    $('#pos-end-shift-btn').addClass('d-none').attr('href', '#');
                }
            });

            $('#pos-restaurant-warehouse').on('change', function () {
                var wh = $(this).val();
                syncRestaurantWarehouseSelection(wh || '');
                if (wh) {
                    resetRestaurantCategories(true);
                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                }
            });

            function getRestaurantSubtotalStandalone() {
                var fromData = parseFloat($('#pos-restaurant-total').data('subtotal'));
                if (!isNaN(fromData) && fromData >= 0) return fromData;
                var raw = $('#pos-restaurant-subtotal').text().replace(/,/g, '').replace(/[^\d.-]/g, '');
                var fromDisplay = parseFloat(raw);
                if (!isNaN(fromDisplay) && fromDisplay >= 0) return fromDisplay;
                var sum = 0;
                $('#tbody-restaurant [data-product-id]').each(function() { sum += parseFloat($(this).attr('data-subtotal')) || 0; });
                return sum;
            }
            function updateRestaurantCartTotals(subtotal) {
                if (!$('#pos-restaurant-subtotal').length) return;
                var type = ($('#pos_discount_type_restaurant').val() || 'fixed');
                var val = parseFloat($('#discount_restaurant').val() || 0) || 0;
                var amount = type === 'percentage' ? (Math.min(100, Math.max(0, val)) / 100 * subtotal) : Math.max(0, val);
                $('#pos-restaurant-subtotal').text(typeof addCommas === 'function' ? addCommas(subtotal) : subtotal);
                $('#pos-restaurant-total').data('subtotal', subtotal);
                $('#discount_hidden_restaurant').val(amount.toFixed(2));
                var totalVal = subtotal <= 0 ? 0 : Math.max(0, subtotal - amount);
                $('#pos-restaurant-total').text(typeof addCommas === 'function' ? addCommas(totalVal) : totalVal);
            }
            $(document).on('keyup input', '#discount_restaurant', function () {
                var type = ($('#pos_discount_type_restaurant').val() || 'fixed');
                if (type === 'percentage') { var v = parseFloat($(this).val()) || 0; if (v > 100) $(this).val(100); }
                var sub = parseFloat($('#pos-restaurant-total').data('subtotal')) || getRestaurantSubtotalStandalone();
                updateRestaurantCartTotals(sub);
            });
            $(document).on('change', '#pos_discount_type_restaurant', function () {
                var type = $(this).val();
                var $wrap = $('.discount-wrap-restaurant').first();
                var sym = $wrap.data('currency-symbol') || ($wrap.find('.pos-discount-prefix-restaurant').text() || '').trim();
                if (sym === '%') sym = $wrap.data('currency-symbol') || '';
                $wrap.find('.pos-discount-prefix-restaurant').text(type === 'percentage' ? '%' : sym);
                $wrap.find('.pos-discount-value-restaurant').attr('max', type === 'percentage' ? '100' : '');
                var sub = getRestaurantSubtotalStandalone();
                updateRestaurantCartTotals(sub);
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
                            syncRestaurantWarehouseSelection(wh);
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
                // Waiter only on in-use tables after order was sent to production (API already nulls when hidden).
                var showWaiter = t.status !== 'available' && !!t.has_sent_to_production && t.waiter_name && String(t.waiter_name).trim();
                var waiter = showWaiter ? posEscapeHtml(t.waiter_name) : '—';
                var guestsVal = (t.current_guests != null && t.current_guests !== '') ? String(t.current_guests) : '—';
                var amount = (t.cart_total_formatted && String(t.cart_total_formatted).trim()) ? posEscapeHtml(t.cart_total_formatted) : '—';
                var waiterIdAttr = showWaiter ? (t.waiter_user_id || '') : '';
                var waiterNameAttr = showWaiter ? posEscapeHtml(t.waiter_name || '') : '';
                return '<button type="button" class="btn ' + statusClass + ' btn-sm pos-table-btn pos-table-card" data-id="' + t.id + '" data-name="' + posEscapeHtml(t.name || '') + '" data-status="' + (t.status || '') + '" data-waiter-id="' + waiterIdAttr + '" data-waiter-name="' + waiterNameAttr + '" data-customer-name="' + posEscapeHtml(t.customer_name || '') + '">' +
                    '<span class="pos-table-card__name">' + name + '</span>' +
                    '<span class="pos-table-card__row"><span class="pos-table-card__label">{{ __("pos.guest_name") }}</span><span class="pos-table-card__value">' + customer + '</span></span>' +
                    '<span class="pos-table-card__row"><span class="pos-table-card__label">{{ __("pos.waiter_user") }}</span><span class="pos-table-card__value">' + waiter + '</span></span>' +
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
            function loadRestaurantDeliveryTakeawayCustomers(branchId, shiftId, orderType) {
                if (!branchId || !shiftId || (orderType !== 'delivery' && orderType !== 'takeaway')) {
                    $('#pos-customer-grid').html('<p class="text-muted small mb-0">{{ __("pos.select_branch_first") }}</p>');
                    return;
                }
                $.get(posRestaurantDeliveryTakeawayOrdersUrl, { branch_id: branchId, shift_id: shiftId, order_type: orderType }, function (res) {
                    var orders = res.orders || [];
                    var html = '';
                    orders.forEach(function (o) {
                        var label = (o.display_label || o.guest_name || o.guest_phone || ('{{ __("pos.customer") }} #' + o.id)).replace(/</g, '&lt;');
                        html += '<button type="button" class="btn btn-outline-primary btn-sm pos-customer-btn" data-pos-id="' + o.id + '" data-name="' + (o.guest_name || '').replace(/"/g, '&quot;') + '" data-email="' + (o.guest_email || '').replace(/"/g, '&quot;') + '" data-phone="' + (o.guest_phone || '').replace(/"/g, '&quot;') + '">' + label + (o.items_count ? ' <span class="badge bg-secondary">' + o.items_count + '</span>' : '') + '</button>';
                    });
                    $('#pos-customer-grid').html(html || '<p class="text-muted small mb-0">{{ __("pos.no_data_found") }}</p>');
                }).fail(function () {
                    $('#pos-customer-grid').html('<p class="text-muted small mb-0">{{ __("pos.error") }}</p>');
                });
            }
            var posTableUpdateStatusUrl = '{{ url("pos-table") }}';
            var posDefaultWaiterUserId = '{{ isset($posPinUser) ? (int) $posPinUser->id : "" }}';
            $(document).on('click', '.pos-table-btn', function () {
                var id = $(this).data('id');
                var name = $(this).data('name');
                var status = $(this).data('status');
                if (status === 'available') {
                    $('#pos-open-table-id').val(id);
                    $('#pos-open-table-name').val(name || ('Table ' + id));
                    $('#pos-open-table-guests').val('');
                    $('#pos-open-table-customer-name').val('');
                    if ($('#pos-open-table-waiter').length) {
                        $('#pos-open-table-waiter').val(posDefaultWaiterUserId || $('#pos-open-table-waiter option:first').val());
                    }
                    $('#posOpenTableModal').modal('show');
                    $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                    return;
                }
                selectRestaurantTable(id, name, status, {
                    waiter_user_id: $(this).attr('data-waiter-id') || '',
                    waiter_name: $(this).attr('data-waiter-name') || '',
                    customer_name: $(this).attr('data-customer-name') || ''
                });
            });
            $(document).on('click', '#pos-open-table-confirm', function () {
                var id = $('#pos-open-table-id').val();
                var name = $('#pos-open-table-name').val();
                var guests = $('#pos-open-table-guests').val();
                if (!id) return;
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.open_shift_required_to_open_table") }}', 'error');
                    return;
                }
                var data = { status: 'occupied', shift_id: shiftId, _token: $('meta[name="csrf-token"]').attr('content') };
                if (guests !== '' && guests !== null) data.guests_count = parseInt(guests, 10) || 0;
                var customerName = $('#pos-open-table-customer-name').val();
                if (customerName && customerName.trim()) data.customer_name = customerName.trim();
                var waiterUserId = $('#pos-open-table-waiter').val();
                if (waiterUserId) data.waiter_user_id = waiterUserId;
                var waiterName = $('#pos-open-table-waiter option:selected').text() || '';
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/status',
                    method: 'PATCH',
                    data: data,
                    success: function (res) {
                        $('#posOpenTableModal').modal('hide');
                        selectRestaurantTable(id, name, 'occupied', {
                            waiter_user_id: (res && res.waiter_user_id) ? res.waiter_user_id : waiterUserId,
                            waiter_name: (res && res.waiter_name) ? res.waiter_name : waiterName,
                            customer_name: customerName || ''
                        });
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });
            $(document).on('click', '#pos-close-table-btn', function () {
                var id = $(this).data('table-id');
                if (!id) return;
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.open_shift_required_to_open_table") }}', 'error');
                    return;
                }
                if (!confirm('{{ __("pos.are_you_sure") }} {{ __("pos.close_table") }}?')) return;
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/close',
                    method: 'POST',
                    data: { shift_id: shiftId, _token: $('meta[name="csrf-token"]').attr('content') },
                    success: function () {
                        show_toastr('{{ __("Success") }}', '{{ __("pos.table_closed") }}', 'success');
                        $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                        $('#pos-selected-table').text('—');
                        $('#pos_table_id_hidden').val('');
                        posCurrentTableWaiter = { id: '', name: '' };
                        setRestaurantWaiterDisplay('');
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });
            var posRestaurantCartKey = ''; // tableId_shiftId or delivery_shiftId / takeaway_shiftId
            var posCurrentTableWaiter = { id: '', name: '' };
            function setRestaurantWaiterDisplay(waiterName, customerName) {
                var $waiter = $('#pos-restaurant-waiter-name');
                if ($waiter.length) {
                    var name = (waiterName && String(waiterName).trim()) ? String(waiterName).trim() : '';
                    if (name) {
                        $waiter.text('{{ __("pos.waiter_user") }}: ' + name).removeClass('d-none');
                    } else {
                        $waiter.text('').addClass('d-none');
                    }
                }
                if (customerName !== undefined && customerName !== null && String(customerName).trim() !== '') {
                    var $custName = $('#pos-restaurant-customer-name');
                    if ($custName.length && (!$custName.text() || $custName.text() === '{{ __("Walk-in-customer") }}')) {
                        $custName.text(String(customerName).trim());
                    }
                }
            }
            function selectRestaurantTable(id, name, status, meta) {
                meta = meta || {};
                $('#pos_restaurant_table_id').val(id);
                $('#pos_restaurant_table_name').val(name);
                $('#pos_table_id_hidden').val(id);
                $('#pos_order_type_hidden').val('dine_in');
                $('#pos-selected-table').text(name || ('Table ' + id));
                posCurrentTableWaiter = {
                    id: meta.waiter_user_id || '',
                    name: meta.waiter_name || ''
                };
                setRestaurantWaiterDisplay(posCurrentTableWaiter.name, meta.customer_name || '');
                var $closeBtn = $('#pos-close-table-btn');
                $closeBtn.data('table-id', id).data('table-status', status || '');
                $closeBtn.toggleClass('d-none', (status || '') !== 'occupied');
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (id && (branchId || shiftId)) {
                    loadRestaurantOrder(id, shiftId, branchId, 'dine_in');
                } else {
                    $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                    updateRestaurantCartTotals(0);
                    $('#pos-pay-btn-restaurant').prop('disabled', true);
                }
                posRestaurantToggleTableGridPanel(false);
            }
            var posStatusLabels = { 'pending': '{{ __("pos.status_pending") }}', 'in_preparation': '{{ __("pos.status_in_preparation") }}', 'ready': '{{ __("pos.status_ready") }}', 'served': '{{ __("pos.status_served") }}', 'cancelled': '{{ __("pos.status_cancelled") }}', 'not_sent': '{{ __("pos.status_not_sent") }}' };
            var canShowVoid = {{ ($canShowVoid ?? false) ? 'true' : 'false' }};
            var canVoidDirectly = {{ ($canVoidDirectly ?? false) ? 'true' : 'false' }};
            var canDeleteSent = {{ ($canDeleteSent ?? false) ? 'true' : 'false' }};
            var posRestaurantVoidLineUrl = '{{ route("pos.restaurant.void-line", ["line" => "__LINE__"]) }}';
            var posRestaurantDeleteLinesUrl = '{{ route("pos.restaurant.delete-lines") }}';
            var posDeleteSelectMode = false;
            function buildRestaurantCartRow(it, idx, opts) {
                opts = opts || {};
                // Restaurant POS: never bill option prices — keep selected options for display/stock only.
                var optsAmt = 0;
                var sub = opts.sub != null ? opts.sub : posLineSubtotal(it.price, it.tax, it.quantity || 0, it.price_type, it.unit_subtotal, 0);
                var taxDisplay = (it.tax && it.tax !== 0) ? (it.tax + '%') : '-';
                var statusText = posStatusLabels[it.status] || it.status;
                var statusRowClass = { 'pending': 'pos-cart-row-sent', 'not_sent': '', 'in_preparation': 'pos-cart-row-preparation', 'ready': 'pos-cart-row-ready', 'served': 'pos-cart-row-ready', 'cancelled': 'pos-cart-row-cancelled' };
                var rowClass = statusRowClass[it.status] || '';
                var isVoided = it.status === 'cancelled' || it.voided_at;
                if (isVoided) {
                    rowClass += ' pos-cart-row--voided';
                }
                var sentClass = it.is_sent ? 'pos-cart-row--sent' : 'pos-cart-row--draft';
                var esc = function (s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
                var priceFmt = typeof addCommas === 'function' ? addCommas(it.price) : it.price;
                var subFmt = typeof addCommas === 'function' ? addCommas(sub) : sub;
                var qty = it.quantity || 0;
                var cartKey = it.cart_line_key || it.id;
                // Keep row ids aligned with add-to-cart / posLineDomId so qty updates can target the correct line.
                var rowId = it.line_id
                    ? ('product-id-r-line-' + it.line_id)
                    : ('product-id-r-' + String(cartKey).replace(/_/g, '-'));
                var note = String(it.description || '').trim();
                var selOptsRaw = '';
                if (it.selected_options_json) {
                    selOptsRaw = typeof it.selected_options_json === 'string'
                        ? it.selected_options_json
                        : JSON.stringify(it.selected_options_json);
                } else if (it.selected_options) {
                    selOptsRaw = typeof it.selected_options === 'string'
                        ? it.selected_options
                        : JSON.stringify(it.selected_options);
                }
                var optionLabels = Array.isArray(it.option_labels) ? it.option_labels.slice() : [];
                if (!optionLabels.length && selOptsRaw) {
                    try {
                        var parsedOpts = typeof selOptsRaw === 'string' ? JSON.parse(selOptsRaw) : selOptsRaw;
                        if (Array.isArray(parsedOpts)) {
                            parsedOpts.forEach(function (opt) {
                                if (opt && opt.name) optionLabels.push(String(opt.name));
                            });
                        }
                    } catch (e) {}
                }
                var lineName = optionLabels.length
                    ? (String(it.product_name || '').trim() || String(it.name || '').split(' — ')[0].trim())
                    : (String(it.name || '').trim() || ('{{ __("Product") }} #' + (it.id || '')));
                if (!lineName) lineName = '{{ __("Product") }} #' + (it.id || '');
                var unitSubAttr = it.unit_subtotal != null
                    ? it.unit_subtotal
                    : (qty > 0 ? posRoundMoney(Math.max(0, sub - optsAmt) / qty) : '');
                var html = '<tr class="pos-cart-row-compact ' + rowClass + ' ' + sentClass + '" data-product-id="' + it.id + '" data-line-id="' + (it.line_id || '') + '" data-cart-line-key="' + esc(cartKey) + '" data-product-variant-id="' + (it.product_variant_id || '') + '" data-is-sent="' + (it.is_sent ? '1' : '0') + '" data-voided="' + (isVoided ? '1' : '0') + '" data-status="' + esc(it.status || '') + '" id="' + rowId + '" data-name="' + esc(lineName) + '" data-price="' + it.price + '" data-price-type="' + esc(it.price_type || 'exclusive') + '" data-quantity="' + qty + '" data-tax="' + (it.tax || 0) + '" data-subtotal="' + sub + '" data-unit-subtotal="' + unitSubAttr + '" data-options-amount="' + optsAmt + '" data-discount="0" data-print-zone="' + esc(it.print_zone) + '" data-printer-id="' + (it.pos_printer_id || '') + '" data-printer-name="' + esc(it.printer_name) + '" data-warehouse-id="' + (it.warehouse_id || '') + '" data-selected-options="' + esc(selOptsRaw) + '" data-option-labels="' + esc(JSON.stringify(optionLabels)) + '">';
                html += '<td class="pos-cart-line-cell p-0 border-0"><div class="pos-cart-line"><div class="pos-cart-line__row">';
                html += '<div class="pos-cart-line__main min-w-0">';
                html += '<span class="pos-cart-line__name name" title="' + esc(lineName) + '">' + esc(lineName) + '</span>';
                if (optionLabels.length) {
                    html += '<span class="pos-cart-line__options">';
                    optionLabels.forEach(function (label) {
                        html += '<span class="pos-cart-line__option">' + esc(label) + '</span>';
                    });
                    html += '</span>';
                }
                if (it.warehouse_name) {
                    html += '<span class="pos-cart-line__warehouse text-muted small d-block">' + esc(it.warehouse_name) + '</span>';
                }
                html += '<span class="pos-cart-line__badge badge pos-line-status">' + esc(statusText) + '</span>';
                var waiterLabel = (it.waiter_name && String(it.waiter_name).trim()) ? String(it.waiter_name).trim() : (posCurrentTableWaiter.name || '');
                if (waiterLabel) {
                    html += '<span class="pos-cart-line__waiter text-muted small d-block">' + esc('{{ __("pos.waiter_user") }}') + ': ' + esc(waiterLabel) + '</span>';
                }
                html += '</div>';
                if (it.is_sent) {
                    html += '<span class="pos-cart-line__qty-label">' + qty + '×</span>';
                } else {
                    html += '<span class="quantity buttons_added pos-cart-line__qty"><input type="button" value="-" class="minus" aria-label="-"><input type="number" step="1" min="1" name="quantity" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + esc(cartKey) + '" value="' + qty + '"><input type="button" value="+" class="plus" aria-label="+"></span>';
                }
                html += '<span class="pos-cart-line__total subtotal">' + subFmt + '</span><span class="d-none price">' + priceFmt + '</span><span class="d-none tax">' + taxDisplay + '</span>';
                html += '<div class="pos-cart-line__action action-btn">';
                if (isVoided) {
                    html += '<span class="badge pos-cart-line__void-badge">{{ __("pos.voided") }}</span>';
                } else if (it.is_sent) {
                    if (canShowVoid && it.line_id) {
                        html += '<button type="button" class="btn btn-warning btn-sm pos-void-line pos-cart-line__void-btn" data-line-id="' + it.line_id + '" data-name="' + esc(lineName) + '" title="{{ __("pos.void") }}">{{ __("pos.void") }}</button>';
                    }
                } else {
                    var delId = 'delete-form-r-' + cartKey + '-' + idx;
                    html += '<a href="#" class="btn btn-danger btn-sm pos-cart-line__del-btn bs-pass-para-pos pos-remove-from-cart" data-confirm="{{ __("Are You Sure?") }}" data-text="{{ __("This action can not be undone. Do you want to continue?") }}" data-confirm-yes="' + delId + '" title="{{ __("Delete") }}" data-id="' + esc(cartKey) + '"><i class="ti ti-trash"></i></a>';
                    html += '<form method="post" action="{{ url("remove-from-cart") }}" id="' + delId + '" class="d-none"><input name="_method" type="hidden" value="DELETE"><input name="_token" type="hidden" value="{{ csrf_token() }}"><input type="hidden" name="session_key" value="pos-restaurant"><input type="hidden" name="id" value="' + esc(cartKey) + '">';
                    if (opts.sessionKeyVal) {
                        html += '<input type="hidden" name="cart_key" value="' + esc(opts.sessionKeyVal) + '">';
                    }
                    html += '</form>';
                }
                html += '</div></div>';
                if (it.is_sent && note) {
                    html += '<div class="pos-cart-line__note notes pos-item-note-readonly" title="' + esc(note) + '">' + esc(note) + '</div>';
                } else if (!it.is_sent && !isVoided) {
                    html += '<textarea class="form-control form-control-sm notes pos-item-notes pos-cart-line__note-input" placeholder="{{ __("pos.note_placeholder") }}" maxlength="200" rows="1" title="{{ __("Note") }}">' + esc(note) + '</textarea>';
                }
                html += '</div></td></tr>';
                return html;
            }
            function collectRestaurantCartNotes() {
                var notes = {};
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var $row = $(this);
                    if ($row.attr('data-is-sent') === '1') return;
                    var key = String($row.attr('data-cart-line-key') || $row.find('.input-number').data('id') || $row.attr('data-product-id') || '');
                    var $n = $row.find('.notes');
                    var note = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    if (key) notes[key] = note;
                    var pid = String($row.attr('data-product-id') || '');
                    if (pid) notes['pid:' + pid] = note;
                });
                return notes;
            }
            function applyRestaurantCartNotes(list, notes) {
                if (!list || !notes) return list;
                list.forEach(function (it) {
                    if (it.is_sent) return;
                    var key = String(it.cart_line_key || it.id || '');
                    var fromKey = notes[key];
                    var fromPid = notes['pid:' + String(it.id || '')];
                    var merged = (fromKey !== undefined && fromKey !== '') ? fromKey : ((fromPid !== undefined && fromPid !== '') ? fromPid : (it.description || ''));
                    if (merged) it.description = merged;
                });
                return list;
            }
            function persistRestaurantLineNote($row) {
                if (!$row || !$row.length || $row.attr('data-is-sent') === '1') return;
                var $n = $row.find('.notes');
                if (!$n.length || !($n.is('input') || $n.is('textarea'))) return;
                var note = ($n.val() || '').trim();
                var lineKey = String($row.attr('data-cart-line-key') || $row.find('.input-number').data('id') || $row.attr('data-product-id') || '');
                var qty = parseInt($row.find('input[name="quantity"]').val() || $row.attr('data-quantity') || 1, 10) || 1;
                if (!lineKey) return;
                var payload = {
                    id: lineKey,
                    quantity: qty,
                    description: note,
                    session_key: 'pos-restaurant',
                    _token: $('meta[name="csrf-token"]').attr('content')
                };
                if (posRestaurantCartKey) payload.cart_key = posRestaurantCartKey;
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var orderType = $('#pos-restaurant-order-type').val() || '';
                if (tableId) payload.table_id = tableId;
                if (shiftId) payload.shift_id = shiftId;
                if (orderType) payload.order_type = orderType;
                $.ajax({
                    url: '{{ url("update-cart") }}',
                    method: 'PATCH',
                    data: payload
                });
            }
            $(document).on('change blur', '#tbody-restaurant .pos-item-notes', function () {
                persistRestaurantLineNote($(this).closest('tr'));
            });
            function posRestaurantServerSubtotal(it, qty) {
                // Trust the server line subtotal (VAT-inclusive money computed server-side);
                // fall back to the browser calculation only when the server sent none.
                var serverSub = parseFloat(it && it.subtotal);
                var q = parseInt(qty, 10) || 0;
                if (!isNaN(serverSub) && serverSub >= 0 && q > 0) {
                    return posRoundMoney(serverSub);
                }
                return posLineSubtotal(parseFloat(it.price) || 0, parseFloat(it.tax) || 0, q, it.price_type, it.unit_subtotal, 0);
            }
            function loadRestaurantOrder(tableId, shiftId, branchId, orderType, posId) {
                var preservedNotes = collectRestaurantCartNotes();
                var params = {};
                if (tableId && (branchId || shiftId)) {
                    params.table_id = tableId;
                    if (branchId) params.branch_id = branchId;
                    if (shiftId) params.shift_id = shiftId;
                    if (posId) params.pos_id = posId;
                } else if (orderType && (orderType === 'delivery' || orderType === 'takeaway') && shiftId && branchId) {
                    params.order_type = orderType;
                    params.shift_id = shiftId;
                    params.branch_id = branchId;
                    if (posId) params.pos_id = posId;
                } else if (!tableId) {
                    return;
                }
                $.get(posRestaurantOrderUrl, params, function (res) {
                    if (res.shift_id) $('#pos_shift_id_hidden').val(res.shift_id);
                    posRestaurantCartKey = res.session_cart_key || (tableId ? (tableId + '_' + (res.shift_id || shiftId)) : '');
                    if (res.pos_id) {
                        $('#pos_restaurant_pos_id').val(res.pos_id || '');
                    }
                    posRenderCheckSelector(res.checks || [], res.pos_id);
                    var orderLines = res.order_lines || [];
                    var sessionItems = res.session_items || [];
                    var list = [];
                    orderLines.forEach(function (it) {
                        var price = parseFloat(it.price) || 0;
                        var tax = parseFloat(it.tax) || 0;
                        var qty = (it.quantity || 0);
                        var sub = posRestaurantServerSubtotal(it, qty);
                        list.push({ line_id: it.line_id, id: it.id, name: it.name, quantity: qty, price: price, tax: tax, subtotal: sub, options_amount: 0, unit_subtotal: it.unit_subtotal != null ? parseFloat(it.unit_subtotal) : (qty > 0 ? posRoundMoney(sub / qty) : null), description: (it.description || '').trim(), print_zone: it.print_zone || '', pos_printer_id: it.pos_printer_id || null, printer_name: it.printer_name || '', pro_image: it.pro_image || 'default.png', status: it.status || 'pending', is_sent: !!it.is_sent, voided_at: it.voided_at || null, product_variant_id: it.product_variant_id || null, cart_line_key: it.cart_line_key || it.id, waiter_name: it.waiter_name || res.waiter_name || '', waiter_user_id: it.waiter_user_id || res.waiter_user_id || '', selected_options: it.selected_options || null, selected_options_json: it.selected_options_json || null });
                    });
                    sessionItems.forEach(function (it) {
                        var id = it.id;
                        var qty = (it.quantity || 0);
                        var price = parseFloat(it.price) || 0;
                        var tax = parseFloat(it.tax) || 0;
                        // Server subtotal wins; product price is the fallback (option money never billed).
                        var sub = posRestaurantServerSubtotal(it, qty);
                        var optsAmt = 0;
                        var unitSub = it.unit_subtotal != null
                            ? parseFloat(it.unit_subtotal)
                            : (qty > 0 ? posRoundMoney(sub / qty) : null);
                        list.push({ line_id: null, id: id, name: it.name || '', quantity: qty, price: price, tax: tax, subtotal: sub, unit_subtotal: unitSub, options_amount: optsAmt, description: (it.description || '').trim(), print_zone: (it.print_zone || ''), pos_printer_id: it.pos_printer_id || null, printer_name: (it.printer_name || ''), pro_image: (it.pro_image || 'default.png'), status: 'not_sent', is_sent: false, product_variant_id: it.product_variant_id || null, cart_line_key: it.cart_line_key || id, waiter_name: it.waiter_name || res.waiter_name || posCurrentTableWaiter.name || '', waiter_user_id: it.waiter_user_id || res.waiter_user_id || posCurrentTableWaiter.id || '', selected_options: it.selected_options || null, selected_options_json: it.selected_options_json || null });
                    });
                    if (res.waiter_name || res.waiter_user_id) {
                        posCurrentTableWaiter = {
                            id: res.waiter_user_id || posCurrentTableWaiter.id || '',
                            name: res.waiter_name || posCurrentTableWaiter.name || ''
                        };
                        setRestaurantWaiterDisplay(posCurrentTableWaiter.name, res.customer_name || '');
                    }
                    applyRestaurantCartNotes(list, preservedNotes);
                    if (res.order_type && $('#pos-restaurant-order-type').length) {
                        $('#pos-restaurant-order-type').val(res.order_type);
                    }
                    // Don't wipe guest fields the cashier is typing (poll/reload can return empty guest_*).
                    (function setGuestFieldsFromOrder(res) {
                        function setGuest(selector, value) {
                            var $el = $(selector);
                            if (!$el.length || value === undefined) return;
                            if ($el.is(':focus')) return;
                            var incoming = (value == null) ? '' : String(value);
                            var current = ($el.val() || '').trim();
                            if (incoming.trim() === '' && current !== '') return;
                            $el.val(incoming);
                        }
                        setGuest('#pos-guest-name', res.guest_name);
                        setGuest('#pos-guest-email', res.guest_email);
                        setGuest('#pos-guest-phone', res.guest_phone);
                    })(res);
                    var tbody = $('#tbody-restaurant');
                    var total = 0;
                    var sessionKeyVal = posRestaurantCartKey;
                    if (list.length === 0) {
                        tbody.html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                    } else {
                        var rows = '';
                        list.forEach(function (it, idx) {
                            var isVoidedLine = it.status === 'cancelled' || it.voided_at;
                            var taxRate = parseFloat(it.tax) || 0;
                            var sub = posRestaurantServerSubtotal(it, it.quantity);
                            if (!isVoidedLine) {
                                total += sub;
                            }
                            rows += buildRestaurantCartRow(it, idx, { sub: sub, sessionKeyVal: sessionKeyVal });
                        });
                        tbody.html(rows);
                    }
                    updateRestaurantCartTotals(total);
                    var payBtn = document.getElementById('pos-pay-btn-restaurant');
                    if (payBtn) payBtn.disabled = list.length === 0;
                    // Never leave select-checkboxes on a refreshed cart unless still in delete mode.
                    $('#tbody-restaurant .pos-cart-line__select').remove();
                    if (posDeleteSelectMode) {
                        posRestaurantApplyDeleteCheckboxes();
                        posRestaurantUpdateEmptyCartButtonState();
                    } else {
                        posRestaurantExitDeleteSelectMode();
                    }
                }).fail(function () {
                    $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                    updateRestaurantCartTotals(0);
                    var payBtn = document.getElementById('pos-pay-btn-restaurant');
                    if (payBtn) payBtn.disabled = true;
                    posRestaurantExitDeleteSelectMode();
                });
            }
            function posRestaurantStatusPollTick() {
                if (document.visibilityState === 'hidden') return;
                if (posDeleteSelectMode) return;
                if (!$('#pos-restaurant-order').length || $('#pos-restaurant-order').hasClass('d-none')) return;
                var $focus = $(document.activeElement);
                if ($focus.is('#pos-guest-name, #pos-guest-email, #pos-guest-phone')) {
                    return;
                }
                if ($focus.closest('#tbody-restaurant').length && $focus.is('input, textarea') && $focus.closest('tr[data-is-sent="0"]').length) {
                    return;
                }
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var orderType = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                var posId = $('#pos_restaurant_pos_id').val();
                if (!branchId || !shiftId) return;
                if (orderType === 'delivery' || orderType === 'takeaway') {
                    if (!posId && !shiftId) return;
                    loadRestaurantOrder(null, shiftId, branchId, orderType, posId || undefined);
                } else if (tableId) {
                    loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in', posId || undefined);
                }
            }
            setInterval(posRestaurantStatusPollTick, 8000);
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') {
                    posRestaurantStatusPollTick();
                }
            });
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
                $('#pos-join-table-restaurant, #pos-split-bill-restaurant').toggleClass('d-none', !isDineIn);
                var $closeBtn = $('#pos-close-table-btn');
                if (isDineIn) {
                    $closeBtn.toggleClass('d-none', ($closeBtn.data('table-status') || '') !== 'occupied');
                    $('#pos_restaurant_pos_id').val('');
                } else {
                    $closeBtn.addClass('d-none');
                    $('#pos-new-delivery-takeaway-label').text(orderType === 'delivery' ? '{{ __("pos.new_delivery") }}' : '{{ __("pos.new_takeaway") }}');
                }
            }
            $('#pos-restaurant-order-type').on('change', function () {
                var orderType = $(this).val() || 'dine_in';
                $('#pos_order_type_hidden').val(orderType);
                posRestaurantToggleOrderTypeUI(orderType);
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (orderType === 'delivery' || orderType === 'takeaway') {
                    $('#pos_restaurant_table_id').val('');
                    $('#pos_table_id_hidden').val('');
                    $('#pos-selected-table').text('—');
                    $('#pos_restaurant_pos_id').val('');
                    if (branchId && shiftId) {
                        loadRestaurantDeliveryTakeawayCustomers(branchId, shiftId, orderType);
                        loadRestaurantOrder(null, shiftId, branchId, orderType);
                    } else {
                        $('#pos-customer-grid').html('<p class="text-muted small mb-0">{{ __("pos.select_branch_first") }}</p>');
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                        updateRestaurantCartTotals(0);
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        posRestaurantCartKey = shiftId ? (orderType + '_' + shiftId) : '';
                    }
                } else {
                    posRestaurantCartKey = '';
                    if ($('#pos_restaurant_table_id').val() && shiftId && branchId) {
                        loadRestaurantOrder($('#pos_restaurant_table_id').val(), shiftId, branchId, 'dine_in');
                    }
                }
            });
            $(document).on('click', '.pos-customer-btn', function () {
                var posId = $(this).data('pos-id');
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var orderType = $('#pos-restaurant-order-type').val() || 'delivery';
                if (!posId || !branchId || !shiftId) return;
                $('#pos_restaurant_pos_id').val(posId);
                $('#pos-guest-name').val($(this).data('name') || '');
                $('#pos-guest-email').val($(this).data('email') || '');
                $('#pos-guest-phone').val($(this).data('phone') || '');
                loadRestaurantOrder(null, shiftId, branchId, orderType, posId);
            });
            $('#pos-new-delivery-takeaway-btn').on('click', function () {
                var orderType = $('#pos-restaurant-order-type').val() || 'delivery';
                var shiftId = $('#pos_shift_id_hidden').val();
                var branchId = $('#pos-restaurant-branch').val();
                if (orderType !== 'delivery' && orderType !== 'takeaway') return;
                $('#pos_restaurant_pos_id').val('');
                posRestaurantCartKey = shiftId ? (orderType + '_' + shiftId) : '';
                $('#pos-guest-name').val('');
                $('#pos-guest-email').val('');
                $('#pos-guest-phone').val('');
                if (branchId && shiftId) {
                    loadRestaurantOrder(null, shiftId, branchId, orderType);
                } else {
                    $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                    updateRestaurantCartTotals(0);
                    $('#pos-pay-btn-restaurant').prop('disabled', true);
                }
            });
            if ($('#pos-restaurant-order-type').length) {
                posRestaurantToggleOrderTypeUI($('#pos-restaurant-order-type').val() || 'dine_in');
            }

            function posRestaurantClearCartDom() {
                $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                $('#discount_restaurant').val('');
                updateRestaurantCartTotals(0);
                $('#pos-pay-btn-restaurant').prop('disabled', true);
                posRestaurantExitDeleteSelectMode();
                posRestaurantUpdateEmptyCartButtonState();
            }
            function posRestaurantResetScreen($btn) {
                var branchId = $('#pos-restaurant-branch').val();
                var sk = $('#empty_cart').val() || sessionKey;
                var data = { session_key: sk };
                if (typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) {
                    data.cart_key = posRestaurantCartKey;
                } else {
                    var tid = $('#pos_table_id_hidden').val();
                    var sid = $('#pos_shift_id_hidden').val();
                    if (tid && sid) { data.table_id = tid; data.shift_id = sid; }
                }
                if ($btn && $btn.length) {
                    $btn.prop('disabled', true).addClass('disabled');
                }
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: data,
                    complete: function () {
                        if ($btn && $btn.length) {
                            $btn.prop('disabled', false).removeClass('disabled');
                        }
                        // Same end state as the old page reload: fresh cart, no table/order picked.
                        posRestaurantClearCartDom();
                        $('#pos-restaurant-order-type').val('dine_in');
                        $('#pos_order_type_hidden').val('dine_in');
                        posRestaurantToggleOrderTypeUI('dine_in');
                        $('#pos_restaurant_table_id').val('');
                        $('#pos_restaurant_table_name').val('');
                        $('#pos_table_id_hidden').val('');
                        $('#pos_table_id').val('');
                        $('#pos-selected-table').text('—');
                        $('#pos_restaurant_pos_id').val('');
                        posRenderCheckSelector([], '');
                        $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                        posCurrentTableWaiter = { id: '', name: '' };
                        setRestaurantWaiterDisplay('');
                        $('#pos-guest-name').val('');
                        $('#pos-guest-email').val('');
                        $('#pos-guest-phone').val('');
                        posRestaurantCartKey = '';
                        if (branchId) {
                            loadRestaurantTables(branchId);
                        }
                    }
                });
            }

            $(document).on('click', '#pos-restaurant-reset-btn', function () {
                posRestaurantResetScreen($(this));
            });

            $(document).on('click', '.pos-void-line', function () {
                var lineId = $(this).data('line-id');
                var name = $(this).data('name') || '';
                if (!lineId) return;
                $('#posVoidLineModal').data('void-line-id', lineId);
                $('#pos-void-line-name').text(name ? (name + ' — ' + '{{ __("pos.void_reason_required") }}') : '{{ __("pos.void_reason_required") }}');
                $('#pos-void-reason-input').val('');
                $('#pos-void-password-input').val('');
                $('#posVoidLineModal').modal('show');
            });
            $('#pos-void-confirm-btn').on('click', function () {
                var lineId = $('#posVoidLineModal').data('void-line-id');
                var reason = ($('#pos-void-reason-input').val() || '').trim();
                var managerPassword = ($('#pos-void-password-input').val() || '').trim();
                if (!lineId) return;
                if (!reason) {
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.void_reason_required") }}', 'error');
                    return;
                }
                if (!canVoidDirectly && !managerPassword) {
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.void_password_required") }}', 'error');
                    return;
                }
                var url = posRestaurantVoidLineUrl.replace('__LINE__', lineId);
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: { _token: '{{ csrf_token() }}', reason: reason, manager_password: managerPassword },
                    success: function () {
                        $('#posVoidLineModal').modal('hide');
                        var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                        var shiftId = $('#pos_shift_id_hidden').val();
                        var branchId = $('#pos-restaurant-branch').val();
                        var orderType = $('#pos-restaurant-order-type').val() || 'dine_in';
                        var posId = $('#pos_restaurant_pos_id').val();
                        if (orderType === 'delivery' || orderType === 'takeaway') {
                            if (branchId && shiftId) loadRestaurantOrder(null, shiftId, branchId, orderType, posId || undefined);
                        } else if (tableId) {
                            loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in');
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("pos.error") }}', msg, 'error');
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
            });

            $(document).on('click', '.pos-remove-from-cart', function (e) {
                var $row = $(this).closest('tr');
                if ($row.attr('data-voided') === '1' || $row.hasClass('pos-cart-row--voided')) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.voided_cannot_delete") }}', 'error');
                    return false;
                }
            });

            $(document).on('click', '#pos-pay-btn-restaurant', function (e) {
                var orderType = $('#pos-restaurant-order-type').val() || 'dine_in';
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                if (orderType === 'dine_in' && !tableId) {
                    e.preventDefault();
                    e.stopPropagation();
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table_required") }}', 'error');
                    return false;
                }
                var warehouseId = $('#pos-restaurant-warehouse').val();
                if (!warehouseId) {
                    e.preventDefault();
                    e.stopPropagation();
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_warehouse") }}', 'error');
                    return false;
                }
                var url = $(this).data('url');
                var title = $(this).attr('data-title') || '';
                var data = {};
                if ($('#vc_name_hidden').length) data['vc_name'] = $('#vc_name_hidden').val();
                data['warehouse_name'] = warehouseId;
                if ($('#discount_hidden_restaurant').length) data['discount'] = $('#discount_hidden_restaurant').val();
                else if ($('#discount_hidden').length) data['discount'] = $('#discount_hidden').val();
                if ($('#quotation_id').length) data['quotation_id'] = $('#quotation_id').val();
                if ($('#pos_order_type_hidden').length) data['pos_order_type'] = $('#pos_order_type_hidden').val();
                if ($('#pos_table_id_hidden').length) data['pos_table_id'] = $('#pos_table_id_hidden').val();
                if ($('#pos_shift_id_hidden').length) data['pos_shift_id'] = $('#pos_shift_id_hidden').val();
                if ($('#pos_restaurant_pos_id').length && $('#pos_restaurant_pos_id').val()) {
                    data['pos_id'] = $('#pos_restaurant_pos_id').val();
                }
                if ($('#pos_restaurant_pos_id').length && (orderType === 'delivery' || orderType === 'takeaway')) {
                    var pid = $('#pos_restaurant_pos_id').val();
                    if (pid) data['pos_id'] = pid;
                }
                $('#commonModal .modal-title').html(title);
                $('.modal-dialog').removeClass('modal-xl modal-lg modal-md modal-sm').addClass('modal-' + ($(this).data('size') || 'xl'));
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
                        if (window.posRestaurantSplitConfig && window.posRestaurantSplitConfig.mode === 'equal') {
                            setTimeout(function () {
                                $(document).trigger('pos-apply-split-config', [window.posRestaurantSplitConfig]);
                                window.posRestaurantSplitConfig = null;
                            }, 150);
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            $(document).on('click', '#pos-view-bill-restaurant', function (e) {
                e.preventDefault();
                var orderType = $('#pos-restaurant-order-type').val() || 'dine_in';
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                if (orderType === 'dine_in' && !tableId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table_required") }}', 'error');
                    return;
                }
                var warehouseId = $('#pos-restaurant-warehouse').val();
                if (!warehouseId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_warehouse") }}', 'error');
                    return;
                }
                var data = {
                    warehouse_name: warehouseId,
                    discount: $('#discount_hidden_restaurant').length ? $('#discount_hidden_restaurant').val() || '0' : ($('#discount_hidden').length ? $('#discount_hidden').val() || '0' : '0'),
                    vc_name: $('#vc_name_hidden').length ? $('#vc_name_hidden').val() || '' : '',
                    pos_table_id: tableId || '',
                    pos_shift_id: $('#pos_shift_id_hidden').length ? $('#pos_shift_id_hidden').val() || '' : '',
                    pos_order_type: orderType,
                    pos_id: (orderType === 'delivery' || orderType === 'takeaway') && $('#pos_restaurant_pos_id').length ? ($('#pos_restaurant_pos_id').val() || '') : ''
                };
                $('#commonModal .modal-title').html('{{ __("pos.bill") }}');
                $('.modal-dialog').removeClass('modal-xl modal-lg modal-md modal-sm').addClass('modal-md');
                $.ajax({
                    url: '{{ route("pos.bill.view") }}',
                    data: data,
                    success: function (html) {
                        $('#commonModal .body').html(html);
                        $('#commonModal').modal('show');
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            $(document).on('click', '#pos-empty-cart-restaurant', function (e) {
                var $btn = $(this);
                if ($btn.prop('disabled') || $btn.hasClass('disabled') || $btn.hasClass('pos-empty-cart--locked') || $btn.attr('aria-disabled') === 'true') {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.delete_sent_admin_only") }}', 'error');
                    return false;
                }
                var hasSent = posRestaurantOrderHasSentLines();
                if (hasSent) {
                    if (!canDeleteSent) {
                        show_toastr('{{ __("pos.error") }}', '{{ __("pos.delete_sent_admin_only") }}', 'error');
                        return;
                    }
                    posRestaurantEnterDeleteSelectMode();
                    return;
                }
                if (!confirm('{{ __("pos.remove_all_items") }}')) return;
                var sk = $( "#empty_cart" ).val() || sessionKey;
                var data = { session_key: sk };
                if (typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) {
                    data.cart_key = posRestaurantCartKey;
                } else {
                    var tid = $('#pos_table_id_hidden').val();
                    var sid = $('#pos_shift_id_hidden').val();
                    if (tid && sid) { data.table_id = tid; data.shift_id = sid; }
                }
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: data,
                    success: function () {
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                        updateRestaurantCartTotals(0);
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        posRestaurantExitDeleteSelectMode();
                        posRestaurantUpdateEmptyCartButtonState();
                        show_toastr('success', '{{ __("Cart is empty!") }}', 'success');
                    }
                });
            });

            function posRestaurantUpdateEmptyCartButtonState() {
                var $btn = $('#pos-empty-cart-restaurant');
                if (!$btn.length) return;
                var hasSent = posRestaurantOrderHasSentLines();
                var allowDeleteSent = canDeleteSent === true || canDeleteSent === 1 || canDeleteSent === '1';
                if (posDeleteSelectMode && allowDeleteSent) {
                    $btn.addClass('d-none');
                    return;
                }
                $btn.removeClass('d-none');
                if (hasSent && !allowDeleteSent) {
                    $btn.prop('disabled', true)
                        .attr('disabled', 'disabled')
                        .attr('aria-disabled', 'true')
                        .addClass('disabled pos-empty-cart--locked')
                        .attr('title', '{{ __("pos.delete_sent_admin_only") }}')
                        .attr('aria-label', '{{ __("pos.delete_sent_admin_only") }}');
                } else {
                    $btn.prop('disabled', false)
                        .removeAttr('disabled')
                        .attr('aria-disabled', 'false')
                        .removeClass('disabled pos-empty-cart--locked')
                        .attr('title', hasSent && allowDeleteSent ? '{{ __("pos.select_items_to_delete") }}' : '{{ __("pos.empty_cart") }}')
                        .attr('aria-label', '{{ __("pos.empty_cart") }}');
                }
            }

            function posRestaurantApplyDeleteCheckboxes() {
                $('#tbody-restaurant .pos-cart-line__select').remove();
                if (!posDeleteSelectMode || !canDeleteSent) return;
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var $row = $(this);
                    if ($row.attr('data-is-sent') !== '1' || $row.attr('data-voided') === '1') return;
                    var lineId = $row.attr('data-line-id');
                    if (!lineId) return;
                    $row.find('.pos-cart-line__main').prepend(
                        '<label class="pos-cart-line__select me-1 mb-0"><input type="checkbox" class="form-check-input pos-delete-line-check" value="' + lineId + '"></label>'
                    );
                });
            }

            function posRestaurantEnterDeleteSelectMode() {
                if (!canDeleteSent) {
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.delete_sent_admin_only") }}', 'error');
                    return;
                }
                posDeleteSelectMode = true;
                $('#pos-empty-cart-restaurant').addClass('d-none');
                $('#pos-delete-selected-restaurant, #pos-cancel-delete-select-restaurant').removeClass('d-none');
                show_toastr('Info', '{{ __("pos.select_items_to_delete") }}', 'info');
                posRestaurantApplyDeleteCheckboxes();
                posRestaurantUpdateEmptyCartButtonState();
            }

            function posRestaurantExitDeleteSelectMode() {
                posDeleteSelectMode = false;
                $('#pos-delete-selected-restaurant, #pos-cancel-delete-select-restaurant').addClass('d-none');
                $('#pos-empty-cart-restaurant').removeClass('d-none');
                $('#tbody-restaurant .pos-cart-line__select').remove();
                posRestaurantUpdateEmptyCartButtonState();
            }

            $(document).on('click', '#pos-cancel-delete-select-restaurant', function () {
                posRestaurantExitDeleteSelectMode();
            });

            $(document).on('click', '#pos-delete-selected-restaurant', function () {
                var lineIds = [];
                $('#tbody-restaurant .pos-delete-line-check:checked').each(function () {
                    var id = parseInt($(this).val(), 10);
                    if (id) lineIds.push(id);
                });
                if (!lineIds.length) {
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.select_at_least_one_item") }}', 'error');
                    return;
                }
                if (!confirm('{{ __("pos.are_you_sure") }} {{ __("pos.delete_selected") }}?')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.ajax({
                    url: posRestaurantDeleteLinesUrl,
                    type: 'POST',
                    data: { _token: '{{ csrf_token() }}', line_ids: lineIds },
                    success: function (res) {
                        posRestaurantExitDeleteSelectMode();
                        show_toastr('success', (res && res.message) ? res.message : '{{ __("pos.items_deleted_from_cart") }}', 'success');
                        var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                        var shiftId = $('#pos_shift_id_hidden').val();
                        var branchId = $('#pos-restaurant-branch').val();
                        var orderType = $('#pos-restaurant-order-type').val() || 'dine_in';
                        var posId = $('#pos_restaurant_pos_id').val();
                        if (orderType === 'delivery' || orderType === 'takeaway') {
                            if (branchId && shiftId) loadRestaurantOrder(null, shiftId, branchId, orderType, posId || undefined);
                        } else if (tableId) {
                            loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in');
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("pos.error") }}', msg, 'error');
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
            });

            function posRestaurantOrderHasSentLines() {
                return $('#tbody-restaurant tr[data-product-id][data-is-sent="1"]:not([data-voided="1"])').length > 0;
            }
            function posRestaurantRefreshAfterSend(payload, res) {
                var branchId = payload.branchId || $('#pos-restaurant-branch').val();
                var shiftId = payload.shiftId || $('#pos_shift_id_hidden').val();
                var posId = (res && res.pos_id) ? res.pos_id : ($('#pos_restaurant_pos_id').val() || '');
                if (posId) {
                    $('#pos_restaurant_pos_id').val(posId);
                }
                if (payload.isNonDineIn) {
                    loadRestaurantOrder(null, shiftId, branchId, payload.orderType, posId || undefined);
                    if (branchId && shiftId && typeof loadRestaurantDeliveryTakeawayCustomers === 'function') {
                        loadRestaurantDeliveryTakeawayCustomers(branchId, shiftId, payload.orderType);
                    }
                } else if (payload.tableId) {
                    loadRestaurantOrder(payload.tableId, shiftId, branchId, 'dine_in');
                }
                if (branchId) {
                    loadRestaurantTables(branchId);
                }
            }
            function posRestaurantMarkUnsentRowsAsSent() {
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var $row = $(this);
                    if ($row.attr('data-is-sent') === '1') return;
                    $row.attr('data-is-sent', '1');
                    $row.attr('data-status', 'pending');
                    $row.removeClass('pos-cart-row--draft').addClass('pos-cart-row--sent pos-cart-row-sent');
                    var $badge = $row.find('.pos-line-status');
                    if ($badge.length) {
                        $badge.text(posStatusLabels.pending || '{{ __("pos.status_pending") }}');
                    }
                });
                if (typeof posRestaurantUpdateEmptyCartButtonState === 'function') {
                    posRestaurantUpdateEmptyCartButtonState();
                }
            }
            function posRestaurantGetKitchenBarRows(unsentOnly) {
                var kitchenRows = [], barRows = [];
                var byPrinter = {};
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    if (unsentOnly && $(this).attr('data-is-sent') === '1') {
                        return;
                    }
                    var name = $(this).attr('data-name') || $(this).find('.name').text();
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || $(this).attr('data-quantity') || 1, 10);
                    var $n = $(this).find('.notes');
                    var note = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    var zone = ($(this).attr('data-print-zone') || '').toLowerCase();
                    var printerId = $(this).attr('data-printer-id') || '';
                    var printerName = $(this).attr('data-printer-name') || '';
                    var optionLabels = [];
                    $(this).find('.pos-cart-line__option').each(function () {
                        var label = ($(this).text() || '').trim();
                        if (label) optionLabels.push(label);
                    });
                    var item = { name: name, qty: qty, note: note, options: optionLabels };
                    if (printerId && printerId !== '') {
                        if (!byPrinter[printerId]) byPrinter[printerId] = { name: printerName || ('Printer ' + printerId), rows: [] };
                        byPrinter[printerId].rows.push(item);
                    } else {
                        if (zone === 'bar') barRows.push(item); else if (zone === 'both') { kitchenRows.push(item); barRows.push(item); } else kitchenRows.push(item);
                    }
                });
                return { kitchen: kitchenRows, bar: barRows, byPrinter: byPrinter };
            }
            function posRestaurantPrintSlips(tableName, options) {
                options = options || {};
                var unsentOnly = options.unsentOnly !== false;
                var isAddon = options.isAddon === true || (options.isAddon !== false && unsentOnly && posRestaurantOrderHasSentLines());
                var orderRef = options.orderRef || $('#pos_restaurant_pos_id').val() || '';
                // When printJobs is explicitly provided (array), never fall back to full DOM — delta print only.
                var jobsExplicit = Object.prototype.hasOwnProperty.call(options, 'printJobs');
                var jobs = jobsExplicit && Array.isArray(options.printJobs) ? options.printJobs : null;
                var rows = (jobsExplicit || jobs) ? null : posRestaurantGetKitchenBarRows(unsentOnly);
                var kitchenRows = rows ? rows.kitchen : [];
                var barRows = rows ? rows.bar : [];
                var byPrinter = rows ? (rows.byPrinter || {}) : {};
                var delay = 0;
                function printSlip(title, slipRows) {
                    if (slipRows.length === 0) return;
                    var w = window.open('', '_blank', 'width=380,height=400');
                    if (!w) {
                        if (typeof show_toastr === 'function') {
                            show_toastr('{{ __("Error") }}', '{{ __("Please allow pop-ups to print kitchen/bar slips in the browser.") }}', 'error');
                        }
                        return;
                    }
                    w.document.write('<html><head><title>' + title + '</title><style>body{font-family:monospace;padding:12px;font-size:16px;}h3{margin:0 0 8px;}table{width:100%;}td{padding:4px 0;}td:last-child{text-align:right;}.note-bold{font-weight:bold;font-size:14px;margin-top:2px;}.slip-subhead{font-weight:bold;font-size:13px;}.addon-banner{font-weight:bold;font-size:18px;margin:8px 0 4px;border:2px solid #000;padding:6px;text-align:center;}.addon-sub{font-size:13px;margin:0 0 8px;color:#333;}</style></head><body>');
                    w.document.write('<h3>' + title + '</h3>');
                    if (isAddon) {
                        w.document.write('<div class="addon-banner">{{ __("pos.kitchen_slip_addon") }}</div>');
                        w.document.write('<p class="addon-sub">{{ __("pos.kitchen_slip_addon_subtitle") }}</p>');
                    }
                    w.document.write('<p><strong>{{ __("pos.table") }}:</strong> ' + (tableName || '—') + '</p>');
                    if (orderRef) {
                        w.document.write('<p><strong>{{ __("pos.kitchen_slip_order_ref") }}</strong> ' + String(orderRef).replace(/</g, '&lt;') + '</p>');
                    }
                    w.document.write('<p><strong>{{ __("pos.time_sent") }}:</strong> ' + new Date().toLocaleString() + '</p>');
                    w.document.write('<table><thead><tr><th>{{ __("pos.name") }}</th><th>{{ __("pos.qty") }}</th></tr></thead><tbody>');
                    slipRows.forEach(function(r) {
                        var name = (r.name || '').replace(/</g, '&lt;');
                        var note = (r.note || '').replace(/</g, '&lt;');
                        var optHtml = '';
                        function slipLabelText(opt) {
                            if (typeof opt === 'string') return opt;
                            if (!opt || !opt.name) return '';
                            var label = String(opt.name);
                            var qty = opt.quantity != null ? opt.quantity : (opt.qty != null ? opt.qty : null);
                            if (qty != null && label.indexOf('×') === -1) {
                                label += ' ×' + qty;
                                if (opt.unit) label += ' ' + opt.unit;
                            }
                            return label;
                        }
                        function appendSlipLabels(list, heading) {
                            var labels = [];
                            (Array.isArray(list) ? list : []).forEach(function (opt) {
                                var label = String(slipLabelText(opt) || '').replace(/</g, '&lt;');
                                if (label) labels.push(label);
                            });
                            if (!labels.length) return;
                            optHtml += '<br><span class="slip-subhead">' + heading + ':</span>';
                            labels.forEach(function (label) {
                                optHtml += '<br>+ ' + label;
                            });
                        }
                        appendSlipLabels(r.options, '{{ __("pos.production_options") }}');
                        appendSlipLabels(r.ingredients, '{{ __("pos.production_ingredients") }}');
                        var noteHtml = note ? '<br><span class="note-bold">{{ __("Note") }}: ' + note + '</span>' : '';
                        w.document.write('<tr><td>' + name + optHtml + noteHtml + '</td><td>' + r.qty + '</td></tr>');
                    });
                    w.document.write('</tbody></table></body></html>');
                    w.document.close();
                    w.focus();
                    w.print();
                    w.close();
                }
                if (jobsExplicit) {
                    if (jobs && jobs.length) {
                        jobs.forEach(function(job) {
                            if (!job || !Array.isArray(job.rows) || job.rows.length === 0) return;
                            var title = job.title || '{{ __("pos.kitchen_slip") }}';
                            (function(jobTitle, jobRows, d) {
                                setTimeout(function() { printSlip(jobTitle, jobRows); }, d);
                            })(title, job.rows, delay);
                            delay += 400;
                        });
                    }
                    return;
                }
                Object.keys(byPrinter).forEach(function(printerId) {
                    var p = byPrinter[printerId];
                    if (p.rows && p.rows.length > 0) {
                        (function(pr, pid, d) { setTimeout(function() { printSlip(pr.name || ('Printer ' + pid), pr.rows); }, d); })(p, printerId, delay);
                        delay += 400;
                    }
                });
                (function(d) { setTimeout(function() { printSlip('{{ __("pos.kitchen_slip") }}', kitchenRows); }, d); })(delay);
                if (barRows.length > 0) (function(d) { setTimeout(function() { printSlip('{{ __("pos.bar_slip") }}', barRows); }, d); })(delay + 400);
            }
            $(document).on('click', '#pos-send-to-production', function () {
                var branchId = $('#pos-restaurant-branch').val();
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var items = [];
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    if ($(this).attr('data-is-sent') === '1') return;
                    var id = $(this).attr('data-product-id');
                    var vid = parseInt($(this).attr('data-product-variant-id'), 10);
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || $(this).attr('data-quantity') || 1, 10);
                    var price = parseFloat($(this).attr('data-price')) || 0;
                    var discount = parseFloat($(this).attr('data-discount')) || 0;
                    var $n = $(this).find('.notes');
                    var desc = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    var unitSub = parseFloat($(this).attr('data-unit-subtotal'));
                    var line = { id: id, quantity: qty, price: price, discount: discount, description: desc, cart_line_key: $(this).attr('data-cart-line-key') || '' };
                    if (!isNaN(unitSub) && unitSub > 0) line.unit_subtotal = unitSub;
                    if (!isNaN(vid) && vid > 0) line.product_variant_id = vid;
                    var lineWh = parseInt($(this).attr('data-warehouse-id'), 10);
                    if (!isNaN(lineWh) && lineWh > 0) line.warehouse_id = lineWh;
                    var selAttr = $(this).attr('data-selected-options');
                    var selOpts = posParseSelectedOptionsAttr(selAttr);
                    if (selOpts && selOpts.length) {
                        line.selected_options = selOpts;
                        line.selected_options_json = JSON.stringify(selOpts);
                    }
                    if (id && qty > 0) items.push(line);
                });
                var orderType = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                var isNonDineIn = (orderType === 'delivery' || orderType === 'takeaway');
                if (!branchId || !shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_branch_first") }}', 'error');
                    return;
                }
                if (isNonDineIn) {
                    var gName = ($('#pos-guest-name').val() || '').trim();
                    var gEmail = ($('#pos-guest-email').val() || '').trim();
                    var gPhone = ($('#pos-guest-phone').val() || '').trim();
                    if (!gName || !gEmail || !gPhone) {
                        show_toastr('{{ __("Error") }}', '{{ __("pos.customer_details_required") }}', 'error');
                        return;
                    }
                } else if (!tableId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table_required") }}', 'error');
                    return;
                }
                if (items.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                window._posPendingSendPayload = {
                    branchId: branchId,
                    tableId: tableId,
                    shiftId: shiftId,
                    items: items,
                    orderType: orderType,
                    isNonDineIn: isNonDineIn
                };
                var defPrep = parseInt($('#pos-prep-minutes-input').val(), 10) || {{ (int) ($posDefaultPrepMinutes ?? 15) }};
                $('#pos-prep-minutes-input').val(defPrep);
                var modalEl = document.getElementById('posPrepTimeModal');
                if (modalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                } else {
                    $('#posPrepTimeModal').modal('show');
                }
            });

            $(document).on('click', '#pos-prep-time-confirm-btn', function () {
                var payload = window._posPendingSendPayload;
                if (!payload || !payload.items || !payload.items.length) {
                    return;
                }
                var prepMinutes = parseInt($('#pos-prep-minutes-input').val(), 10);
                if (!prepMinutes || prepMinutes < 1) {
                    show_toastr('{{ __("Error") }}', '{{ __("Please enter preparation time in minutes.") }}', 'error');
                    return;
                }
                var modalEl = document.getElementById('posPrepTimeModal');
                if (modalEl && window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                } else {
                    $('#posPrepTimeModal').modal('hide');
                }
                var sendData = {
                    _token: '{{ csrf_token() }}',
                    branch_id: payload.branchId,
                    shift_id: payload.shiftId,
                    order_type: payload.orderType,
                    items: payload.items,
                    items_json: JSON.stringify(payload.items || []),
                    prep_minutes: prepMinutes
                };
                if (payload.isNonDineIn) {
                    sendData.guest_name = ($('#pos-guest-name').val() || '').trim();
                    sendData.guest_email = ($('#pos-guest-email').val() || '').trim();
                    sendData.guest_phone = ($('#pos-guest-phone').val() || '').trim();
                    var existingPosId = $('#pos_restaurant_pos_id').val();
                    if (existingPosId) sendData.pos_id = existingPosId;
                } else {
                    sendData.table_id = payload.tableId;
                }
                $.ajax({
                    type: 'POST',
                    url: '{{ route("pos.send.to.production") }}',
                    data: sendData,
                    success: function (res) {
                        window._posPendingSendPayload = null;
                        if (res.success) {
                            show_toastr('{{ __("Success") }}', res.message || '{{ __("Order sent to production.") }}', 'success');
                            var tableLabel = payload.isNonDineIn ? (payload.orderType === 'delivery' ? '{{ __("pos.delivery") }}' : '{{ __("pos.takeaway") }}') : $('#pos-selected-table').text();
                            var isAddonSend = posRestaurantOrderHasSentLines();
                            posRestaurantMarkUnsentRowsAsSent();
                            // Print request first (agent jobs already enqueued server-side; browser slips if configured).
                            posRestaurantPrintSlips(tableLabel, {
                                unsentOnly: true,
                                isAddon: isAddonSend,
                                orderRef: res.pos_id || $('#pos_restaurant_pos_id').val() || '',
                                printJobs: Array.isArray(res.print_jobs) ? res.print_jobs : []
                            });
                            // Refresh cart + tables in place after print has been kicked off (no page reload).
                            setTimeout(function () {
                                posRestaurantRefreshAfterSend(payload, res);
                            }, 500);
                        }
                    },
                    error: function (xhr) {
                        var msg = posRestaurantAjaxErrorMsg(xhr, '{{ __("pos.error") }}');
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });
            function posRestaurantAjaxErrorMsg(xhr, fallback) {
                var msg = fallback || '{{ __("pos.error") }}';
                if (!xhr || !xhr.responseJSON) {
                    return msg;
                }
                var data = xhr.responseJSON;
                if (data.error) {
                    return data.error;
                }
                if (data.message) {
                    msg = data.message;
                }
                if (data.errors) {
                    var first = Object.values(data.errors)[0];
                    if (Array.isArray(first) && first.length) {
                        return first[0];
                    }
                    if (typeof first === 'string') {
                        return first;
                    }
                }
                return msg;
            }
            function posRestaurantRequireGuestDetails(orderType) {
                var isNonDineIn = (orderType === 'delivery' || orderType === 'takeaway');
                if (!isNonDineIn) {
                    return true;
                }
                var gName = ($('#pos-guest-name').val() || '').trim();
                var gEmail = ($('#pos-guest-email').val() || '').trim();
                var gPhone = ($('#pos-guest-phone').val() || '').trim();
                if (!gName || !gEmail || !gPhone) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.customer_details_required") }}', 'error');
                    $('#pos-guest-name').trigger('focus');
                    return false;
                }
                return true;
            }
            $(document).on('click', '#pos-print-kitchen-slip', function () {
                var tableName = $('#pos-selected-table').text();
                var branchId = $('#pos-restaurant-branch').val();
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var items = [];
                // Match posRestaurantGetKitchenBarRows: include unsent rows even with empty print_zone (empty = kitchen).
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    if ($(this).attr('data-is-sent') === '1') return;
                    var id = $(this).attr('data-product-id');
                    var vid = parseInt($(this).attr('data-product-variant-id'), 10);
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || $(this).attr('data-quantity') || 1, 10);
                    var price = parseFloat($(this).attr('data-price')) || 0;
                    var discount = parseFloat($(this).attr('data-discount')) || 0;
                    var $n = $(this).find('.notes');
                    var desc = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    var line = { id: id, quantity: qty, price: price, discount: discount, description: desc, cart_line_key: $(this).attr('data-cart-line-key') || '' };
                    if (!isNaN(vid) && vid > 0) line.product_variant_id = vid;
                    var selAttr = $(this).attr('data-selected-options');
                    var selOpts = posParseSelectedOptionsAttr(selAttr);
                    if (selOpts && selOpts.length) {
                        line.selected_options = selOpts;
                        line.selected_options_json = JSON.stringify(selOpts);
                    }
                    if (id && qty > 0) items.push(line);
                });
                var orderTypePrint = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                var isNonDineInPrint = (orderTypePrint === 'delivery' || orderTypePrint === 'takeaway');
                if (items.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                if (!branchId || !shiftId || (!isNonDineInPrint && !tableId)) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_branch_first") }}', 'error');
                    return;
                }
                if (!posRestaurantRequireGuestDetails(orderTypePrint)) {
                    return;
                }
                var printOpts = {
                    unsentOnly: true,
                    isAddon: posRestaurantOrderHasSentLines(),
                    orderRef: $('#pos_restaurant_pos_id').val() || ''
                };
                var printData = { _token: '{{ csrf_token() }}', branch_id: branchId, shift_id: shiftId, order_type: orderTypePrint, items: items, items_json: JSON.stringify(items || []), kitchen_slip_only: 1 };
                if (isNonDineInPrint) {
                    printData.guest_name = ($('#pos-guest-name').val() || '').trim();
                    printData.guest_email = ($('#pos-guest-email').val() || '').trim();
                    printData.guest_phone = ($('#pos-guest-phone').val() || '').trim();
                    var existingPosId = $('#pos_restaurant_pos_id').val();
                    if (existingPosId) printData.pos_id = existingPosId;
                } else {
                    printData.table_id = tableId;
                }
                $.ajax({
                    type: 'POST',
                    url: '{{ route("pos.send.to.production") }}',
                    data: printData,
                    success: function (res) {
                        if (res.success) {
                            var isAddonPrint = posRestaurantOrderHasSentLines();
                            posRestaurantMarkUnsentRowsAsSent();
                            printOpts.isAddon = isAddonPrint;
                            printOpts.orderRef = res.pos_id || printOpts.orderRef;
                            printOpts.printJobs = Array.isArray(res.print_jobs) ? res.print_jobs : [];
                            posRestaurantPrintSlips(tableName, printOpts);
                            if (isNonDineInPrint) {
                                if (res.pos_id) {
                                    $('#pos_restaurant_pos_id').val(res.pos_id);
                                }
                                loadRestaurantOrder(null, $('#pos_shift_id_hidden').val(), $('#pos-restaurant-branch').val(), orderTypePrint, res.pos_id || $('#pos_restaurant_pos_id').val());
                            } else {
                                var tid = $('#pos_table_id_hidden').val();
                                var sid = $('#pos_shift_id_hidden').val();
                                if (tid) loadRestaurantOrder(tid, sid, $('#pos-restaurant-branch').val(), 'dine_in');
                            }
                        }
                    },
                    error: function (xhr) {
                        var msg = posRestaurantAjaxErrorMsg(xhr, '{{ __("pos.error") }}');
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });
            $(document).on('click', '.pos-open-drawer-btn', function () {
                var w = window.open('', '_blank', 'width=300,height=200');
                if (!w) { show_toastr('{{ __("Error") }}', '{{ __("pos.open_drawer_allow_popup") }}', 'error'); return; }
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
                    source: 'restaurant',
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
            function posRenderCheckSelector(checks, activePosId) {
                var $wrap = $('#pos-check-selector-wrap');
                var $sel = $('#pos-check-selector');
                if (!checks || checks.length <= 1) {
                    $wrap.addClass('d-none');
                    $sel.empty();
                    return;
                }
                $wrap.removeClass('d-none');
                var html = '';
                checks.forEach(function (c) {
                    html += '<option value="' + c.pos_id + '">' + posEscapeHtml(c.label) + ' (' + posEscapeHtml(c.total_formatted || c.total) + ')</option>';
                });
                $sel.html(html);
                if (activePosId) {
                    $sel.val(String(activePosId));
                }
            }
            $(document).on('change', '#pos-check-selector', function () {
                var posId = $(this).val();
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var branchId = $('#pos-restaurant-branch').val();
                if (tableId && posId) {
                    loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in', posId);
                }
            });

            window.posRestaurantSplitConfig = null;
            var posSplitGuestGroups = [];

            function posBuildSplitItemsList() {
                var html = '';
                var hasSent = false;
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var lineId = $(this).attr('data-line-id');
                    var isSent = $(this).attr('data-is-sent') === '1';
                    if (!lineId || !isSent) return;
                    hasSent = true;
                    var name = $(this).attr('data-name') || $(this).find('.name').text();
                    var sub = parseFloat($(this).attr('data-subtotal')) || 0;
                    html += '<div class="form-check"><input class="form-check-input pos-split-item-cb" type="checkbox" value="' + lineId + '" data-name="' + posEscapeHtml(name) + '" data-subtotal="' + sub + '" id="splitline' + lineId + '"><label class="form-check-label" for="splitline' + lineId + '">' + posEscapeHtml(name) + ' <span class="text-muted">(' + posFormatAmount(sub) + ')</span></label></div>';
                });
                if (!hasSent) {
                    html = '<p class="text-muted small mb-0">{{ __("pos.split_requires_sent_items") }}</p>';
                }
                $('#pos-split-items-list').html(html);
                posSplitGuestGroups = [];
                $('#pos-split-guest-groups').empty();
            }

            function posRenderSplitGuestGroups() {
                var html = '';
                posSplitGuestGroups.forEach(function (g, idx) {
                    html += '<div class="border rounded p-2"><div class="d-flex justify-content-between align-items-center mb-1"><strong>' + posEscapeHtml(g.label) + '</strong><button type="button" class="btn btn-sm btn-outline-danger pos-split-remove-guest" data-idx="' + idx + '">&times;</button></div><ul class="small mb-0 ps-3">';
                    (g.items || []).forEach(function (it) {
                        html += '<li>' + posEscapeHtml(it.name) + '</li>';
                    });
                    html += '</ul></div>';
                });
                $('#pos-split-guest-groups').html(html || '<p class="text-muted small mb-0">{{ __("pos.no_guest_checks_yet") }}</p>');
            }

            $(document).on('click', '#pos-split-add-guest', function () {
                var label = ($('#pos-split-guest-label').val() || '').trim();
                if (!label) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.guest_label_required") }}', 'error');
                    return;
                }
                var lineIds = [];
                var items = [];
                $('.pos-split-item-cb:checked').each(function () {
                    lineIds.push(parseInt($(this).val(), 10));
                    items.push({ line_id: parseInt($(this).val(), 10), name: $(this).data('name') || $(this).attr('data-name') });
                });
                if (lineIds.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_items_for_guest") }}', 'error');
                    return;
                }
                posSplitGuestGroups.push({ label: label, line_ids: lineIds, items: items });
                $('.pos-split-item-cb:checked').prop('checked', false);
                $('#pos-split-guest-label').val('');
                posRenderSplitGuestGroups();
            });
            $(document).on('click', '.pos-split-remove-guest', function () {
                var idx = parseInt($(this).data('idx'), 10);
                posSplitGuestGroups.splice(idx, 1);
                posRenderSplitGuestGroups();
            });

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
                posBuildSplitItemsList();
                posRenderSplitGuestGroups();
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
                var activeTab = $('#pos-split-equal-tab').hasClass('active') ? 'equal' : 'items';
                if (activeTab === 'equal') {
                    var total = parseFloat($('#pos-split-parts-input').attr('data-total')) || 0;
                    var n = parseInt($('#pos-split-parts-input').val(), 10) || 2;
                    if (n < 2) n = 2;
                    var perPart = total / n;
                    var amounts = [];
                    var running = 0;
                    for (var i = 0; i < n; i++) {
                        var amt = (i === n - 1) ? (total - running) : Math.round(perPart * 100) / 100;
                        amounts.push(amt);
                        running += amt;
                    }
                    window.posRestaurantSplitConfig = { mode: 'equal', parts: n, amounts: amounts, total: total };
                    $('#posSplitBillModal').modal('hide');
                    show_toastr('{{ __("pos.split_bill") }}', '{{ __("pos.split_equal_ready") }}', 'success');
                    return;
                }
                if (posSplitGuestGroups.length < 2) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.split_min_two_guests") }}', 'error');
                    return;
                }
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!tableId || !branchId || !shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table_required") }}', 'error');
                    return;
                }
                var splits = posSplitGuestGroups.map(function (g) {
                    return { label: g.label, line_ids: g.line_ids };
                });
                $.ajax({
                    url: '{{ route("pos.restaurant.split-order") }}',
                    method: 'POST',
                    data: { _token: $('meta[name="csrf-token"]').attr('content'), branch_id: branchId, table_id: tableId, shift_id: shiftId, splits: splits },
                    success: function (data) {
                        $('#posSplitBillModal').modal('hide');
                        window.posRestaurantSplitConfig = null;
                        posSplitGuestGroups = [];
                        show_toastr('{{ __("Success") }}', data.message || '{{ __("pos.split_bill_success") }}', 'success');
                        if (data.checks && data.checks.length) {
                            loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in', data.checks[0].pos_id);
                        } else {
                            loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in');
                        }
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            $(document).on('click', '#pos-move-table-restaurant', function () {
                var sourceTableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var sourceName = $('#pos_restaurant_table_name').val() || $('#pos-selected-table').text() || ('Table ' + sourceTableId);
                if (!sourceTableId || !branchId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table") }}', 'error');
                    return;
                }
                if (!shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.open_shift_required_for_branch") }}', 'error');
                    return;
                }
                $('#pos-move-from-table-name').text(sourceName);
                $('#posMoveTableModal').modal('show');
                $('#pos-move-table-list').html('<p class="text-muted small mb-0">{{ __("Loading...") }}</p>');
                $.get(posRestaurantLayoutUrl, { branch_id: branchId, shift_id: shiftId }, function (res) {
                    var tblList = '';
                    var availableCount = 0;
                    (res.areas || []).forEach(function (area) {
                        (area.tables || []).forEach(function (t) {
                            if (String(t.id) === String(sourceTableId)) return;
                            var isAvailable = t.status === 'available';
                            var statusLabel = t.status === 'available'
                                ? '{{ __("pos.available") }}'
                                : (t.status === 'occupied' ? '{{ __("pos.occupied") }}' : (t.status === 'reserved' ? '{{ __("pos.reserved") }}' : (t.status || '')));
                            var labelText = posEscapeHtml(t.name || ('Table ' + t.id)) + ' <span class="text-muted">(' + statusLabel + ')</span>';
                            if (isAvailable) {
                                availableCount++;
                                tblList += '<div class="form-check"><input class="form-check-input pos-move-tbl" type="radio" name="pos_move_target" value="' + t.id + '" id="mt' + t.id + '"><label class="form-check-label" for="mt' + t.id + '">' + labelText + '</label></div>';
                            } else {
                                tblList += '<div class="form-check"><input class="form-check-input pos-move-tbl" type="radio" name="pos_move_target" value="' + t.id + '" id="mt' + t.id + '" disabled><label class="form-check-label text-muted" for="mt' + t.id + '">' + labelText + '</label></div>';
                            }
                        });
                    });
                    if (tblList === '') {
                        tblList = '<p class="text-muted small mb-0">{{ __("pos.no_target_tables") }}</p>';
                    } else if (availableCount === 0) {
                        tblList = '<p class="text-muted small mb-0">{{ __("pos.no_available_tables_to_move") }}</p>' + tblList;
                    }
                    $('#pos-move-table-list').html(tblList);
                }).fail(function () {
                    $('#pos-move-table-list').html('<p class="text-muted small mb-0">{{ __("pos.error") }}</p>');
                });
            });
            $(document).on('click', '#pos-move-table-confirm', function () {
                var sourceTableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var targetTableId = $('.pos-move-tbl:checked:not(:disabled)').val();
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!sourceTableId || !targetTableId || !branchId || !shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_target_table") }}', 'error');
                    return;
                }
                $.ajax({
                    url: '{{ route("pos.restaurant.move-table") }}',
                    method: 'POST',
                    data: { _token: $('meta[name="csrf-token"]').attr('content'), branch_id: branchId, source_table_id: sourceTableId, target_table_id: targetTableId, shift_id: shiftId },
                    success: function (data) {
                        $('#posMoveTableModal').modal('hide');
                        show_toastr('{{ __("Success") }}', data.message || '{{ __("pos.table_moved_success") }}', 'success');
                        var targetName = data.target_table_name || ('Table ' + data.target_table_id);
                        selectRestaurantTable(data.target_table_id, targetName, 'occupied');
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            $(document).on('click', '#pos-join-table-restaurant', function () {
                var targetTableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!targetTableId || !branchId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_table") }}', 'error');
                    return;
                }
                if (!shiftId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.open_shift_required_for_branch") }}', 'error');
                    return;
                }
                $('#posJoinTableModal').modal('show');
                $('#pos-join-table-list').html('<p class="text-muted small mb-0">{{ __("Loading...") }}</p>');
                $.get(posRestaurantLayoutUrl, { branch_id: branchId }, function (res) {
                    var tblList = '';
                    var found = 0;
                    (res.areas || []).forEach(function (area) {
                        (area.tables || []).forEach(function (t) {
                            if (String(t.id) === String(targetTableId)) return;
                            if (t.status !== 'occupied' && t.status !== 'reserved') return;
                            found++;
                            tblList += '<div class="form-check"><input class="form-check-input pos-join-tbl" type="checkbox" value="' + t.id + '" id="jt' + t.id + '"><label class="form-check-label" for="jt' + t.id + '">' + (t.name || 'Table ' + t.id) + '</label></div>';
                        });
                    });
                    if (tblList === '') {
                        tblList = '<p class="text-muted small mb-0">' + (found === 0 ? '{{ __("pos.no_other_occupied_tables") }}' : '{{ __("pos.no_data_found") }}') + '</p>';
                    }
                    $('#pos-join-table-list').html(tblList);
                }).fail(function () {
                    $('#pos-join-table-list').html('<p class="text-muted small mb-0">{{ __("pos.error") }}</p>');
                });
            });
            $(document).on('click', '#pos-join-table-confirm', function () {
                var targetTableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (!targetTableId || !branchId || !shiftId) return;
                var ids = [];
                $('.pos-join-tbl:checked').each(function () { ids.push($(this).val()); });
                if (ids.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_tables_to_join") }}', 'error');
                    return;
                }
                $.ajax({
                    url: '{{ route("pos.restaurant.join-tables") }}',
                    method: 'POST',
                    data: { _token: $('meta[name="csrf-token"]').attr('content'), branch_id: branchId, target_table_id: targetTableId, target_shift_id: shiftId, source_table_ids: ids },
                    success: function (data) {
                        $('#posJoinTableModal').modal('hide');
                        show_toastr('{{ __("Success") }}', data.message || '{{ __("Orders merged successfully.") }}', 'success');
                        loadRestaurantOrder(targetTableId, shiftId, branchId);
                        if (branchId) loadRestaurantTables(branchId);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });

            $(document).on('click', '#pos-manage-tables-btn', function () {
                var branchId = $('#pos-restaurant-branch').val();
                if (!branchId) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_branch_first") }}', 'error');
                    return;
                }
                $('#pos-table-management-body').html('<p class="text-muted small">{{ __("Loading...") }}</p>');
                $('#posTableManagementModal').modal('show');
                var canReassign = {{ ($canChangeShift ?? false) ? 'true' : 'false' }};
                $.get(posRestaurantLayoutUrl, { branch_id: branchId, for_manage: 1 }, function (res) {
                    var html = '<div class="list-group list-group-flush">';
                    var allTableIds = [];
                    (res.areas || []).forEach(function (area) {
                        (area.tables || []).forEach(function (t) {
                            allTableIds.push(t.id);
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
                    if (canReassign && allTableIds.length > 0) {
                        html += '<hr><h6 class="mb-2">{{ __("pos.reassign_tables") }}</h6>';
                        html += '<p class="text-muted small mb-2">{{ __("pos.reassign_tables_hint") }}</p>';
                        html += '<div class="mb-2"><label class="form-label">{{ __("pos.from_shift") }}</label><select id="pos-reassign-from-shift" class="form-select form-select-sm"><option value="">—</option></select></div>';
                        html += '<div class="mb-2"><label class="form-label">{{ __("pos.to_shift") }}</label><select id="pos-reassign-to-shift" class="form-select form-select-sm"><option value="">—</option></select></div>';
                        html += '<div class="mb-2"><label class="form-label">{{ __("pos.select_tables_to_transfer") }}</label><div id="pos-reassign-table-list" class="border rounded p-2" style="max-height:120px;overflow-y:auto;"></div></div>';
                        html += '<button type="button" id="pos-reassign-submit" class="btn btn-sm btn-primary">{{ __("pos.transfer_tables") }}</button>';
                    }
                    if (!res.areas || res.areas.length === 0 || (res.areas.every(function(a) { return !(a.tables && a.tables.length); }))) {
                        html = '<p class="text-muted small mb-0">{{ __("pos.no_data_found") }}</p>';
                    }
                    $('#pos-table-management-body').html(html);
                    if (canReassign && allTableIds.length > 0) {
                        $.get(posBranchOpenShiftsUrl, { branch_id: branchId }, function (sres) {
                            var shifts = (sres && sres.shifts) ? sres.shifts : [];
                            var opt = '<option value="">—</option>';
                            shifts.forEach(function (s) {
                                opt += '<option value="' + s.id + '">' + (s.user_name || '') + ' (' + (s.opened_at || '') + ')</option>';
                            });
                            $('#pos-reassign-from-shift').html(opt);
                            $('#pos-reassign-to-shift').html(opt);
                            $('#pos-reassign-table-list').html('<p class="text-muted small mb-0">{{ __("pos.select_from_waiter_first") }}</p>');
                            $('#posTableManagementModal').data('reassign-shifts', shifts);
                            $(document).off('change', '#pos-reassign-from-shift').on('change', '#pos-reassign-from-shift', function () {
                                var fromId = $(this).val();
                                var shiftsData = $('#posTableManagementModal').data('reassign-shifts') || [];
                                var fromShift = shiftsData.find(function (s) { return String(s.id) === String(fromId); });
                                var tblList = '';
                                if (fromShift && fromShift.assigned_tables && fromShift.assigned_tables.length > 0) {
                                    fromShift.assigned_tables.forEach(function (t) {
                                        tblList += '<div class="form-check"><input class="form-check-input pos-reassign-tbl" type="checkbox" value="' + t.id + '" id="rt' + t.id + '"><label class="form-check-label" for="rt' + t.id + '">' + (t.name || 'Table ' + t.id) + '</label></div>';
                                    });
                                } else {
                                    tblList = '<p class="text-muted small mb-0">{{ __("pos.no_tables_assigned_to_waiter") }}</p>';
                                }
                                $('#pos-reassign-table-list').html(tblList);
                            });
                        });
                        $(document).off('click', '#pos-reassign-submit').on('click', '#pos-reassign-submit', function () {
                            var from = $('#pos-reassign-from-shift').val();
                            var to = $('#pos-reassign-to-shift').val();
                            var ids = [];
                            $('.pos-reassign-tbl:checked').each(function () { ids.push($(this).val()); });
                            if (!from || !to || ids.length === 0) {
                                show_toastr('{{ __("Error") }}', '{{ __("pos.select_from_to_and_tables") }}', 'error');
                                return;
                            }
                            if (from === to) {
                                show_toastr('{{ __("Error") }}', '{{ __("pos.from_and_to_must_differ") }}', 'error');
                                return;
                            }
                            $.ajax({
                                url: '{{ route("pos-shift.transfer-tables") }}',
                                method: 'POST',
                                data: { _token: $('meta[name="csrf-token"]').attr('content'), from_shift_id: from, to_shift_id: to, table_ids: ids },
                                success: function () {
                                    show_toastr('{{ __("Success") }}', '{{ __("Tables transferred successfully.") }}', 'success');
                                    $('#posTableManagementModal').modal('hide');
                                    if (branchId) loadRestaurantTables(branchId);
                                },
                                error: function (xhr) {
                                    var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("pos.error") }}';
                                    show_toastr('{{ __("Error") }}', msg, 'error');
                                }
                            });
                        });
                    }
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

            // On load: if branch is pre-selected (e.g. single branch), load restaurant options & warehouses
            var initBranchId = $( '#branch_id' ).val();
            if (initBranchId) {
                toggleRestaurantOptions(initBranchId);
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

            // Multi-branch: filter warehouses when branch changes
            $( '#branch_id' ).change(function() {
                var branchId = $(this).val();
                if (!branchId) {
                    return;
                }
                toggleRestaurantOptions(branchId);
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
                                $("#tbody").html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
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
                $('#pos-customer-name').text(name || '{{ __('Walk-in-customer') }}');
                $('#pos-customer-email').text(email || 'walkin@default.com');
                $('#pos-customer-phone').text(contact || '0000000000');
                $('#pos-customer-badge').text(type === 'walk-in' ? '{{ __('Walk-in') }}' : '{{ __('Customer') }}');
                $('#vc_name_hidden').val($('#customer').val());
                var isWalkIn = ($('#customer').val() || '').toString() === 'Walk-in-customer';
                $('#pos-customer-edit').toggleClass('d-none', !isWalkIn);
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
            $(document).on('click', '#pos-customer-change', function(e) {
                e.preventDefault();
                var $customerSelect = $(this).closest('.pos-customer-block').find('.customer_select');
                var customerSelect = $customerSelect[0];
                $customerSelect.removeClass('d-none');
                if (customerSelect) {
                    customerSelect.style.setProperty('display', 'block', 'important');
                    customerSelect.focus();
                }
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
                    $("#tbody").html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                    $('.no-found').removeClass('d-none');
                    $('#btn-pur button').attr('disabled', 'disabled');
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
                if (value != '') {
                    var cat = $('.cat-active').children().data('cat-id');
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
                if (value != '') {
                    var cat = $('.cat-active').children().data('cat-id');
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

            function getProductCategories() {
                if (!navigator.onLine) {
                    var cached = posGetCachedCategories();
                    if (cached) $('#categories-listing').html(cached);
                    else $('#categories-listing').html('<div class="col-12"><small class="text-muted">{{ __("pos.offline_no_products") }}</small></div>');
                    return;
                }
                $.ajax({
                    type: 'GET',
                    url: posCategoriesUrl,
                    success: function (data) {
                        posSetCachedCategories(data);
                        $('#categories-listing').html(data);
                    },
                    error: function() {
                        var cached = posGetCachedCategories();
                        if (cached) $('#categories-listing').html(cached);
                    }
                });
            }

            function posSetRestaurantWarehouseDropdown(warehouseId) {
                var id = String(warehouseId || '');
                if (!id) return;
                var $sel = $('#pos-restaurant-warehouse');
                if ($sel.length) {
                    var $opt = $sel.find('option').filter(function () { return String(this.value) === id; });
                    if (!$opt.length) {
                        var label = $('#posWarehousePickerList input[name="pos_warehouse_pick"]').filter(function () {
                            return String(this.value) === id;
                        }).closest('.form-check').find('label').text() || id;
                        $sel.append($('<option/>').val(id).text(label));
                    }
                    if (String($sel.val()) !== id) {
                        $sel.val(id).trigger('change');
                        return;
                    }
                }
                syncRestaurantWarehouseSelection(id);
            }

            function posPromptWarehouseThenAdd($el, $btn, wasDisabled, productVariantId, ctx, selectedOptions) {
                var productId = $el.data('product-id');
                var branchId = $('#pos-restaurant-branch').val() || '';
                var currentId = getRestaurantWarehouseId();
                $.get('{{ route('pos.restaurant.warehouses.for.product') }}', {
                    branch_id: branchId,
                    product_id: productId,
                    quantity: 1,
                    product_variant_id: productVariantId || '',
                    current_warehouse_id: (currentId && currentId !== '0') ? currentId : '',
                    selected_options: (selectedOptions && selectedOptions.length) ? JSON.stringify(selectedOptions) : ''
                }).done(function (res) {
                    var list = (res && res.warehouses) ? res.warehouses : [];
                    var recommended = res && res.recommended_id ? String(res.recommended_id) : '';
                    var $list = $('#posWarehousePickerList').empty();
                    var $hint = $('#posWarehousePickerHint');
                    if (!list.length) {
                        $hint.text('{{ __("No warehouse is allocated for this user.") }}');
                    } else if (!recommended) {
                        $hint.text('{{ __("No allocated warehouse has enough stock for this item.") }}');
                    } else {
                        $hint.text('{{ __("Choose a warehouse with enough stock.") }}');
                    }
                    list.forEach(function (w) {
                        var id = 'pos-wh-pick-' + w.id;
                        var disabled = w.sufficient ? '' : ' disabled';
                        var checked = (recommended && String(w.id) === recommended) ? ' checked' : '';
                        var label = escHtml(w.name || ('#' + w.id));
                        if (!w.sufficient) {
                            label += ' — {{ __("Out of stock") }}';
                        }
                        $list.append(
                            '<div class="form-check">' +
                            '<input class="form-check-input" type="radio" name="pos_warehouse_pick" id="' + id + '" value="' + w.id + '"' + checked + disabled + '>' +
                            '<label class="form-check-label" for="' + id + '">' + label + '</label>' +
                            '</div>'
                        );
                    });
                    $('#posWarehousePickerConfirm').prop('disabled', !recommended);
                    $('#posWarehousePickerModal').data('posWarehouseCtx', {
                        $el: $el,
                        $btn: $btn,
                        wasDisabled: wasDisabled,
                        productVariantId: productVariantId,
                        ctx: ctx,
                        selectedOptions: selectedOptions
                    });
                    $('#posWarehousePickerModal').modal('show');
                }).fail(function (xhr) {
                    var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || '{{ __("Could not check warehouse stock.") }}';
                    if (typeof show_toastr === 'function') show_toastr('{{ __("Error") }}', msg, 'error');
                });
            }

            function escHtml(s) {
                return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            }

            $(document).on('change', '#posWarehousePickerList input[name="pos_warehouse_pick"]', function () {
                $('#posWarehousePickerConfirm').prop('disabled', !$(this).val());
            });

            $(document).on('click', '#posWarehousePickerConfirm', function () {
                var picked = $('#posWarehousePickerList input[name="pos_warehouse_pick"]:checked').val();
                var d = $('#posWarehousePickerModal').data('posWarehouseCtx');
                if (!picked || !d || !d.$el) return;
                $('#posWarehousePickerModal').modal('hide');
                posSetRestaurantWarehouseDropdown(picked);
                posRestaurantRunAddToCartAjax(d.$el, d.$btn, d.wasDisabled, d.productVariantId, d.ctx, d.selectedOptions, picked);
            });

            function posRestaurantRunAddToCartAjax($el, $btn, wasDisabled, productVariantId, ctx, selectedOptions, warehouseOverride) {
                var isRestaurant = ctx.isRestaurant;
                if (isRestaurant && !warehouseOverride) {
                    posPromptWarehouseThenAdd($el, $btn, wasDisabled, productVariantId, ctx, selectedOptions);
                    return;
                }
                var tbodySel = ctx.tbodySel;
                var rowIdPrefix = ctx.rowIdPrefix;
                var totalSel = ctx.totalSel;
                var sum = 0;
                if ($btn.length && !wasDisabled) {
                    $btn.prop('disabled', true).addClass('pos-add-cart-loading');
                    var $iconGo = $btn.find('i');
                    if ($iconGo.length) $iconGo.removeClass('ti-plus').addClass('ti-loader ti-spin');
                }
                var addUrl = $el.attr('data-url') || $el.data('url');
                try {
                    var parsedAddUrl = new URL(addUrl, window.location.origin);
                    parsedAddUrl.protocol = window.location.protocol;
                    parsedAddUrl.host = window.location.host;
                    addUrl = parsedAddUrl.toString();
                } catch (e) {}
                var warehouseId = warehouseOverride ? String(warehouseOverride) : getRestaurantWarehouseId();
                if (!warehouseId || warehouseId === '0') {
                    warehouseId = '';
                    if (isRestaurant) {
                        warehouseId = $('#pos-restaurant-warehouse').length ? ($('#pos-restaurant-warehouse').val() || '') : '';
                    }
                    if (!warehouseId) {
                        warehouseId = $('#warehouse').length ? ($('#warehouse').val() || '') : '';
                    }
                }
                if (warehouseId && warehouseId !== '0') {
                    addUrl += (addUrl.indexOf('?') !== -1 ? '&' : '?') + 'war_id=' + encodeURIComponent(warehouseId);
                }
                if (isRestaurant) {
                    addUrl += (addUrl.indexOf('?') !== -1 ? '&' : '?') + 'restaurant=1';
                    if (typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) {
                        addUrl += '&cart_key=' + encodeURIComponent(posRestaurantCartKey);
                    } else {
                        var tidR = $('#pos_table_id_hidden').val();
                        var sidR = $('#pos_shift_id_hidden').val();
                        if (tidR && sidR) {
                            addUrl += '&table_id=' + encodeURIComponent(tidR) + '&shift_id=' + encodeURIComponent(sidR);
                        }
                    }
                }
                var taxRateGo = $el.data('product-tax');
                if (taxRateGo !== undefined && taxRateGo !== '' && !isNaN(parseFloat(taxRateGo))) {
                    addUrl += (addUrl.indexOf('?') !== -1 ? '&' : '?') + 'tax_rate=' + encodeURIComponent(taxRateGo);
                }
                if (productVariantId) {
                    addUrl += (addUrl.indexOf('?') !== -1 ? '&' : '?') + 'product_variant_id=' + encodeURIComponent(productVariantId);
                }
                if (selectedOptions && selectedOptions.length) {
                    addUrl += (addUrl.indexOf('?') !== -1 ? '&' : '?') + 'selected_options=' + encodeURIComponent(JSON.stringify(selectedOptions));
                }
                $.ajax({
                    url: addUrl,
                    success: function (data) {
                        if (data.code == '200') {
                            var rowDomId = posLineDomId(data.product);
                            if (isRestaurant) {
                                var existingR = $(tbodySel + ' tr[id="' + rowIdPrefix + rowDomId + '"]').filter('[data-is-sent="0"]').first();
                                if (!existingR.length) {
                                    existingR = $(tbodySel + ' tr[id="' + rowIdPrefix + rowDomId + '"]').first();
                                }
                                if (existingR.length && (data.carttotal && !data.carthtml)) {
                                    existingR.find('input.input-number').val(data.product.quantity);
                                    var sub = (data.product.subtotal != null)
                                        ? parseFloat(data.product.subtotal)
                                        : posLineSubtotal(data.product.price, data.product.tax, data.product.quantity, data.product.price_type, data.product.unit_subtotal, 0);
                                    if (data.product.unit_subtotal != null) {
                                        existingR.attr('data-unit-subtotal', data.product.unit_subtotal);
                                    }
                                    existingR.attr('data-options-amount', 0);
                                    existingR.find('.subtotal').text(typeof addCommas === 'function' ? addCommas(sub) : sub);
                                    existingR.attr('data-subtotal', sub).attr('data-quantity', data.product.quantity);
                                    if (data.product.selected_options_json) {
                                        existingR.attr('data-selected-options', typeof data.product.selected_options_json === 'string'
                                            ? data.product.selected_options_json
                                            : JSON.stringify(data.product.selected_options_json));
                                    } else if (data.product.selected_options) {
                                        existingR.attr('data-selected-options', JSON.stringify(data.product.selected_options));
                                    }
                                } else if (data.carthtml) {
                                    var $rowR = $(data.carthtml).attr('data-subtotal', data.product.subtotal);
                                    if (data.product && (data.product.selected_options_json || data.product.selected_options)) {
                                        var selJson = data.product.selected_options_json
                                            ? (typeof data.product.selected_options_json === 'string'
                                                ? data.product.selected_options_json
                                                : JSON.stringify(data.product.selected_options_json))
                                            : JSON.stringify(data.product.selected_options);
                                        $rowR.attr('data-selected-options', selJson);
                                    }
                                    $(tbodySel + ' .no-found').addClass('d-none');
                                    $(tbodySel).append($rowR);
                                    $('#pos-pay-btn-restaurant').prop('disabled', false);
                                }
                                sum = 0;
                                $(tbodySel + ' tr[data-product-id]').each(function() { sum += parseFloat($(this).attr('data-subtotal')) || 0; });
                                updateRestaurantCartTotals(sum);
                            } else {
                                if ('carttotal' in data) {
                                    $.each(data.carttotal, function (key, value) {
                                        var rid = posLineDomId(value);
                                        $('#product-id-' + rid + ' .subtotal').text(addCommas(value.subtotal));
                                        sum += value.subtotal;
                                    });
                                    $('#displaytotal').text(addCommas(sum));
                                    $('.totalamount').text(addCommas(sum));
                                    $('.discount').val('');
                                } else if (data.carthtml) {
                                    $('#tbody').append(data.carthtml);
                                    $('.no-found').addClass('d-none');
                                    var rd = posLineDomId(data.product);
                                    $('.carttable #product-id-' + rd + ' input[name="quantity"]').val(data.product.quantity);
                                }
                                $('#btn-pur button').removeAttr('disabled');
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
                                var $ic2 = $btn.find('i');
                                if ($ic2.length) $ic2.removeClass('ti-loader ti-spin').addClass('ti-plus');
                            }, 800);
                        }
                    }
                });
            }

            var posProductOptionsJsonBase = @json(url('/productservice'));

            function posProductHasOptions($el) {
                return String($el.attr('data-options-enabled')) === '1';
            }

            function posOpenOptionsModal($el, $btn, wasDisabled, variantId, ctx, optsList) {
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
                    posRestaurantRunAddToCartAjax($el, $btn, wasDisabled, variantId, ctx, selected);
                }, { forPurchase: false });
            }

            function posLoadProductOptions($el, done) {
                var productId = $el.attr('data-product-id') || $el.data('productId');
                if (!productId) {
                    done(null, []);
                    return;
                }
                var url = (posProductOptionsJsonBase+'/' || '/productservice/') + String(productId) + '/options-json';
                var warId = typeof getRestaurantWarehouseId === 'function' ? getRestaurantWarehouseId() : ($('#warehouse').val() || '0');
                $.ajax({
                    url: url,
                    type: 'GET',
                    dataType: 'json',
                    data: { war_id: warId || '0' },
                    success: function (resp) {
                        if (resp && resp.options_enabled) {
                            $el.attr('data-options-enabled', '1');
                        }
                        var list = (resp && resp.options) ? resp.options : [];
                        if (list.length) {
                            $el.attr('data-options', JSON.stringify(list));
                        }
                        done(resp, list);
                    },
                    error: function () {
                        done(null, []);
                    }
                });
            }

            function posOpenOptionsThenCart($el, $btn, wasDisabled, variantId, ctx) {
                var rawO = $el.attr('data-options');
                var opts = [];
                try { opts = rawO ? JSON.parse(rawO) : []; } catch (e) { opts = []; }

                if (posProductHasOptions($el) && opts.length) {
                    posOpenOptionsModal($el, $btn, wasDisabled, variantId, ctx, opts);
                    return;
                }

                if (!posProductHasOptions($el) && !opts.length) {
                    posLoadProductOptions($el, function (resp, list) {
                        if (resp && resp.options_enabled && list.length) {
                            posOpenOptionsModal($el, $btn, wasDisabled, variantId, ctx, list);
                        } else {
                            posRestaurantRunAddToCartAjax($el, $btn, wasDisabled, variantId, ctx, null);
                        }
                    });
                    return;
                }

                if (posProductHasOptions($el)) {
                    posLoadProductOptions($el, function (resp, list) {
                        if (list.length) {
                            posOpenOptionsModal($el, $btn, wasDisabled, variantId, ctx, list);
                        } else if (typeof show_toastr === 'function') {
                            show_toastr('{{ __("Error") }}', '{{ __("No options configured for this product.") }}', 'error');
                        }
                    });
                    return;
                }

                posRestaurantRunAddToCartAjax($el, $btn, wasDisabled, variantId, ctx, null);
            }

            $(document).on('click', '#posVariantPickerConfirm', function () {
                var $modal = $('#posVariantPickerModal');
                var d = $modal.data('posVariantCtx');
                $modal.modal('hide');
                if (!d || !d.$el) return;
                var vid = $('#posVariantPickerSelect').val();
                if (!vid) return;
                posOpenOptionsThenCart(d.$el, d.$btn, d.wasDisabled, vid, d.ctx);
            });

            $(document).on('click', '.toacart', function () {
                var $el = $(this);
                var $btn = $el.find('.pos-add-cart-btn, button').first();
                var isRestaurant = $el.closest('#pos-restaurant-panel').length > 0;
                var tbodySel = isRestaurant ? '#tbody-restaurant' : '#tbody';
                var rowIdPrefix = isRestaurant ? 'product-id-r-' : 'product-id-';
                var totalSel = isRestaurant ? '#pos-restaurant-total' : '#displaytotal, .totalamount';
                var ctx = { isRestaurant: isRestaurant, tbodySel: tbodySel, rowIdPrefix: rowIdPrefix, totalSel: totalSel };

                // Before any other check: a product click first needs an open
                // shift, then (dine-in) an open table. Open the matching
                // pop-up / panel so the user sets those up first.
                if (isRestaurant) {
                    if (!$('#pos_shift_id_hidden').val()) {
                        var $openShiftBtn = $('#pos-open-shift-btn');
                        if ($openShiftBtn.length) {
                            $openShiftBtn.trigger('click');
                        } else if ($('#posOpenShiftModal').length) {
                            $('#posOpenShiftModal').modal('show');
                        }
                        return;
                    }
                    var clickOrderType = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                    var clickTableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val() || $('#pos_restaurant_pos_id').val();
                    if (clickOrderType === 'dine_in' && !clickTableId) {
                        var $openTableBtn = $('#pos-open-table-panel-btn');
                        var $tableGridWrap = $('#pos-restaurant-table-grid-wrap');
                        if ($openTableBtn.length && !$openTableBtn.hasClass('d-none') && $tableGridWrap.length && $tableGridWrap.hasClass('d-none')) {
                            $openTableBtn.trigger('click');
                        }
                        // No table yet: scroll the table picker into view at the
                        // top of the screen so the user selects a table first.
                        if ($tableGridWrap.length && !$tableGridWrap.hasClass('d-none')) {
                            $tableGridWrap.get(0).scrollIntoView({ behavior: 'smooth', block: 'start' });
                        } else {
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        }
                        return;
                    }
                }

                if (!navigator.onLine && $el.data('product-id')) {
                    if (String($el.data('variants-enabled')) === '1') {
                        var rawOff = $el.attr('data-variants');
                        if (rawOff && rawOff.length > 2) {
                            if (typeof show_toastr === 'function') show_toastr('error', '{{ __("Variant products require online mode.") }}', 'error');
                            return;
                        }
                    }
                    var id = $el.data('product-id');
                    var name = $el.data('product-name') || '';
                    var price = parseFloat($el.data('product-price')) || 0;
                    var taxRate = parseFloat($el.data('product-tax')) || 0;
                    var imgSrc = $el.data('product-image') || '';
                    var shelfUnit = parseFloat($el.data('product-inclusive-price')) || 0;
                    var unitLine = shelfUnit > 0 ? posRoundMoney(shelfUnit) : posRoundMoney(price * (1 + taxRate / 100));
                    var qty = 1;
                    var subtotal = unitLine;
                    var $row = $(tbodySel + ' tr[id="' + rowIdPrefix + id + '"]');
                    if ($row.length) {
                        qty = parseInt($row.find('input.input-number').val() || 1, 10) + 1;
                        var storedUnit = parseFloat($row.attr('data-unit-subtotal'));
                        unitLine = (!isNaN(storedUnit) && storedUnit > 0) ? storedUnit : unitLine;
                        subtotal = posRoundMoney(unitLine * qty);
                        $row.find('input.input-number').val(qty);
                        $row.attr('data-quantity', qty).attr('data-subtotal', subtotal).attr('data-unit-subtotal', unitLine);
                        $row.find('.subtotal').text(addCommas(subtotal));
                    } else {
                        var row = isRestaurant
                            ? buildRestaurantCartRow({ id: id, cart_line_key: id, name: name, quantity: qty, price: price, tax: taxRate, unit_subtotal: unitLine, description: '', is_sent: false, status: 'not_sent' }, 0, { sub: subtotal })
                            : ('<tr data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-price-type="exclusive" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-unit-subtotal="' + unitLine + '" data-discount="0" data-netprice="' + subtotal + '"><td class="cart-images"><img alt="" src="' + imgSrc + '" class="card-image avatar shadow hover-shadow-lg"></td><td class="name">' + (name.replace(/</g, '&lt;')) + '</td><td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" title="{{ __("pos.quantity") }}" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus"></span></td><td class="tax">-</td><td class="price">' + addCommas(price) + '</td><td class="subtotal">' + addCommas(subtotal) + '</td><td class="action-btn"><a href="#" class="btn btn-sm bg-danger pos-offline-remove" data-id="' + id + '" title="{{ __("pos.delete") }}"><i class="ti ti-trash text-white"></i></a></td></tr>');
                        $(tbodySel + ' .no-found').addClass('d-none');
                        $(tbodySel).append(row);
                        if (!isRestaurant) {
                            $('#btn-pur button').removeAttr('disabled');
                            $('.btn-empty button').addClass('btn-clear-cart');
                        } else {
                            $('#pos-pay-btn-restaurant').prop('disabled', false);
                        }
                    }
                    var total = 0;
                    $(tbodySel + ' tr[data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                    if (isRestaurant) updateRestaurantCartTotals(total); else $(totalSel).text(addCommas(total));
                    return;
                }
                var wasDisabled = $btn.length && $btn.prop('disabled');
                if (navigator.onLine && String($el.attr('data-variants-enabled')) === '1') {
                    var rawVL = $el.attr('data-variants');
                    var vlist = [];
                    try { vlist = rawVL ? JSON.parse(rawVL) : []; } catch (vex) { vlist = []; }
                    var openVariantModal = function (list) {
                        if (!list || !list.length) {
                            if (typeof show_toastr === 'function') {
                                show_toastr('{{ __("Error") }}', '{{ __("Select size / color variant for this product.") }}', 'error');
                            }
                            return;
                        }
                        var $sel = $('#posVariantPickerSelect').empty();
                        list.forEach(function (v) {
                            var ps = (v.sale_price != null && v.sale_price !== '') ? (' — ' + v.sale_price) : '';
                            $sel.append($('<option/>').val(v.id).text((v.label || ((v.size || '') + ' / ' + (v.color || ''))) + ps));
                        });
                        $('#posVariantPickerModal').data('posVariantCtx', { $el: $el, $btn: $btn, wasDisabled: wasDisabled, ctx: ctx });
                        $('#posVariantPickerModal').modal('show');
                    };
                    if (vlist.length) {
                        openVariantModal(vlist);
                        return;
                    }
                    var pid = $el.data('product-id');
                    if (pid) {
                        $.get('{{ url("productservice") }}/' + pid + '/variants-json', function (resp) {
                            var list = (resp && resp.variants) ? resp.variants : [];
                            openVariantModal(list);
                        }).fail(function () {
                            openVariantModal([]);
                        });
                        return;
                    }
                }
                posOpenOptionsThenCart($el, $btn, wasDisabled, null, ctx);
            });

            $(document).on('click', '.pos-offline-remove', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                var $row = $(this).closest('tr[data-product-id]');
                var inRestaurant = $row.closest('#carthtml-restaurant').length > 0;
                var tbodySel = inRestaurant ? '#tbody-restaurant' : '#tbody';
                var totalSel = inRestaurant ? '#pos-restaurant-total' : '#displaytotal, .totalamount';
                $row.remove();
                if ($(tbodySel + ' tr[data-product-id]').length === 0) {
                    $(tbodySel).html('<tr class="text-center no-found"><td colspan="' + (inRestaurant ? 1 : 7) + '">{{ __("pos.no_data_found") }}</td></tr>');
                    if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                    else { $('#btn-pur button').attr('disabled', 'disabled'); $('.btn-empty button').removeClass('btn-clear-cart'); }
                }
                var total = 0;
                $(tbodySel + ' tr[data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                if (inRestaurant) updateRestaurantCartTotals(total); else $(totalSel).text(addCommas(total));
            });

            $(document).on('change keyup', '#carthtml input[name="quantity"], #carthtml-restaurant input[name="quantity"]', function (e) {
                e.preventDefault();
                var ele = $(this);
                var inRestaurant = ele.closest('#carthtml-restaurant').length > 0;
                var tbodySel = inRestaurant ? '#tbody-restaurant' : '#tbody';
                var rowIdPrefix = inRestaurant ? 'product-id-r-' : 'product-id-';
                var quantity = parseInt(ele.closest('span').find('input[name="quantity"]').val(), 10) || 0;
                if (!navigator.onLine) {
                    var $row = ele.closest('tr[data-product-id]');
                    if ($row.length && quantity > 0) {
                        var price = parseFloat($row.attr('data-price')) || 0;
                        var taxRate = parseFloat($row.attr('data-tax')) || 0;
                        var priceType = ($row.attr('data-price-type') || 'exclusive').toLowerCase();
                        var unitSub = parseFloat($row.attr('data-unit-subtotal'));
                        var optsAmt = posResolveOptionsAmount($row, posParseSelectedOptionsAttr($row.attr('data-selected-options') || ''), taxRate);
                        var subtotal = posLineSubtotal(price, taxRate, quantity, priceType, !isNaN(unitSub) ? unitSub : null, optsAmt);
                        $row.attr('data-subtotal', subtotal);
                        $row.find('.subtotal').text(addCommas(subtotal));
                        var total = 0;
                        $(tbodySel + ' tr[data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                        if (inRestaurant) updateRestaurantCartTotals(total);
                        else { $('#displaytotal').text(addCommas(total)); $('.totalamount').text(addCommas(total)); }
                    } else if (quantity === 0) {
                        ele.closest('tr').remove();
                        if ($(tbodySel + ' tr[data-product-id]').length === 0) {
                            $(tbodySel).html('<tr class="text-center no-found"><td colspan="' + (inRestaurant ? 1 : 7) + '">{{ __("pos.no_data_found") }}</td></tr>');
                            if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                            else { $('#btn-pur button').attr('disabled', 'disabled'); $('.btn-empty button').removeClass('btn-clear-cart'); }
                        }
                    }
                    return;
                }
                var sum = 0;
                var discount = $('.discount').val();
                if (quantity != null && quantity != 0) {
                    $.ajax({
                        url: ele.data('url'),
                        method: "patch",
                        data: (function () {
                            var d = { id: ele.attr("data-id"), quantity: quantity, discount: discount, session_key: session_key };
                            if (inRestaurant) {
                                var lineWh = ele.closest('tr').attr('data-warehouse-id');
                                d.war_id = (lineWh && lineWh !== '0') ? lineWh : getRestaurantWarehouseId();
                                if (typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) {
                                    d.cart_key = posRestaurantCartKey;
                                } else {
                                    d.table_id = $('#pos_table_id_hidden').val();
                                    d.shift_id = $('#pos_shift_id_hidden').val();
                                }
                            }
                            return d;
                        })(),
                        success: function (data) {
                            if (data.code == '200') {
                                if (quantity == 0) {
                                    var $tr = ele.closest('tr[data-product-id]');
                                    $tr.remove();
                                    if ($(tbodySel + ' tr[data-product-id]').length === 0) {
                                        $(tbodySel).html('<tr class="text-center no-found"><td colspan="' + (inRestaurant ? 1 : 7) + '">{{ __("pos.no_data_found") }}</td></tr>');
                                        if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                                        else { $('#btn-pur button').attr('disabled', 'disabled'); $('.btn-empty button').removeClass('btn-clear-cart'); }
                                    }
                                }
                                $.each(data.product, function (key, value) {
                                    sum += (value && value.subtotal != null) ? parseFloat(value.subtotal) || 0 : 0;
                                    // Match by cart line key (session key), never fall back to the clicked row for every line.
                                    var lineKey = String((value && value.cart_line_key) || key || '');
                                    var $target = $();
                                    if (lineKey !== '') {
                                        $target = $(tbodySel + ' tr[data-cart-line-key]').filter(function () {
                                            return String($(this).attr('data-cart-line-key')) === lineKey;
                                        }).first();
                                        if (!$target.length) {
                                            $target = $('#' + rowIdPrefix + String(lineKey).replace(/_/g, '-'));
                                        }
                                    }
                                    if (!$target.length && value && value.id != null && !inRestaurant) {
                                        $target = $('#' + rowIdPrefix + value.id);
                                    }
                                    // Only the changed line may fall back to the clicked row.
                                    if (!$target.length && inRestaurant && String(ele.attr('data-id')) === lineKey) {
                                        $target = ele.closest('tr[data-product-id]');
                                    }
                                    if ($target.length) {
                                        $target.find('.subtotal').text(addCommas(value.subtotal));
                                        $target.attr('data-subtotal', value.subtotal).attr('data-quantity', value.quantity);
                                        if (value.unit_subtotal != null) {
                                            $target.attr('data-unit-subtotal', value.unit_subtotal);
                                        }
                                    }
                                });
                                if (inRestaurant) {
                                    sum = 0;
                                    $(tbodySel + ' tr[data-product-id]').each(function() { sum += parseFloat($(this).attr('data-subtotal')) || 0; });
                                    updateRestaurantCartTotals(sum);
                                } else { $('#displaytotal').text(addCommas(sum)); $('.totalamount').text(data.discount); }
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
                    var $row = ele.closest('tr[data-product-id]');
                    if ($row.length && confirm('{{ __("pos.are_you_sure") }}')) {
                        $row.remove();
                        if ($('#tbody tr[data-product-id]').length === 0) {
                            $('#tbody').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                            $('#btn-pur button').attr('disabled', 'disabled');
                            $('.btn-empty button').removeClass('btn-clear-cart');
                        }
                        var total = 0;
                        $('#tbody tr[data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal') || $(this).attr('data-price') * parseInt($(this).find('input.input-number').val() || 1, 10)) || 0; });
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
                        $('#btn-pur button').attr('disabled', 'disabled');
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
                            // Clear the retail cart in place (no page reload).
                            if (typeof posClearCartDom === 'function') {
                                posClearCartDom();
                            }
                            $('.discount').val('');
                            if ($('#discount_hidden').length) $('#discount_hidden').val('');
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
                var warehouseVal = $('#pos_restaurant_warehouse_hidden').val() || $('#warehouse_name_hidden').val();
                var payload = (function() {
                    var d = {
                        vc_name: $('#vc_name_hidden').val(),
                        warehouse_name: warehouseVal,
                        discount: $('#discount_hidden').val(),
                        quotation_id: $('#quotation_id').val(),
                        payment_method: $('#payment_method').length ? $('#payment_method').val() : 'cash',
                        document_type: $('#document_type').length ? ($('#document_type').val() || 'cash_sale') : 'cash_sale',
                        notes: $('#payment_notes').length ? ($('#payment_notes').val() || '') : '',
                        tip: $('#payment_tip').length ? (parseFloat($('#payment_tip').val()) || 0) : 0,
                        pos_order_type: $('#pos_order_type_hidden').val() || '',
                        pos_table_id: $('#pos_table_id_hidden').val() || '',
                        pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                        hotel_charge_enabled: $('#hotel_charge_enabled').length ? ($('#hotel_charge_enabled').is(':checked') ? 1 : 0) : 0,
                        hotel_folio_id: $('#hotel_folio_id').length ? ($('#hotel_folio_id').val() || '') : '',
                    };
                    if ($('#pos_restaurant_pos_id').length && $('#pos_restaurant_pos_id').val()) {
                        d.pos_id = $('#pos_restaurant_pos_id').val();
                    }
                    if (d.hotel_charge_enabled && !d.hotel_folio_id) {
                        show_toastr('{{ __("Error") }}', '{{ __("Please select room folio.") }}', 'error');
                        return null;
                    }
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
                $('#commonModal').modal('hide');
                var sk = $('#empty_cart').val() || sessionKey;
                var emptyData = { session_key: sk };
                var savedTableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var savedTableName = $('#pos_restaurant_table_name').val() || $('#pos-selected-table').text();
                var shiftId = $('#pos_shift_id_hidden').val();
                var branchId = $('#pos-restaurant-branch').val();
                if (data && data.pos_id) {
                    window._posLastPaidReceiptId = data.pos_id;
                }
                if (sk === 'pos-restaurant') {
                    if (typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) {
                        emptyData.cart_key = posRestaurantCartKey;
                    } else if (savedTableId && shiftId) {
                        emptyData.table_id = savedTableId;
                        emptyData.shift_id = shiftId;
                    }
                }
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: emptyData,
                    success: function () {
                        $('#tbody').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#displaytotal, .totalamount').text('0');
                        $('#btn-pur button').attr('disabled', 'disabled');
                        $('.btn-empty button').removeClass('btn-clear-cart');
                        var showReceipt = function () {
                            if (data && data.pos_id) {
                                var receiptUrl = '{{ url("printview/pos") }}?pos_id=' + data.pos_id + '&source=restaurant';
                                $.get(receiptUrl, function (html) {
                                    $('#commonModal .modal-title').html('');
                                    $('#commonModal .modal-dialog').removeClass('modal-xl modal-lg').addClass('modal-sm');
                                    $('#commonModal .body').html(html);
                                    $('#commonModal').modal('show');
                                });
                            } else if (!savedTableId) {
                                if ($('#commonModal').length) $('#commonModal').modal('hide');
                            }
                        };
                        var releaseTableUi = function () {
                            $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="1">{{ __("pos.no_data_found") }}</td></tr>');
                            updateRestaurantCartTotals(0);
                            $('#pos-pay-btn-restaurant').prop('disabled', true);
                            if (branchId) loadRestaurantTables(branchId);
                            $('#pos_table_id_hidden').val('');
                            $('#pos_restaurant_table_id').val('');
                            $('#pos_restaurant_table_name').val('');
                            $('#pos_restaurant_pos_id').val('');
                            $('#pos-selected-table').text('—');
                            $('#pos-check-selector-wrap').addClass('d-none');
                            $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                        };
                        var keepTableUi = function () {
                            if (savedTableId && shiftId && branchId) {
                                loadRestaurantOrder(savedTableId, shiftId, branchId, 'dine_in');
                                if (branchId) loadRestaurantTables(branchId);
                            } else {
                                releaseTableUi();
                            }
                        };
                        if (savedTableId && shiftId) {
                            // Payment succeeded: ask the server to release the table
                            // (it closes only when every check of this shift is paid)
                            // and update the screen from the live status, no refresh.
                            $.ajax({
                                type: 'POST',
                                url: '{{ route("pos.restaurant.close-table-if-paid") }}',
                                data: {
                                    _token: $('meta[name="csrf-token"]').attr('content'),
                                    pos_table_id: savedTableId,
                                    pos_shift_id: shiftId
                                },
                                success: function (res) {
                                    if (res && res.closed) {
                                        releaseTableUi();
                                    } else {
                                        keepTableUi();
                                    }
                                    showReceipt();
                                },
                                error: function () {
                                    keepTableUi();
                                    showReceipt();
                                }
                            });
                        } else {
                            keepTableUi();
                            showReceipt();
                        }
                    }
                });
            });

            // Durable receipt Print → Print Agent (works even if AJAX-injected receipt scripts fail to eval).
            // Does not change kitchen/production print. Skips when printview already handled the click.
            $(document).on('click.posAgentReceipt', '#commonModal #print', function (e) {
                var $btn = $(this);
                if ($btn.data('pos-agent-busy')) {
                    return;
                }
                var useAgent = String($btn.attr('data-print-agent') || '') === '1'
                    && String($btn.attr('data-print-source') || '') === 'restaurant';
                if (!useAgent) {
                    return;
                }
                // If printview script already bound and ran tryAgentReceiptPrint, it sets this flag briefly.
                if ($btn.hasClass('disabled') && $btn.css('pointer-events') === 'none') {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                var posId = $btn.attr('data-pos-id')
                    || (typeof posPrintPosId !== 'undefined' ? posPrintPosId : '')
                    || window._posLastPaidReceiptId
                    || $('#pos_restaurant_pos_id').val()
                    || '';
                var branchId = $btn.attr('data-branch-id')
                    || $('#pos-restaurant-branch').val()
                    || $('#pos_branch_id_hidden').val()
                    || '';
                if (!posId) {
                    return;
                }
                $btn.data('pos-agent-busy', true).addClass('disabled').css('pointer-events', 'none');
                $.ajax({
                    url: '{{ route("pos.restaurant.print-receipt") }}',
                    type: 'POST',
                    data: {
                        _token: $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}',
                        branch_id: branchId,
                        pos_id: posId,
                        pos_table_id: $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val() || '',
                        pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                        pos_order_type: $('#pos-restaurant-order-type').val() || $('#pos_order_type_hidden').val() || ''
                    }
                }).done(function (res) {
                    if (res && res.success) {
                        if (typeof show_toastr === 'function') {
                            show_toastr('{{ __("Success") }}', (res.message || '{{ __("Sent to printer agent.") }}'), 'success');
                        }
                        return;
                    }
                    if (typeof show_toastr === 'function') {
                        show_toastr('{{ __("Error") }}', (res && res.error) ? res.error : '{{ __("pos.error") }}', 'error');
                    }
                }).fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Could not send to printer agent.") }}';
                    if (typeof show_toastr === 'function') {
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                }).always(function () {
                    $btn.data('pos-agent-busy', false).removeClass('disabled').css('pointer-events', '');
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
                    $('#tbody tr[data-product-id]').each(function() {
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
                            // Reload so the server-rendered top bar (opening balance,
                            // closing balance button) and the no-shift overlays
                            // reflect the newly opened shift.
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
    </script>
    {{-- PIN stays active until user manually switches/logouts --}}
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
    <script>
        (function () {
            var btn = document.getElementById('pos-fullscreen-toggle-btn');
            if (!btn) return;

            function isFullscreen() {
                return !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
            }

            function requestFullscreen() {
                var el = document.documentElement;
                var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
                if (fn) {
                    var p = fn.call(el);
                    if (p && typeof p.catch === 'function') {
                        p.catch(function () {});
                    }
                }
            }

            function exitFullscreen() {
                var fn = document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen || document.msExitFullscreen;
                if (fn) {
                    var p = fn.call(document);
                    if (p && typeof p.catch === 'function') {
                        p.catch(function () {});
                    }
                }
            }

            function updateFullscreenButton() {
                var on = isFullscreen();
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                btn.title = on ? '{{ $tp("pos.exit_fullscreen", "Exit fullscreen") }}' : '{{ $tp("pos.fullscreen", "Fullscreen") }}';
                btn.querySelectorAll('.pos-fullscreen-icon-enter, .pos-fullscreen-label-enter').forEach(function (el) {
                    el.classList.toggle('d-none', on);
                });
                btn.querySelectorAll('.pos-fullscreen-icon-exit, .pos-fullscreen-label-exit').forEach(function (el) {
                    el.classList.toggle('d-none', !on);
                });
            }

            btn.addEventListener('click', function () {
                if (isFullscreen()) {
                    exitFullscreen();
                } else {
                    requestFullscreen();
                }
            });

            ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(function (ev) {
                document.addEventListener(ev, updateFullscreenButton);
            });
            updateFullscreenButton();
        })();
    </script>
    <script>
        $(function () {
            var $customer = $('#pos-restaurant-customer');
            var $modal = $('#posNewCustomerModal');
            if (!$customer.length || !$modal.length) return;

            function updateRestaurantCustomerCard() {
                var $selected = $customer.find('option:selected');
                var type = $selected.data('type') || 'customer';
                var value = $customer.val() || '';
                $('#pos-restaurant-customer-name').text($selected.text() || '{{ __('Walk-in-customer') }}');
                $('#pos-restaurant-customer-email').text($selected.data('email') || 'walkin@default.com');
                $('#pos-restaurant-customer-phone').text($selected.data('contact') || '0000000000');
                $('#pos-restaurant-customer-badge').text(type === 'walk-in' ? '{{ __('Walk-in') }}' : '{{ __('Customer') }}');
                $('#pos-restaurant-vc-name-hidden, #vc_name_hidden').val(value);
                $('#pos-restaurant-customer-edit').toggleClass('d-none', value !== 'Walk-in-customer');
                fetchRestaurantCustomerBalance();
            }

            function fetchRestaurantCustomerBalance() {
                var vc = ($customer.val() || '').toString().trim();
                if (vc === '' || vc === 'Walk-in-customer') {
                    $('#pos-restaurant-customer-balance-wrap').addClass('d-none');
                    $('#pos-restaurant-customer-balance').text('0').attr('data-raw', 0);
                    return;
                }
                $.get('{{ route("pos.customer.balance") }}', { vc_name: vc }, function (response) {
                    var balance = parseFloat(response.balance) || 0;
                    $('#pos-restaurant-customer-balance').text(response.formatted || '0').attr('data-raw', balance);
                    $('#pos-restaurant-customer-balance-wrap').toggleClass('d-none', !response.success || balance <= 0);
                }).fail(function () {
                    $('#pos-restaurant-customer-balance-wrap').addClass('d-none');
                });
            }

            $(document).on('click.restaurantCustomer', '#pos-restaurant-customer-change', function (event) {
                event.preventDefault();
                var customerSelect = $customer[0];
                $customer.removeClass('d-none');
                if (customerSelect) {
                    customerSelect.style.setProperty('display', 'block', 'important');
                    customerSelect.focus();
                    if (typeof customerSelect.showPicker === 'function') {
                        try { customerSelect.showPicker(); } catch (error) {}
                    }
                }
            });
            $(document).on('click.restaurantCustomer', '#pos-restaurant-customer-edit', function (event) {
                event.preventDefault();
                $('#pos-new-customer-form')[0].reset();
                $('#pos-new-customer-error').addClass('d-none').text('');
                $modal.modal('show');
            });
            $(document).on('change.restaurantCustomer', '#pos-restaurant-customer', function () {
                updateRestaurantCustomerCard();
                $customer[0].style.removeProperty('display');
                $customer.addClass('d-none');
            });
            $(document).on('click.restaurantCustomer', '#pos-restaurant-pay-balance-btn', function (event) {
                event.preventDefault();
                var vc = ($customer.val() || '').toString().trim();
                var balance = parseFloat($('#pos-restaurant-customer-balance').attr('data-raw')) || 0;
                $('#pos-pay-balance-vc-name').val(vc);
                $('#pos-pay-balance-shift-id').val($('#pos_shift_id_hidden').val() || '');
                $('#pos-pay-balance-amount').val(balance > 0 ? balance.toFixed(2) : '');
                $('#pos-pay-balance-error').addClass('d-none').text('');
                $('#pos-pay-balance-invoices').html('<p class="text-muted small mb-0">{{ __('Loading...') }}</p>');
                $('#pos-pay-balance-total-due').text('0');
                $.get('{{ route("pos.customer.outstanding.invoices") }}', { vc_name: vc }, function (response) {
                    if (response.success && response.invoices && response.invoices.length) {
                        var html = '<div class="small">';
                        response.invoices.forEach(function (invoice) {
                            html += '<label class="d-flex align-items-center gap-2 py-1 border-bottom border-light"><input type="checkbox" name="invoice_ids[]" value="' + invoice.id + '" class="form-check-input pos-pay-inv-cb" checked> <span class="flex-grow-1">' + (invoice.ref_number || invoice.invoice_id) + '</span> <span class="text-end">' + invoice.due_formatted + '</span></label>';
                        });
                        $('#pos-pay-balance-invoices').html(html + '</div>');
                    } else {
                        $('#pos-pay-balance-invoices').html('<p class="text-muted small mb-0">{{ __('No outstanding invoices.') }}</p>');
                    }
                    $('#pos-pay-balance-total-due').text(response.formatted || '0');
                }).fail(function () {
                    $('#pos-pay-balance-invoices').html('<p class="text-danger small mb-0">{{ __('Error loading invoices.') }}</p>');
                });
                var $account = $('#pos-pay-balance-account');
                $account.empty().append('<option value="">{{ __('Loading...') }}</option>');
                $.get('{{ route("pos.bank.accounts") }}', function (response) {
                    $account.empty().append('<option value="">{{ __('Select account') }}</option>');
                    if (response.success && response.accounts) {
                        $.each(response.accounts, function (index, account) {
                            $account.append($('<option></option>').val(account.id).text(account.label));
                        });
                    }
                });
                $('#posPayBalanceModal').modal('show');
            });
            $(document).on('submit.restaurantCustomer', '#pos-new-customer-form', function (event) {
                event.preventDefault();
                var $form = $(this);
                var $error = $('#pos-new-customer-error');
                var $submit = $('#pos-new-customer-submit');
                $error.addClass('d-none').text('');
                $submit.prop('disabled', true);
                $.ajax({
                    url: '{{ route("customer.store.from.pos") }}',
                    type: 'POST',
                    data: $form.serialize(),
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        if (response.success && response.customer) {
                            var customer = response.customer;
                            $('<option></option>')
                                .val(customer.name)
                                .text(customer.name)
                                .attr('data-email', customer.email || '')
                                .attr('data-contact', customer.contact || '')
                                .attr('data-type', 'walk-in')
                                .appendTo($customer);
                            $customer.val(customer.name).addClass('d-none');
                            updateRestaurantCustomerCard();
                            $modal.modal('hide');
                            $form[0].reset();
                        } else {
                            $error.text(response.message || '{{ __('Error saving customer.') }}').removeClass('d-none');
                        }
                    },
                    error: function (xhr) {
                        var message = xhr.responseJSON && xhr.responseJSON.message
                            ? xhr.responseJSON.message
                            : (xhr.statusText || '{{ __('Error saving customer.') }}');
                        $error.text(message).removeClass('d-none');
                    },
                    complete: function () {
                        $submit.prop('disabled', false);
                    }
                });
            });
            $(document).on('submit.restaurantCustomer', '#pos-pay-balance-form', function (event) {
                event.preventDefault();
                var $form = $(this);
                var $error = $('#pos-pay-balance-error');
                var $submit = $('#pos-pay-balance-submit');
                $error.addClass('d-none').text('');
                $submit.prop('disabled', true);
                $.ajax({
                    url: '{{ route("pos.customer.pay.balance") }}',
                    type: 'POST',
                    data: $form.serialize(),
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        if (response.success) {
                            $('#pos-restaurant-customer-balance').text(response.formatted || '0').attr('data-raw', response.balance || 0);
                            $('#pos-restaurant-customer-balance-wrap').toggleClass('d-none', (parseFloat(response.balance) || 0) <= 0);
                            $('#posPayBalanceModal').modal('hide');
                            if (response.receipt_html) {
                                $('#commonModal .modal-title').html('');
                                $('#commonModal .body').html(response.receipt_html);
                                $('#commonModal').modal('show');
                            }
                        } else {
                            $error.text(response.message || '{{ __('Error.') }}').removeClass('d-none');
                        }
                    },
                    error: function (xhr) {
                        var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : '{{ __('Error.') }}';
                        $error.text(message).removeClass('d-none');
                    },
                    complete: function () {
                        $submit.prop('disabled', false);
                    }
                });
            });
            updateRestaurantCustomerCard();
        });
    </script>
    @if(!empty($canChangePosPrintSettings))
        @include('partials.printer-settings-modal', ['posPrinterBranches' => collect($branches ?? [])->map(fn ($name, $id) => (object) ['id' => (int) $id, 'name' => (string) $name])->values()])
    @endif
</body>
</html>