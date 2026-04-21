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
        - {{ __('POS Restaurant') }}</title>

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
        #categories-listing{
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
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
            background: #6fd943;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
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
                        <span class="text-white pos-brand">{{ __('POS Restaurant') }}</span>
                        @if($userOpenShift)
                            <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.opening_balance') }}:</strong> {{ Auth::user()->priceFormat($userOpenShift->opening_balance ?? 0) }}</span>
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
                    <div class="d-flex align-items-center gap-2">
                        <span id="pos-current-time" class="text-white opacity-90 small align-middle" style="min-width: 5rem;">--:--:--</span>
                        <span id="pos-status-badge" class="badge bg-success align-middle" style="font-size: 0.75rem;">{{ __('pos.online') }}</span>
                        <span id="pos-pending-badge" class="badge bg-warning text-dark d-none" style="font-size: 0.8rem; cursor: pointer;" data-msg="{{ __('pos.pending_sync_label') }}" title="{{ __('pos.sync_now') }}"></span>
                        <button type="button" id="pos-install-app-btn" class="btn btn-sm btn-light" title="{{ __('pos.install_app') }}">
                            <i class="ti ti-download" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('pos.install_app') }}</span>
                        </button>
                        <a href="{{ route('pos.restaurant.index') }}" class="btn btn-sm btn-light me-1" title="{{ __('POS Restaurant') }}"><i class="ti ti-building-store" style="font-size: 1.25rem;"></i></a>
                        <a href="{{ route('pos.index') }}" class="btn btn-sm btn-outline-light" title="{{ __('POS Retail') }}"><i class="ti ti-shopping-cart" style="font-size: 1.25rem;"></i></a>
                        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light" title="{{ __('pos.dashboard') }}"><i class="ti ti-home" style="font-size: 1.25rem;"></i></a>
                        
                        @if (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager'))
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
                                <li><a class="dropdown-item pos-eod-link" href="#" data-date="{{ date('Y-m-d') }}" data-bs-toggle="modal" data-bs-target="#posEodWarningModal"><i class="ti ti-calendar-off me-2"></i>{{ __('pos.day_closed') }} / {{ __('EOD Report') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('report.pos.low-stock') }}" target="_blank"><i class="ti ti-box me-2"></i>{{ __('Low Stock') }}</a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#payReceiptsModal"><i class="ti ti-receipt me-2"></i>{{ __('pos.pay_receipts') }}</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">{{ __('Other') }}</h6></li>
                                <li><a class="dropdown-item" href="{{ route('pos.barcode') }}" target="_blank"><i class="ti ti-barcode me-2"></i>{{ __('pos.pos_product_barcode') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.setting') }}" target="_blank"><i class="ti ti-settings me-2"></i>{{ __('pos.barcode_setting') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('pos.print.setting') }}" target="_blank"><i class="ti ti-printer me-2"></i>{{ __('Print') }} {{ __('Setting') }}</a></li>
                            </ul>
                        </div>
                        @endif
                        <a href="#" id="pos-end-shift-btn" class="btn btn-sm btn-warning d-none" title="{{ __('pos.end_shift') }}"><i class="ti ti-logout"></i> <span class="d-none d-sm-inline">{{ __('pos.end_shift') }}</span></a>
                        @if(isset($posPinUser) && $posPinUser)
                        <span class="text-white opacity-90 small align-middle ms-1">{{ __('pos.logged_in_as') }} <strong>{{ $posPinUser->name }}</strong></span>
                        <form method="post" action="{{ route('pos.restaurant.logout-pin') }}" class="d-inline ms-1">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-light" title="{{ __('pos.switch_user') }}"><i class="ti ti-user-switch"></i> <span class="d-none d-sm-inline">{{ __('pos.switch_user') }}</span></button>
                        </form>
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
                                $defaultBranchId = (isset($branches) && $branches->count() == 1) ? $branches->keys()->first() : '';
                            @endphp
                            @if(isset($branches) && $branches->count() >= 1)
                            <div class="col-12">
                                {{ Form::select('branch_id', $branches, $defaultBranchId, ['class' => 'form-control select branch_select', 'id' => 'branch_id', 'placeholder' => __('Select Branch'), 'readonly' => !empty($posBranchLocked), 'disabled' => !empty($posBranchLocked)]) }}
                                @if(!empty($posBranchLocked))<small class="text-muted">{{ __('Assigned branch only') }}</small>@endif
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
                                {{ Form::select('customer_id', $customers, $customer, ['class' => 'form-control select customer_select', 'id' => 'customer', 'required' => 'required', 'placeholder' => __('pos.select_customer') ]) }}
                                {{ Form::hidden('vc_name_hidden', '', ['id' => 'vc_name_hidden']) }}

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
                                            data-title="{{ __('pos.pos_invoice') }}"
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

    @include('pos.partials.variant-picker-modal')

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
    <script>
        (function($) {
            var searchUrl = '{{ route("pos.search.pay.receipts") }}';
            var printBaseUrl = '{{ url("printview/pos") }}';
            var posPrintSource = 'restaurant';
            $(document).on('click', '#pay-receipts-search', function() {
                var btn = $(this).prop('disabled', true);
                var tbody = $('#pay-receipts-tbody');
                $.get(searchUrl, {
                    pos_number: $('#pay-receipts-pos-number').val(),
                    customer_name: $('#pay-receipts-customer').val(),
                    amount: $('#pay-receipts-amount').val()
                }).done(function(res) {
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
            });
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

    <script>
        var POS_PENDING_KEY = 'pos_pending_sales';
        var POS_CACHE_PRODUCTS = 'pos_cache_products';
        var POS_CACHE_CATEGORIES = 'pos_cache_categories';
        var posSyncUrl = '{{ route("pos.sync") }}';
        var posEmptyCartUrl = '{{ route("warehouse-empty-cart") }}';
        var posRestaurantOrderUrl = '{{ route("pos.restaurant.order") }}';
        var posSearchProductsUrl = '{{ route("search.products") }}';
        var posCategoriesUrl = '{{ route("product.categories") }}';
        var sessionKey = '{{ $lastsegment ?? "pos" }}';
        var session_key = sessionKey;

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
            $('#tbody tr[data-product-id]').each(function () {
                var $row = $(this);
                var id = $row.attr('data-product-id');
                var lineKey = $row.attr('data-cart-line-key') || String(id);
                var vId = parseInt($row.attr('data-product-variant-id'), 10);
                var qty = parseInt($row.find('input.input-number').val() || 1, 10);
                var price = parseFloat($row.attr('data-price')) || 0;
                var tax = parseFloat($row.attr('data-tax')) || 0;
                var subtotal = price * qty;
                var discount = parseFloat($row.attr('data-discount')) || 0;
                var netPrice = subtotal - discount;
                cart[lineKey] = {
                    id: parseInt(id, 10),
                    product_variant_id: (!isNaN(vId) && vId > 0) ? vId : null,
                    name: $row.attr('data-name') || '',
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
                        $('#commonModal .modal-title').html('{{ __("pos.pos_invoice") }}');
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
                getProductCategories();
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
                        loadRestaurantTables(bid);
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
                            var myShiftId = userShiftByBranch[branchId];
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
                        var shiftIdForEnd = userShiftByBranch[branchId];
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
                    loadRestaurantTables(branchId);
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
                                var $whSelect = $('#pos-restaurant-warehouse');
                                $whSelect.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                                $.each(data, function (id, name) {
                                    $whSelect.append($('<option></option>').val(id).text(name));
                                });
                                var ids = Object.keys(data);
                                if (ids.length) {
                                    var wh = ids[0];
                                    $whSelect.val(wh);
                                    $('#pos_restaurant_warehouse_hidden').val(wh);
                                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                                    getProductCategoriesRestaurant();
                                }
                                $('#pos-restaurant-warehouse-hint').text(ids.length ? '' : '{{ __("No warehouses for this branch") }}').toggle(ids.length === 0);
                            });
                            $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
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
                $('#pos_restaurant_warehouse_hidden').val(wh || '');
                if (wh) {
                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                    getProductCategoriesRestaurant();
                }
                var sk = $( "#empty_cart" ).val() || sessionKey;
                var emptyData = { session_key: sk };
                if (sk === 'pos-restaurant' && typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) { emptyData.cart_key = posRestaurantCartKey; }
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: emptyData,
                    success: function () {
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        updateRestaurantCartTotals(0);
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                    }
                });
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
                    loadRestaurantTables(initRestBranch);
                    $('#pos-restaurant-placeholder').addClass('d-none');
                    $('#pos-restaurant-order').removeClass('d-none');
                    $('#pos-restaurant-order-right').removeClass('d-none');
                    $.get('{{ route("pos.warehouses.by.branch") }}', { branch_id: initRestBranch }, function (data) {
                        var $whSelect = $('#pos-restaurant-warehouse');
                        $whSelect.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(data, function (id, name) {
                            $whSelect.append($('<option></option>').val(id).text(name));
                        });
                        var ids = Object.keys(data);
                        if (ids.length) {
                            var wh = ids[0];
                            $whSelect.val(wh);
                            $('#pos_restaurant_warehouse_hidden').val(wh);
                            searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                            getProductCategoriesRestaurant();
                        }
                        $('#pos-restaurant-warehouse-hint').text(ids.length ? '' : '{{ __("No warehouses for this branch") }}').toggle(ids.length === 0);
                    });
                }
            }

            function loadRestaurantTables(branchId) {
                $.get(posRestaurantLayoutUrl, { branch_id: branchId }, function (res) {
                    var html = '';
                    (res.areas || []).forEach(function (area) {
                        html += '<div class="mb-2"><small class="text-muted d-block mb-1">' + area.name + '</small><div class="d-flex flex-wrap gap-2">';
                        (area.tables || []).forEach(function (t) {
                            var statusClass = t.status === 'occupied' ? 'btn-warning' : (t.status === 'reserved' ? 'btn-secondary' : 'btn-outline-primary');
                            var label = t.name || ('Table ' + t.id);
                            if (t.current_guests != null && t.current_guests !== '') label += ' (' + t.current_guests + ')';
                            html += '<button type="button" class="btn ' + statusClass + ' btn-sm pos-table-btn" data-id="' + t.id + '" data-name="' + (t.name || '') + '" data-status="' + (t.status || '') + '">' + label + '</button>';
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
            $(document).on('click', '.pos-table-btn', function () {
                var id = $(this).data('id');
                var name = $(this).data('name');
                var status = $(this).data('status');
                if (status === 'available') {
                    $('#pos-open-table-id').val(id);
                    $('#pos-open-table-name').val(name || ('Table ' + id));
                    $('#pos-open-table-guests').val('');
                    $('#pos-open-table-customer-name').val('');
                    $('#posOpenTableModal').modal('show');
                    $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                    return;
                }
                selectRestaurantTable(id, name, status);
            });
            $(document).on('click', '#pos-open-table-confirm', function () {
                var id = $('#pos-open-table-id').val();
                var name = $('#pos-open-table-name').val();
                var guests = $('#pos-open-table-guests').val();
                if (!id) return;
                var data = { status: 'occupied', _token: $('meta[name="csrf-token"]').attr('content') };
                if (guests !== '' && guests !== null) data.guests_count = parseInt(guests, 10) || 0;
                var customerName = $('#pos-open-table-customer-name').val();
                if (customerName && customerName.trim()) data.customer_name = customerName.trim();
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/status',
                    method: 'PATCH',
                    data: data,
                    success: function () {
                        $('#posOpenTableModal').modal('hide');
                        selectRestaurantTable(id, name, 'occupied');
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
                if (!confirm('{{ __("pos.are_you_sure") }} {{ __("pos.close_table") }}?')) return;
                $.ajax({
                    url: posTableUpdateStatusUrl + '/' + id + '/close',
                    method: 'POST',
                    data: { _token: $('meta[name="csrf-token"]').attr('content') },
                    success: function () {
                        show_toastr('{{ __("Success") }}', '{{ __("pos.table_closed") }}', 'success');
                        $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                        $('#pos-selected-table').text('—');
                        $('#pos_table_id_hidden').val('');
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
            function selectRestaurantTable(id, name, status) {
                $('#pos_restaurant_table_id').val(id);
                $('#pos_restaurant_table_name').val(name);
                $('#pos_table_id_hidden').val(id);
                $('#pos_order_type_hidden').val('dine_in');
                $('#pos-selected-table').text(name || ('Table ' + id));
                var $closeBtn = $('#pos-close-table-btn');
                $closeBtn.data('table-id', id).data('table-status', status || '');
                $closeBtn.toggleClass('d-none', (status || '') !== 'occupied');
                var branchId = $('#pos-restaurant-branch').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                if (id && (branchId || shiftId)) {
                    loadRestaurantOrder(id, shiftId, branchId, 'dine_in');
                } else {
                    $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="8">{{ __("pos.no_data_found") }}</td></tr>');
                    updateRestaurantCartTotals(0);
                    $('#pos-pay-btn-restaurant').prop('disabled', true);
                }
            }
            var posStatusLabels = { 'pending': '{{ __("pos.status_pending") }}', 'in_preparation': '{{ __("pos.status_in_preparation") }}', 'ready': '{{ __("pos.status_ready") }}', 'served': '{{ __("pos.status_served") }}', 'cancelled': '{{ __("pos.status_cancelled") }}', 'not_sent': '{{ __("pos.status_not_sent") }}' };
            var canShowVoid = {{ ($canShowVoid ?? false) ? 'true' : 'false' }};
            var posRestaurantVoidLineUrl = '{{ route("pos.restaurant.void-line", ["line" => "__LINE__"]) }}';
            function loadRestaurantOrder(tableId, shiftId, branchId, orderType, posId) {
                var params = {};
                if (tableId && (branchId || shiftId)) {
                    params.table_id = tableId;
                    if (branchId) params.branch_id = branchId;
                    if (shiftId) params.shift_id = shiftId;
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
                    if (res.pos_id && (orderType === 'delivery' || orderType === 'takeaway')) $('#pos_restaurant_pos_id').val(res.pos_id || '');
                    var orderLines = res.order_lines || [];
                    var sessionItems = res.session_items || [];
                    var list = [];
                    orderLines.forEach(function (it) {
                        list.push({ line_id: it.line_id, id: it.id, name: it.name, quantity: (it.quantity || 0), price: parseFloat(it.price) || 0, tax: parseFloat(it.tax) || 0, subtotal: parseFloat(it.subtotal) || 0, description: (it.description || '').trim(), print_zone: it.print_zone || '', pos_printer_id: it.pos_printer_id || null, printer_name: it.printer_name || '', pro_image: it.pro_image || 'default.png', status: it.status || 'pending', is_sent: true, voided_at: it.voided_at || null });
                    });
                    sessionItems.forEach(function (it) {
                        var id = it.id;
                        var qty = (it.quantity || 0);
                        var price = parseFloat(it.price) || 0;
                        var tax = parseFloat(it.tax) || 0;
                        var sub = (it.subtotal != null) ? parseFloat(it.subtotal) : (price * (1 + (tax || 0) / 100) * qty);
                        list.push({ line_id: null, id: id, name: it.name || '', quantity: qty, price: price, tax: tax, subtotal: sub, description: (it.description || '').trim(), print_zone: (it.print_zone || ''), pos_printer_id: it.pos_printer_id || null, printer_name: (it.printer_name || ''), pro_image: (it.pro_image || 'default.png'), status: 'not_sent', is_sent: false });
                    });
                    if (res.order_type && $('#pos-restaurant-order-type').length) {
                        $('#pos-restaurant-order-type').val(res.order_type);
                    }
                    if (res.guest_name !== undefined) $('#pos-guest-name').val(res.guest_name || '');
                    if (res.guest_email !== undefined) $('#pos-guest-email').val(res.guest_email || '');
                    if (res.guest_phone !== undefined) $('#pos-guest-phone').val(res.guest_phone || '');
                    var tbody = $('#tbody-restaurant');
                    var total = 0;
                    var sessionKeyVal = posRestaurantCartKey;
                    if (list.length === 0) {
                        tbody.html('<tr class="text-center no-found"><td colspan="8">{{ __("pos.no_data_found") }}</td></tr>');
                    } else {
                        var rows = '';
                        var csrf = '{{ csrf_token() }}';
                        var deleteFormBase = '{{ url("remove-from-cart") }}';
                        var statusRowClass = { 'pending': 'pos-cart-row-sent', 'not_sent': '', 'in_preparation': 'pos-cart-row-preparation', 'ready': 'pos-cart-row-ready', 'served': 'pos-cart-row-ready', 'cancelled': 'pos-cart-row-cancelled' };
                        list.forEach(function (it, idx) {
                            var sub = (it.price * it.quantity * (1 + (it.tax || 0) / 100));
                            total += sub;
                            var taxDisplay = (it.tax && it.tax !== 0) ? (it.tax + '%') : '-';
                            var rowId = it.line_id ? ('product-id-r-' + it.line_id) : ('product-id-r-s-' + it.id + '-' + idx);
                            var statusText = posStatusLabels[it.status] || it.status;
                            var rowClass = statusRowClass[it.status] || '';
                            rows += '<tr class="' + rowClass + '" data-product-id="' + it.id + '" data-line-id="' + (it.line_id || '') + '" data-is-sent="' + (it.is_sent ? '1' : '0') + '" data-status="' + (it.status || '') + '" id="' + rowId + '" data-name="' + (it.name || '').replace(/"/g, '&quot;') + '" data-price="' + it.price + '" data-quantity="' + it.quantity + '" data-tax="' + it.tax + '" data-subtotal="' + sub + '" data-discount="0" data-print-zone="' + (it.print_zone || '').replace(/"/g, '&quot;') + '" data-printer-id="' + (it.pos_printer_id || '') + '" data-printer-name="' + (it.printer_name || '').replace(/"/g, '&quot;') + '">';
                            rows += '<td class="name">' + (it.name || '').replace(/</g, '&lt;') + '</td>';
                            var descEsc = (it.description || '').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/\n/g, '&#10;');
                            if (it.is_sent) {
                                rows += '<td class="pos-note-cell"><div class="notes pos-item-note-readonly">' + (descEsc || '—') + '</div></td>';
                            } else {
                                rows += '<td class="pos-note-cell"><textarea class="form-control form-control-sm notes pos-item-notes" placeholder="{{ __("pos.note_placeholder") }}" maxlength="200" rows="2" title="{{ __("Note") }}">' + (it.description || '').replace(/</g, '&lt;').replace(/&/g, '&amp;') + '</textarea></td>';
                            }
                            if (it.is_sent) {
                                rows += '<td class="text-center">' + it.quantity + '</td>';
                            } else {
                                rows += '<td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + it.id + '" value="' + it.quantity + '"><input type="button" value="+" class="plus"></span></td>';
                            }
                            rows += '<td class="tax">' + taxDisplay + '</td>';
                            rows += '<td class="price text-right">' + (typeof addCommas === 'function' ? addCommas(it.price) : it.price) + '</td>';
                            rows += '<td class="subtotal">' + (typeof addCommas === 'function' ? addCommas(sub) : sub) + '</td>';
                            rows += '<td class="text-center"><span class="badge pos-line-status">' + statusText + '</span></td>';
                            if (it.is_sent) {
                                if (it.status === 'cancelled' || it.voided_at) {
                                    rows += '<td class="action-btn text-muted">{{ __("pos.voided") }}</td>';
                                } else if (canShowVoid) {
                                    rows += '<td class="action-btn"><button type="button" class="btn btn-sm btn-warning pos-void-line" data-line-id="' + (it.line_id || '') + '" data-name="' + (it.name || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;') + '">{{ __("pos.void") }}</button></td>';
                                } else {
                                    rows += '<td class="action-btn text-muted">—</td>';
                                }
                            } else {
                                rows += '<td class="action-btn"><a href="#" class="btn btn-sm bg-danger bs-pass-para-pos pos-remove-from-cart" data-confirm="{{ __("Are You Sure?") }}" data-text="{{ __("This action can not be undone. Do you want to continue?") }}" data-confirm-yes="delete-form-r-' + it.id + '-' + idx + '" title="{{ __("Delete") }}" data-id="' + it.id + '"><i class="ti ti-trash text-white"></i></a>';
                                rows += '<form method="post" action="' + deleteFormBase + '" id="delete-form-r-' + it.id + '-' + idx + '"><input name="_method" type="hidden" value="DELETE"><input name="_token" type="hidden" value="' + csrf + '"><input type="hidden" name="session_key" value="pos-restaurant"><input type="hidden" name="id" value="' + it.id + '"><input type="hidden" name="cart_key" value="' + (sessionKeyVal || '').replace(/"/g, '&quot;') + '"></form></td>';
                            }
                            rows += '</tr>';
                        });
                        tbody.html(rows);
                    }
                    updateRestaurantCartTotals(total);
                    var payBtn = document.getElementById('pos-pay-btn-restaurant');
                    if (payBtn) payBtn.disabled = list.length === 0;
                }).fail(function () {
                    $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="8">{{ __("pos.no_data_found") }}</td></tr>');
                    updateRestaurantCartTotals(0);
                    var payBtn = document.getElementById('pos-pay-btn-restaurant');
                    if (payBtn) payBtn.disabled = true;
                });
            }
            function posRestaurantToggleOrderTypeUI(orderType) {
                var isDineIn = orderType === 'dine_in';
                $('#pos-restaurant-table-block').toggleClass('d-none', !isDineIn);
                $('#pos-restaurant-guest-block').toggleClass('d-none', isDineIn);
                $('#pos-restaurant-table-grid-wrap').toggleClass('d-none', !isDineIn);
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
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="8">{{ __("pos.no_data_found") }}</td></tr>');
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
                    $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="8">{{ __("pos.no_data_found") }}</td></tr>');
                    updateRestaurantCartTotals(0);
                    $('#pos-pay-btn-restaurant').prop('disabled', true);
                }
            });
            if ($('#pos-restaurant-order-type').length) {
                posRestaurantToggleOrderTypeUI($('#pos-restaurant-order-type').val() || 'dine_in');
            }

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
                var warehouse_id = $('#pos-restaurant-warehouse').val();
                searchProducts(posSearchProductsUrl, '', cat, warehouse_id, 'name', 'product-listing-restaurant');
            });

            $(document).on('click', '.pos-void-line', function () {
                var lineId = $(this).data('line-id');
                var name = $(this).data('name') || '';
                if (!lineId) return;
                $('#posVoidLineModal').data('void-line-id', lineId);
                $('#pos-void-line-name').text(name ? (name + ' — ' + '{{ __("pos.void_reason_required") }}') : '{{ __("pos.void_reason_required") }}');
                $('#pos-void-reason-input').val('');
                $('#posVoidLineModal').modal('show');
            });
            $('#pos-void-confirm-btn').on('click', function () {
                var lineId = $('#posVoidLineModal').data('void-line-id');
                var reason = ($('#pos-void-reason-input').val() || '').trim();
                if (!lineId) return;
                if (!reason) {
                    show_toastr('{{ __("pos.error") }}', '{{ __("pos.void_reason_required") }}', 'error');
                    return;
                }
                var url = posRestaurantVoidLineUrl.replace('__LINE__', lineId);
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: { _token: '{{ csrf_token() }}', reason: reason },
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
                    }
                });
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
                var title = $(this).data('title') || '{{ __("pos.pos_invoice") }}';
                var data = {};
                if ($('#vc_name_hidden').length) data['vc_name'] = $('#vc_name_hidden').val();
                data['warehouse_name'] = warehouseId;
                if ($('#discount_hidden_restaurant').length) data['discount'] = $('#discount_hidden_restaurant').val();
                else if ($('#discount_hidden').length) data['discount'] = $('#discount_hidden').val();
                if ($('#quotation_id').length) data['quotation_id'] = $('#quotation_id').val();
                if ($('#pos_order_type_hidden').length) data['pos_order_type'] = $('#pos_order_type_hidden').val();
                if ($('#pos_table_id_hidden').length) data['pos_table_id'] = $('#pos_table_id_hidden').val();
                if ($('#pos_shift_id_hidden').length) data['pos_shift_id'] = $('#pos_shift_id_hidden').val();
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
                $('#commonModal .modal-title').html('{{ __("pos.view_bill") }}');
                $('.modal-dialog').removeClass('modal-xl modal-lg modal-md modal-sm').addClass('modal-xl');
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

            $(document).on('click', '#pos-empty-cart-restaurant', function () {
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
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        updateRestaurantCartTotals(0);
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        show_toastr('success', '{{ __("Cart is empty!") }}', 'success');
                    }
                });
            });

            function posRestaurantGetKitchenBarRows() {
                var kitchenRows = [], barRows = [];
                var byPrinter = {};
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var name = $(this).attr('data-name') || $(this).find('.name').text();
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || 1, 10);
                    var $n = $(this).find('.notes');
                    var note = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    var zone = ($(this).attr('data-print-zone') || '').toLowerCase();
                    var printerId = $(this).attr('data-printer-id') || '';
                    var printerName = $(this).attr('data-printer-name') || '';
                    var item = { name: name, qty: qty, note: note };
                    if (printerId && printerId !== '') {
                        if (!byPrinter[printerId]) byPrinter[printerId] = { name: printerName || ('Printer ' + printerId), rows: [] };
                        byPrinter[printerId].rows.push(item);
                    } else {
                        if (zone === 'bar') barRows.push(item); else if (zone === 'both') { kitchenRows.push(item); barRows.push(item); } else kitchenRows.push(item);
                    }
                });
                return { kitchen: kitchenRows, bar: barRows, byPrinter: byPrinter };
            }
            function posRestaurantPrintSlips(tableName) {
                var rows = posRestaurantGetKitchenBarRows();
                var kitchenRows = rows.kitchen, barRows = rows.bar, byPrinter = rows.byPrinter || {};
                var delay = 0;
                function printSlip(title, slipRows) {
                    if (slipRows.length === 0) return;
                    var w = window.open('', '_blank', 'width=380,height=400');
                    w.document.write('<html><head><title>' + title + '</title><style>body{font-family:monospace;padding:12px;font-size:14px;}h3{margin:0 0 8px;}table{width:100%;}td{padding:4px 0;}td:last-child{text-align:right;}.note-bold{font-weight:bold;font-size:13px;margin-top:2px;}</style></head><body>');
                    w.document.write('<h3>' + title + '</h3>');
                    w.document.write('<p><strong>{{ __("pos.table") }}:</strong> ' + (tableName || '—') + '</p>');
                    w.document.write('<p><strong>{{ __("pos.time_sent") }}:</strong> ' + new Date().toLocaleString() + '</p>');
                    w.document.write('<table><thead><tr><th>{{ __("pos.name") }}</th><th>{{ __("pos.qty") }}</th></tr></thead><tbody>');
                    slipRows.forEach(function(r) {
                        var name = (r.name || '').replace(/</g, '&lt;');
                        var note = (r.note || '').replace(/</g, '&lt;');
                        var noteHtml = note ? '<br><span class="note-bold">' + note + '</span>' : '';
                        w.document.write('<tr><td>' + name + noteHtml + '</td><td>' + r.qty + '</td></tr>');
                    });
                    w.document.write('</tbody></table></body></html>');
                    w.document.close();
                    w.focus();
                    w.print();
                    w.close();
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
                    var line = { id: id, quantity: qty, price: price, discount: discount, description: desc };
                    if (!isNaN(vid) && vid > 0) line.product_variant_id = vid;
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
                var sendData = { _token: '{{ csrf_token() }}', branch_id: branchId, shift_id: shiftId, order_type: orderType, items: items };
                if (isNonDineIn) {
                    sendData.guest_name = ($('#pos-guest-name').val() || '').trim();
                    sendData.guest_email = ($('#pos-guest-email').val() || '').trim();
                    sendData.guest_phone = ($('#pos-guest-phone').val() || '').trim();
                    var existingPosId = $('#pos_restaurant_pos_id').val();
                    if (existingPosId) sendData.pos_id = existingPosId;
                } else {
                    sendData.table_id = tableId;
                }
                $.ajax({
                    type: 'POST',
                    url: '{{ route("pos.send.to.production") }}',
                    data: sendData,
                    success: function (res) {
                        if (res.success) {
                            show_toastr('{{ __("Success") }}', res.message || '{{ __("Order sent to production.") }}', 'success');
                            var tableLabel = isNonDineIn ? (orderType === 'delivery' ? '{{ __("pos.delivery") }}' : '{{ __("pos.takeaway") }}') : $('#pos-selected-table').text();
                            posRestaurantPrintSlips(tableLabel);
                            if (isNonDineIn) {
                                if (res.pos_id) {
                                    $('#pos_restaurant_pos_id').val(res.pos_id);
                                    loadRestaurantOrder(null, shiftId, branchId, orderType, res.pos_id);
                                } else {
                                    loadRestaurantOrder(null, shiftId, branchId, orderType);
                                }
                                if (typeof loadRestaurantDeliveryTakeawayCustomers === 'function') {
                                    loadRestaurantDeliveryTakeawayCustomers(branchId, shiftId, orderType);
                                }
                            } else {
                                loadRestaurantOrder(tableId, shiftId, branchId, 'dine_in');
                            }
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
                        show_toastr('{{ __("Error") }}', msg, 'error');
                    }
                });
            });
            $(document).on('click', '#pos-print-kitchen-slip', function () {
                var tableName = $('#pos-selected-table').text();
                var branchId = $('#pos-restaurant-branch').val();
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                var shiftId = $('#pos_shift_id_hidden').val();
                var items = [];
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    if ($(this).attr('data-is-sent') === '1') return;
                    var zone = ($(this).attr('data-print-zone') || '').trim();
                    var printerId = ($(this).attr('data-printer-id') || '').trim();
                    if (!zone && !printerId) return;
                    var id = $(this).attr('data-product-id');
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || $(this).attr('data-quantity') || 1, 10);
                    var price = parseFloat($(this).attr('data-price')) || 0;
                    var discount = parseFloat($(this).attr('data-discount')) || 0;
                    var $n = $(this).find('.notes');
                    var desc = ($n.length && ($n.is('input') || $n.is('textarea'))) ? ($n.val() || '').trim() : ($n.text() || '').trim();
                    if (id && qty > 0) items.push({ id: id, quantity: qty, price: price, discount: discount, description: desc });
                });
                var orderTypePrint = ($('#pos-restaurant-order-type').length ? $('#pos-restaurant-order-type').val() : '') || 'dine_in';
                var isNonDineInPrint = (orderTypePrint === 'delivery' || orderTypePrint === 'takeaway');
                var rows = posRestaurantGetKitchenBarRows();
                if (rows.kitchen.length === 0 && rows.bar.length === 0 && Object.keys(rows.byPrinter || {}).every(function(k) { return !(rows.byPrinter[k].rows && rows.byPrinter[k].rows.length); })) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                var canPrint = items.length > 0 && branchId && shiftId && (isNonDineInPrint || tableId);
                if (canPrint) {
                    var printData = { _token: '{{ csrf_token() }}', branch_id: branchId, shift_id: shiftId, order_type: orderTypePrint, items: items, kitchen_slip_only: 1 };
                    if (isNonDineInPrint) {
                        printData.guest_name = ($('#pos-guest-name').val() || '').trim();
                        printData.guest_email = ($('#pos-guest-email').val() || '').trim();
                        printData.guest_phone = ($('#pos-guest-phone').val() || '').trim();
                    } else {
                        printData.table_id = tableId;
                    }
                    $.ajax({
                        type: 'POST',
                        url: '{{ route("pos.send.to.production") }}',
                        data: printData,
                        success: function (res) {
                            if (res.success) {
                                posRestaurantPrintSlips(tableName);
                                if (isNonDineInPrint) {
                                    loadRestaurantOrder(null, $('#pos_shift_id_hidden').val(), $('#pos-restaurant-branch').val(), orderTypePrint);
                                } else {
                                    var tid = $('#pos_table_id_hidden').val();
                                    var sid = $('#pos_shift_id_hidden').val();
                                    if (tid) loadRestaurantOrder(tid, sid, $('#pos-restaurant-branch').val(), 'dine_in');
                                }
                            }
                        },
                        error: function (xhr) {
                            var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
                            show_toastr('{{ __("Error") }}', msg, 'error');
                        }
                    });
                } else if (items.length > 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.select_branch_first") }}', 'error');
                } else {
                    posRestaurantPrintSlips(tableName);
                }
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
                $('#commonModal .modal-dialog').removeClass('modal-xl modal-lg').addClass('modal-sm');
                $('#commonModal .modal-title').html(title || '{{ __("pos.add_closing_balance") }}');
                $.ajax({
                    url: url,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    success: function (html) {
                        $('#commonModal .body').html(html);
                        $('#commonModal').modal('show');
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
                $.get(posRestaurantLayoutUrl, { branch_id: branchId }, function (res) {
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
                    var ids = Object.keys(data || {});
                    if (ids.length) {
                        var prevVal = $warehouse.val();
                        $warehouse.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(data, function(id, name) {
                            $warehouse.append($('<option></option>').val(id).text(name));
                        });
                        var wh = ids.indexOf(String(prevVal)) !== -1 ? prevVal : ids[0];
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
                        var $warehouse = $( '#warehouse' );
                        var ids = Object.keys(data || {});
                        if (!ids.length) {
                            return;
                        }
                        $warehouse.empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                        $.each(data, function(id, name) {
                            $warehouse.append($('<option></option>').val(id).text(name));
                        });
                        var wh = ids[0];
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

            $( '.customer_select' ).change(function() {
                $( "#vc_name_hidden" ).val($(this).val());
            });
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
                var ware_id = $( "#pos-restaurant-warehouse" ).val();
                var cat = $('#pos-restaurant-panel .cat-active').length ? $('#pos-restaurant-panel .cat-active').first().children().data('cat-id') : '0';
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

            function posRestaurantRunAddToCartAjax($el, $btn, wasDisabled, productVariantId, ctx) {
                var isRestaurant = ctx.isRestaurant;
                var tbodySel = ctx.tbodySel;
                var rowIdPrefix = ctx.rowIdPrefix;
                var totalSel = ctx.totalSel;
                var sum = 0;
                if ($btn.length && !wasDisabled) {
                    $btn.prop('disabled', true).addClass('pos-add-cart-loading');
                    var $iconGo = $btn.find('i');
                    if ($iconGo.length) $iconGo.removeClass('ti-plus').addClass('ti-loader ti-spin');
                }
                var addUrl = $el.data('url');
                var warehouseId = '';
                if (isRestaurant) {
                    warehouseId = $('#pos-restaurant-warehouse').length ? ($('#pos-restaurant-warehouse').val() || '') : '';
                }
                if (!warehouseId) {
                    warehouseId = $('#warehouse').length ? ($('#warehouse').val() || '') : '';
                }
                if (warehouseId) {
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
                                    var sub = (data.product.price * data.product.quantity * (1 + (data.product.tax || 0) / 100));
                                    existingR.find('.subtotal').text(typeof addCommas === 'function' ? addCommas(sub) : sub);
                                    existingR.attr('data-subtotal', sub).attr('data-quantity', data.product.quantity);
                                } else if (data.carthtml) {
                                    var $rowR = $(data.carthtml).attr('data-subtotal', data.product.subtotal);
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

            $(document).on('click', '#posVariantPickerConfirm', function () {
                var $modal = $('#posVariantPickerModal');
                var d = $modal.data('posVariantCtx');
                $modal.modal('hide');
                if (!d || !d.$el) return;
                var vid = $('#posVariantPickerSelect').val();
                if (!vid) return;
                posRestaurantRunAddToCartAjax(d.$el, d.$btn, d.wasDisabled, vid, d.ctx);
            });

            $(document).on('click', '.toacart', function () {
                var $el = $(this);
                var $btn = $el.find('.pos-add-cart-btn, button').first();
                var isRestaurant = $el.closest('#pos-restaurant-panel').length > 0;
                var tbodySel = isRestaurant ? '#tbody-restaurant' : '#tbody';
                var rowIdPrefix = isRestaurant ? 'product-id-r-' : 'product-id-';
                var totalSel = isRestaurant ? '#pos-restaurant-total' : '#displaytotal, .totalamount';
                var ctx = { isRestaurant: isRestaurant, tbodySel: tbodySel, rowIdPrefix: rowIdPrefix, totalSel: totalSel };

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
                        var row = isRestaurant
                            ? ('<tr data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '"><td class="name">' + (name.replace(/</g, '&lt;')) + '</td><td class="pos-note-cell"><textarea class="form-control form-control-sm notes pos-item-notes" placeholder="{{ __("pos.note_placeholder") }}" maxlength="200" rows="2" title="{{ __("Note") }}"></textarea></td><td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" title="{{ __("pos.quantity") }}" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus"></span></td><td class="tax">-</td><td class="price">' + addCommas(price) + '</td><td class="subtotal">' + addCommas(subtotal) + '</td><td class="text-center"><span class="badge pos-line-status">{{ __("pos.status_not_sent") }}</span></td><td class="action-btn"><a href="#" class="btn btn-sm bg-danger pos-offline-remove" data-id="' + id + '" title="{{ __("pos.delete") }}"><i class="ti ti-trash text-white"></i></a></td></tr>')
                            : ('<tr data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '"><td class="cart-images"><img alt="" src="' + imgSrc + '" class="card-image avatar shadow hover-shadow-lg"></td><td class="name">' + (name.replace(/</g, '&lt;')) + '</td><td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" title="{{ __("pos.quantity") }}" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus"></span></td><td class="tax">-</td><td class="price">' + addCommas(price) + '</td><td class="subtotal">' + addCommas(subtotal) + '</td><td class="action-btn"><a href="#" class="btn btn-sm bg-danger pos-offline-remove" data-id="' + id + '" title="{{ __("pos.delete") }}"><i class="ti ti-trash text-white"></i></a></td></tr>');
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
                if (navigator.onLine && String($el.data('variants-enabled')) === '1') {
                    var rawVL = $el.attr('data-variants');
                    var vlist = [];
                    try { vlist = rawVL ? JSON.parse(rawVL) : []; } catch (vex) { vlist = []; }
                    if (vlist.length) {
                        var $sel = $('#posVariantPickerSelect').empty();
                        vlist.forEach(function (v) {
                            var ps = (v.sale_price != null && v.sale_price !== '') ? (' — ' + v.sale_price) : '';
                            $sel.append($('<option/>').val(v.id).text((v.label || '') + ps));
                        });
                        $('#posVariantPickerModal').data('posVariantCtx', { $el: $el, $btn: $btn, wasDisabled: wasDisabled, ctx: ctx });
                        $('#posVariantPickerModal').modal('show');
                        return;
                    }
                }
                posRestaurantRunAddToCartAjax($el, $btn, wasDisabled, null, ctx);
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
                    $(tbodySel).html('<tr class="text-center no-found"><td colspan="' + (inRestaurant ? 7 : 7) + '">{{ __("pos.no_data_found") }}</td></tr>');
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
                        var tax = price * taxRate / 100;
                        var subtotal = (price + tax) * quantity;
                        $row.attr('data-subtotal', subtotal);
                        $row.find('.subtotal').text(addCommas(subtotal));
                        var total = 0;
                        $(tbodySel + ' tr[data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
                        if (inRestaurant) updateRestaurantCartTotals(total);
                        else { $('#displaytotal').text(addCommas(total)); $('.totalamount').text(addCommas(total)); }
                    } else if (quantity === 0) {
                        ele.closest('tr').remove();
                        if ($(tbodySel + ' tr[data-product-id]').length === 0) {
                            $(tbodySel).html('<tr class="text-center no-found"><td colspan="' + (inRestaurant ? 7 : 7) + '">{{ __("pos.no_data_found") }}</td></tr>');
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
                                        $(tbodySel).html('<tr class="text-center no-found"><td colspan="' + (inRestaurant ? 7 : 7) + '">{{ __("pos.no_data_found") }}</td></tr>');
                                        if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                                        else { $('#btn-pur button').attr('disabled', 'disabled'); $('.btn-empty button').removeClass('btn-clear-cart'); }
                                    }
                                }
                                $.each(data.product, function (key, value) {
                                    sum += value.subtotal;
                                    var $target = $('#' + rowIdPrefix + value.id);
                                    if (!$target.length && inRestaurant) $target = ele.closest('tr[data-product-id]');
                                    if ($target.length) {
                                        $target.find('.subtotal').text(addCommas(value.subtotal));
                                        $target.attr('data-subtotal', value.subtotal).attr('data-quantity', value.quantity);
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
                var warehouseVal = $('#pos_restaurant_warehouse_hidden').val() || $('#warehouse_name_hidden').val();
                var payload = (function() {
                    var d = {
                        vc_name: $('#vc_name_hidden').val(),
                        warehouse_name: warehouseVal,
                        discount: $('#discount_hidden').val(),
                        quotation_id: $('#quotation_id').val(),
                        payment_method: $('#payment_method').length ? $('#payment_method').val() : 'cash',
                        pos_order_type: $('#pos_order_type_hidden').val() || '',
                        pos_table_id: $('#pos_table_id_hidden').val() || '',
                        pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                        hotel_charge_enabled: $('#hotel_charge_enabled').length ? ($('#hotel_charge_enabled').is(':checked') ? 1 : 0) : 0,
                        hotel_folio_id: $('#hotel_folio_id').length ? ($('#hotel_folio_id').val() || '') : '',
                    };
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
                if (sk === 'pos-restaurant') {
                    if (typeof posRestaurantCartKey !== 'undefined' && posRestaurantCartKey) {
                        emptyData.cart_key = posRestaurantCartKey;
                    } else {
                        var tid = $('#pos_table_id_hidden').val();
                        var sid = $('#pos_shift_id_hidden').val();
                        if (tid && sid) { emptyData.table_id = tid; emptyData.shift_id = sid; }
                    }
                }
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: emptyData,
                    success: function () {
                        $('#tbody').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#displaytotal, .totalamount').text('0');
                        updateRestaurantCartTotals(0);
                        $('#btn-pur button').attr('disabled', 'disabled');
                        $('.btn-empty button').removeClass('btn-clear-cart');
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        var branchId = $('#pos-restaurant-branch').val();
                        if (branchId) loadRestaurantTables(branchId);
                        $('#pos_table_id_hidden').val('');
                        $('#pos_restaurant_table_id').val('');
                        $('#pos_restaurant_table_name').val('');
                        $('#pos-selected-table').text('—');
                        $('#pos-close-table-btn').addClass('d-none').data('table-id', '');
                        if (data && data.pos_id) {
                            var receiptUrl = '{{ url("printview/pos") }}?pos_id=' + data.pos_id + '&source=restaurant';
                            $.get(receiptUrl, function (html) {
                                $('#commonModal .modal-title').html('{{ __("pos.pos_invoice") }}');
                                $('#commonModal .modal-dialog').removeClass('modal-xl').addClass('modal-lg');
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
    </script>
    {{-- 30s inactivity → return to PIN screen (shared device) --}}
    @if(isset($posPinUser) && $posPinUser)
    <script>
        (function () {
            var INACTIVITY_SEC = 30;
            var timeoutId = null;
            var logoutUrl = '{{ route("pos.restaurant.logout-pin") }}';
            var redirectUrl = '{{ route("pos.restaurant.index") }}';
            function goToPinScreen() {
                document.body.removeEventListener('mousemove', resetTimer);
                document.body.removeEventListener('keydown', resetTimer);
                document.body.removeEventListener('click', resetTimer);
                document.body.removeEventListener('touchstart', resetTimer);
                if (timeoutId) clearTimeout(timeoutId);
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = logoutUrl;
                var csrf = document.createElement('input');
                csrf.name = '_token';
                csrf.value = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
            }
            function resetTimer() {
                if (timeoutId) clearTimeout(timeoutId);
                timeoutId = setTimeout(goToPinScreen, INACTIVITY_SEC * 1000);
            }
            document.body.addEventListener('mousemove', resetTimer);
            document.body.addEventListener('keydown', resetTimer);
            document.body.addEventListener('click', resetTimer);
            document.body.addEventListener('touchstart', resetTimer);
            timeoutId = setTimeout(goToPinScreen, INACTIVITY_SEC * 1000);
        })();
    </script>
    @endif
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