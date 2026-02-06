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
        {{ !empty($companySettings['header_text']) ? $companySettings['header_text']->value : config('app.name', 'ERPGO SaaS') }}
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

    </style>
</head>


<body class="{{ $themeColor }}">
    <div id="pos-offline-banner" class="alert alert-warning py-2 mb-0 rounded-0 text-center d-none" role="alert" style="z-index: 9998;">
        <span class="ti ti-wifi-off me-1"></span> {{ __('pos.offline_banner') }}
    </div>
@php
    $today_balance = \App\Models\PosBalance::where('user_id', \Auth::user()->creatorId())
        ->whereDate('created_at', \Carbon\Carbon::today())
        ->first();
    

@endphp
    <div class="pos-page container-fluid px-2">
        <?php $lastsegment = request()->segment(count(request()->segments())); ?>
        <div class="row">
            <div class="col-12">
                <div class="mt-2 pos-top-bar bg-primary d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="text-white pos-brand">{{ __('POS Restaurant') }}</span>
                        @if($today_balance && $today_balance->opening_balance)
                            <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.opening_balance') }}:</strong> {{ Auth::user()->priceFormat($today_balance->opening_balance) }}</span>
                        @else
                            <a href="#" data-url="{{ route('pos.add_opening_balance') }}" data-bs-toggle="tooltip" title="{{ __('pos.add_opening_balance') }}" data-ajax-popup="true" data-title="{{ __('pos.add_opening_balance') }}" class="btn btn-sm btn-light">{{ __('pos.add_opening_balance') }}</a>
                        @endif
                        @if($today_balance && $today_balance->closing_balance)
                            <span class="ms-2 ms-lg-3 text-white opacity-90"><strong>{{ __('pos.closing_balance') }}:</strong> {{ Auth::user()->priceFormat($today_balance->closing_balance) }}</span>
                        @else
                            <a href="#" data-url="{{ route('pos.add_closing_balance') }}" data-bs-toggle="tooltip" title="{{ __('pos.add_closing_balance') }}" data-ajax-popup="true" data-title="{{ __('pos.add_closing_balance') }}" class="btn btn-sm btn-light">{{ __('pos.add_closing_balance') }}</a>
                        @endif
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span id="pos-pending-badge" class="badge bg-warning text-dark d-none" style="font-size: 0.8rem; cursor: pointer;" data-msg="{{ __('pos.pending_sync_label') }}" title="{{ __('pos.sync_now') }}"></span>
                        <button type="button" id="pos-install-app-btn" class="btn btn-sm btn-light" title="{{ __('pos.install_app') }}">
                            <i class="ti ti-download" style="font-size: 1.1rem;"></i> <span class="d-none d-sm-inline">{{ __('pos.install_app') }}</span>
                        </button>
                        <a href="{{ route('pos.restaurant.index') }}" class="btn btn-sm btn-light me-1" title="{{ __('POS Restaurant') }}"><i class="ti ti-building-store" style="font-size: 1.25rem;"></i></a>
                        <a href="{{ route('pos.index') }}" class="btn btn-sm btn-outline-light" title="{{ __('POS Retail') }}"><i class="ti ti-shopping-cart" style="font-size: 1.25rem;"></i></a>
                        <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light" title="{{ __('pos.dashboard') }}"><i class="ti ti-home" style="font-size: 1.25rem;"></i></a>
                    </div>
                </div>
            </div>
        </div>
        @php
            $posMode = $posMode ?? 'restaurant';
            $showModeTabs = false;
        @endphp
        {{-- Restaurant-only: no mode tabs, only restaurant panel --}}
        <div id="pos-retail-panel" class="pos-mode-panel d-none">
        <div class="mt-2 row mb-4 gy-2 main_div">
            @if(!$today_balance || !$today_balance->opening_balance)
                <div class="disabled_div">
                    <h3>Add Opening Balance to Enable the transactions</h3>
                </div>
            @endif
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
                                {{ Form::select('branch_id', $branches, $defaultBranchId, ['class' => 'form-control select branch_select', 'id' => 'branch_id', 'placeholder' => __('Select Branch')]) }}
                            </div>
                            @endif
                            <div id="pos-restaurant-options" class="col-12 row gy-1 {{ empty($restaurantBranchIds) ? 'd-none' : '' }}">
                                <div class="col-sm-6 pr-1">
                                    {{ Form::select('pos_order_type', ['dine_in' => __('pos.dine_in'), 'takeaway' => __('pos.takeaway'), 'delivery' => __('pos.delivery'), 'kiosk' => __('pos.kiosk')], null, ['class' => 'form-control select', 'id' => 'pos_order_type', 'placeholder' => __('pos.select_order_type')]) }}
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
                                                $netPrice += $details['netPrice'];
                                                $discount += $details['discount'];
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

        <div id="pos-restaurant-panel" class="pos-mode-panel mt-2">
            @include('pos.restaurant')
        </div>{{-- /pos-restaurant-panel --}}
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
        var posEmptyCartUrl = '{{ route("warehouse-empty-cart") }}';
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
        $(document).ready(function () {
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
                var $select = $('#pos-shift-select');
                var $hint = $('#pos-shift-hint');
                $select.find('option:not(:first)').remove();
                $('#pos_shift_id_hidden').val('');
                if (!branchId) {
                    $hint.text('{{ __("pos.select_branch_first") }}').show();
                    return;
                }
                $hint.text('').hide();
                $.ajax({
                    url: posBranchOpenShiftsUrl,
                    data: { branch_id: branchId },
                    dataType: 'json',
                    success: function (res) {
                        var shifts = res && res.shifts ? res.shifts : [];
                        var currentUserShiftId = null;
                        shifts.forEach(function (s) {
                            var label = s.user_name + ' — #' + s.id + (s.opened_at ? ' (' + s.opened_at + ')' : '');
                            $select.append($('<option></option>').attr('value', s.id).attr('data-user-id', s.user_id).text(label));
                            if (s.is_current_user) currentUserShiftId = s.id;
                        });
                        if (currentUserShiftId) {
                            $select.val(currentUserShiftId);
                        } else if (shifts.length > 0) {
                            $select.val(shifts[0].id);
                        }
                        $('#pos_shift_id_hidden').val($select.val() || '');
                        if (shifts.length === 0) {
                            $hint.text('{{ __("pos.no_open_shifts") }}').show();
                        }
                    },
                    error: function () {
                        $hint.text('{{ __("pos.no_shift") }}').show();
                    }
                });
            }

            $('#pos-shift-select').on('change', function () {
                $('#pos_shift_id_hidden').val($(this).val() || '');
            });

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
                            $('#pos-restaurant-total').text('0');
                            $('#pos-pay-btn-restaurant').prop('disabled', false);
                        }
                    });
                } else {
                    $('#pos-restaurant-warehouse').empty().append('<option value="">{{ __("pos.select_warehouse") }}</option>');
                    $('#pos_restaurant_warehouse_hidden').val('');
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
                    searchProducts(posSearchProductsUrl, '', '0', wh, 'name', 'product-listing-restaurant');
                    getProductCategoriesRestaurant();
                }
                var sk = $( "#empty_cart" ).val() || sessionKey;
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: { session_key: sk },
                    success: function () {
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#pos-restaurant-total').text('0');
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                    }
                });
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
                            var label = t.name || ('Table ' + t.id);
                            if (t.current_guests != null && t.current_guests !== '') label += ' (' + t.current_guests + ')';
                            html += '<button type="button" class="btn ' + statusClass + ' btn-sm pos-table-btn" data-id="' + t.id + '" data-name="' + (t.name || '') + '" data-status="' + (t.status || '') + '">' + label + '</button>';
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
                var status = $(this).data('status');
                if (status === 'available') {
                    $('#pos-open-table-id').val(id);
                    $('#pos-open-table-name').val(name || ('Table ' + id));
                    $('#pos-open-table-guests').val('');
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
            function selectRestaurantTable(id, name, status) {
                $('#pos_restaurant_table_id').val(id);
                $('#pos_restaurant_table_name').val(name);
                $('#pos_table_id_hidden').val(id);
                $('#pos_order_type_hidden').val('dine_in');
                $('#pos-selected-table').text(name || ('Table ' + id));
                var $closeBtn = $('#pos-close-table-btn');
                $closeBtn.data('table-id', id).data('table-status', status || '');
                $closeBtn.toggleClass('d-none', (status || '') !== 'occupied');
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

            $(document).on('click', '#pos-pay-btn-restaurant', function (e) {
                var tableId = $('#pos_table_id_hidden').val() || $('#pos_restaurant_table_id').val();
                if (!tableId) {
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
                if ($('#discount_hidden').length) data['discount'] = $('#discount_hidden').val();
                if ($('#quotation_id').length) data['quotation_id'] = $('#quotation_id').val();
                if ($('#pos_order_type_hidden').length) data['pos_order_type'] = $('#pos_order_type_hidden').val();
                if ($('#pos_table_id_hidden').length) data['pos_table_id'] = $('#pos_table_id_hidden').val();
                if ($('#pos_shift_id_hidden').length) data['pos_shift_id'] = $('#pos_shift_id_hidden').val();
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
                var kitchenRows = [], barRows = [];
                $('#tbody-restaurant tr[data-product-id]').each(function () {
                    var name = $(this).attr('data-name') || $(this).find('.name').text();
                    var qty = parseInt($(this).find('input[name="quantity"]').val() || 1, 10);
                    var zone = ($(this).attr('data-print-zone') || '').toLowerCase();
                    var item = { name: name, qty: qty };
                    if (zone === 'bar') barRows.push(item); else kitchenRows.push(item);
                });
                var allRows = kitchenRows.concat(barRows);
                if (allRows.length === 0) {
                    show_toastr('{{ __("Error") }}', '{{ __("pos.no_data_found") }}', 'error');
                    return;
                }
                function printSlip(title, rows) {
                    if (rows.length === 0) return;
                    var w = window.open('', '_blank', 'width=300,height=400');
                    w.document.write('<html><head><title>' + title + '</title><style>body{font-family:monospace;padding:12px;font-size:14px;}h3{margin:0 0 8px;}table{width:100%;}td{padding:4px 0;}td:last-child{text-align:right;}</style></head><body>');
                    w.document.write('<h3>' + title + '</h3>');
                    w.document.write('<p><strong>{{ __("pos.table") }}:</strong> ' + (tableName || '—') + '</p>');
                    w.document.write('<p><small>' + new Date().toLocaleString() + '</small></p>');
                    w.document.write('<table><thead><tr><th>{{ __("pos.name") }}</th><th>{{ __("pos.qty") }}</th></tr></thead><tbody>');
                    rows.forEach(function(r) {
                        w.document.write('<tr><td>' + (r.name || '').replace(/</g, '&lt;') + '</td><td>' + r.qty + '</td></tr>');
                    });
                    w.document.write('</tbody></table></body></html>');
                    w.document.close();
                    w.focus();
                    w.print();
                    w.close();
                }
                printSlip('{{ __("pos.kitchen_slip") }}', kitchenRows);
                if (barRows.length > 0) setTimeout(function() { printSlip('{{ __("pos.bar_slip") }}', barRows); }, 400);
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

            $(document).on('keyup', 'input.searchproduct', function() {
                var url = $(this).data('url');
                var value = this.value;
                var ware_id = $( "#warehouse" ).val();
                if (value != '') {
                    var cat = $('.cat-active').children().data('cat-id');
                    searchProducts(url, value, cat, ware_id, 'name');
                }
            });

            $(document).on('keyup', 'input.searchproduct-restaurant', function() {
                var url = $(this).data('url');
                var value = this.value;
                var ware_id = $( "#pos-restaurant-warehouse" ).val();
                var cat = $('#pos-restaurant-panel .cat-active').length ? $('#pos-restaurant-panel .cat-active').first().children().data('cat-id') : '0';
                searchProducts(url, value, cat, ware_id, 'name', 'product-listing-restaurant');
            });

            $(document).on('keyup', 'input.barcodeScanner', function() {
                var url = $(this).data('url');
                var value = this.value;
                var ware_id = $( "#warehouse" ).val();
                if (value != '') {
                    var cat = $('.cat-active').children().data('cat-id');
                    searchProducts(url, value, cat,ware_id, 'sku');
                }
            });


            function searchProducts(url, value, cat_id, war_id, type, targetId) {
                war_id = war_id || '0';
                type = type || 'name';
                targetId = targetId || 'product-listing';
                var cacheKey = posProductCacheKey(war_id, cat_id, value, type);
                function renderProducts(html) {
                    $('#' + targetId).html(html);
                }
                function showOfflineNoProducts() {
                    renderProducts('<div class="card card-body col-12 text-center"><h5>{{ __("pos.offline_no_products") }}</h5><small>{{ __("pos.offline_load_first") }}</small></div>');
                }
                if (!navigator.onLine) {
                    var cached = posGetCachedProducts(cacheKey);
                    if (cached) renderProducts(cached);
                    else showOfflineNoProducts();
                    return;
                }
                $.ajax({
                    type: 'GET',
                    url: url,
                    data: { 'search': value, 'cat_id': cat_id, 'war_id': war_id, 'session_key': session_key, 'type': type },
                    success: function(data) {
                        posSetCachedProducts(cacheKey, data);
                        renderProducts(data);
                    },
                    error: function() {
                        var cached = posGetCachedProducts(cacheKey);
                        if (cached) renderProducts(cached);
                        else showOfflineNoProducts();
                    }
                });
            }

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

            $(document).on('click', '.toacart', function () {
                var $el = $(this);
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
                        var row = '<tr data-product-id="' + id + '" id="' + rowIdPrefix + id + '" data-name="' + (name.replace(/"/g, '&quot;')) + '" data-price="' + price + '" data-quantity="' + qty + '" data-tax="' + taxRate + '" data-subtotal="' + subtotal + '" data-discount="0" data-netprice="' + subtotal + '"><td class="cart-images"><img alt="" src="' + imgSrc + '" class="card-image avatar shadow hover-shadow-lg"></td><td class="name">' + (name.replace(/</g, '&lt;')) + '</td><td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" title="{{ __("pos.quantity") }}" class="input-number" data-url="{{ url('update-cart/') }}" data-id="' + id + '" value="1"><input type="button" value="+" class="plus"></span></td><td class="tax">-</td><td class="price">' + addCommas(price) + '</td><td class="subtotal">' + addCommas(subtotal) + '</td><td class="action-btn"><a href="#" class="btn btn-sm bg-danger pos-offline-remove" data-id="' + id + '" title="{{ __("pos.delete") }}"><i class="ti ti-trash text-white"></i></a></td></tr>';
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
                    $(totalSel).text(addCommas(total));
                    return;
                }
                var sum = 0;
                var addUrl = $el.data('url');
                if (isRestaurant) addUrl += (addUrl.indexOf('?') !== -1 ? '&' : '?') + 'restaurant=1';
                $.ajax({
                    url: addUrl,
                    success: function (data) {
                        if (data.code == '200') {
                            if (isRestaurant) {
                                var existing = $(tbodySel + ' tr[id="' + rowIdPrefix + data.product.id + '"]');
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
                                $(tbodySel + ' tr[data-product-id]').each(function() { sum += parseFloat($(this).attr('data-subtotal')) || 0; });
                                $('#pos-restaurant-total').text(addCommas(sum));
                            } else {
                                if ('carttotal' in data) {
                                    $.each(data.carttotal, function (key, value) {
                                        $('#product-id-' + value.id + ' .subtotal').text(addCommas(value.subtotal));
                                        sum += value.subtotal;
                                    });
                                    $('#displaytotal').text(addCommas(sum));
                                    $('.totalamount').text(addCommas(sum));
                                    $('.discount').val('');
                                } else if (data.carthtml) {
                                    $('#tbody').append(data.carthtml);
                                    $('.no-found').addClass('d-none');
                                    $('.carttable #product-id-' + data.product.id + ' input[name="quantity"]').val(data.product.quantity);
                                }
                                $('#btn-pur button').removeAttr('disabled');
                                $('.btn-empty button').addClass('btn-clear-cart');
                            }
                        }
                    },
                    error: function (data) {
                        data = data.responseJSON || {};
                        show_toastr('{{ __("Error") }}', data.error || '{{ __("pos.error") }}', 'error');
                    }
                });
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
                    $(tbodySel).html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                    if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                    else { $('#btn-pur button').attr('disabled', 'disabled'); $('.btn-empty button').removeClass('btn-clear-cart'); }
                }
                var total = 0;
                $(tbodySel + ' tr[data-product-id]').each(function() { total += parseFloat($(this).attr('data-subtotal')) || 0; });
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
                        if (inRestaurant) $('#pos-restaurant-total').text(addCommas(total));
                        else { $('#displaytotal').text(addCommas(total)); $('.totalamount').text(addCommas(total)); }
                    } else if (quantity === 0) {
                        ele.closest('tr').remove();
                        if ($(tbodySel + ' tr[data-product-id]').length === 0) {
                            $(tbodySel).html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
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
                        data: {
                            id: ele.attr("data-id"),
                            quantity: quantity,
                            discount: discount,
                            session_key: session_key
                        },
                        success: function (data) {
                            if (data.code == '200') {
                                if (quantity == 0) {
                                    var $tr = ele.closest('tr[data-product-id]');
                                    $tr.remove();
                                    if ($(tbodySel + ' tr[data-product-id]').length === 0) {
                                        $(tbodySel).html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                                        if (inRestaurant) $('#pos-pay-btn-restaurant').prop('disabled', true);
                                        else { $('#btn-pur button').attr('disabled', 'disabled'); $('.btn-empty button').removeClass('btn-clear-cart'); }
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
                $.ajax({
                    url: ele.data('url') || '{{ route("pos.data.store") }}',
                    method: 'GET',
                    data: {
                        vc_name: $('#vc_name_hidden').val(),
                        warehouse_name: warehouseVal,
                        discount: $('#discount_hidden').val(),
                        quotation_id: $('#quotation_id').val(),
                        payment_method: $('#payment_method').length ? $('#payment_method').val() : 'cash',
                        pos_order_type: $('#pos_order_type_hidden').val() || '',
                        pos_table_id: $('#pos_table_id_hidden').val() || '',
                        pos_shift_id: $('#pos_shift_id_hidden').val() || '',
                    },
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
                $.ajax({
                    type: 'POST',
                    url: posEmptyCartUrl,
                    data: { session_key: sk },
                    success: function () {
                        $('#tbody').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#tbody-restaurant').html('<tr class="text-center no-found"><td colspan="7">{{ __("pos.no_data_found") }}</td></tr>');
                        $('#displaytotal, .totalamount').text('0');
                        $('#pos-restaurant-total').text('0');
                        $('#btn-pur button').attr('disabled', 'disabled');
                        $('.btn-empty button').removeClass('btn-clear-cart');
                        $('#pos-pay-btn-restaurant').prop('disabled', true);
                        if ($('#commonModal').length) $('#commonModal').modal('hide');
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