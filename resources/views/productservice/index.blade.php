@extends('layouts.admin')
@section('page-title')
    {{__('Manage Product & Services')}}
@endsection
@push('css-page')
    <link rel="stylesheet" href="{{ asset('css/jquery.dataTables.min.css') }}">
    <style>
        .ss-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: #6d6a85; margin-bottom: 12px; }
        .ss-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 5px; }
        table.ss-chart { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.ss-chart th, table.ss-chart td { padding: 8px 10px; border-bottom: 1px solid #eef0f6; text-align: center; }
        table.ss-chart th { background: #f6f6fb; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        table.ss-chart th:first-child, table.ss-chart td:first-child { text-align: left; }
        table.ss-chart .ss-section td { background: #eef0fa; font-weight: 700; color: #51459d; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .ss-item-name { font-weight: 600; }
        .ss-item-sku { color: #9a97ad; font-size: 12px; }
        .ss-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #eceff1; color: #546e7a; }
        .ss-nostock { background: #f1f0f7; color: #6d6a85; border: 1px solid #d9d6e8; margin-left: 6px; }
        /* Fixed popup width at every screen size/zoom (Bootstrap's modal-xl
           steps 1140/800/500px at window breakpoints); the chart scrolls
           sideways instead of squeezing when there are many warehouses. */
        .ss-dialog { width: 96vw; max-width: 1200px; }
        table.ss-chart th, table.ss-chart td { white-space: nowrap; }
        table.ss-chart th:first-child, table.ss-chart td:first-child { white-space: normal; min-width: 170px; }
        .ss-b-product { background: #e3f2fd; color: #1565c0; }
        .ss-b-option { background: #ede7f6; color: #5e35b1; }
        .ss-qty { font-weight: 700; }
        .ss-zero { color: #d32f2f; font-weight: 700; background: #fdecea; }
        .ss-low { color: #e65100; font-weight: 700; }
        .ss-total { background: #fafafc; font-weight: 700; }
        .ss-sellbadge { display: inline-block; margin-top: 3px; padding: 1px 7px; border-radius: 20px; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .ss-can { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }
        .ss-cant { background: #fdecea; color: #b71c1c; border: 1px solid #ef9a9a; }
        .ss-transfer-panel { display: none; margin-top: 16px; padding: 14px; border: 1px solid #d9d6e8; border-radius: 8px; background: #fafafc; }
        .ss-transfer-panel .form-label { font-size: 12px; font-weight: 600; }
    </style>
@endpush
@push('script-page')
    <script src="{{ asset('js/jquery.dataTables.min.js') }}"></script>
    <script>
        $(document).ready(function () {
            var initialCategory = @json(request('category', ''));
            var categoryEl = document.getElementById('productservice-category');
            var categoryChoices = null;

            if (categoryEl && typeof Choices !== 'undefined') {
                categoryChoices = new Choices(categoryEl, {
                    searchEnabled: true,
                    itemSelectText: '',
                    shouldSort: false,
                });
            }

            function getCategoryFilterValue() {
                if (categoryChoices) {
                    var v = categoryChoices.getValue(true);
                    return (v === null || v === undefined) ? '' : String(v);
                }
                return $('#productservice-category').val() || '';
            }

            function setCategoryFilterValue(value) {
                if (categoryChoices) {
                    categoryChoices.setChoiceByValue(value ? String(value) : '');
                } else {
                    $('#productservice-category').val(value || '');
                }
            }

            function reloadProductServiceTable() {
                productServiceTable.page(0);
                productServiceTable.ajax.reload(null, false);
            }

            if (initialCategory) {
                setCategoryFilterValue(initialCategory);
            }

            var productServiceTable = $('#productservice-table').DataTable({
                processing: true,
                serverSide: true,
                deferRender: true,
                searchDelay: 450,
                ajax: {
                    url: '{{ route('productservice.datatable') }}',
                    type: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    data: function (d) {
                        d.category = getCategoryFilterValue();
                    }
                },
                order: [[0, 'asc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                columns: [
                    { data: 0, name: 'name', orderable: true },
                    { data: 1, name: 'sku', orderable: true },
                    { data: 2, name: 'sale_price', orderable: true },
                    { data: 3, name: 'purchase_price', orderable: true },
                    { data: 4, name: 'tax', orderable: false, searchable: false },
                    { data: 5, name: 'discount', orderable: false, searchable: false },
                    { data: 6, name: 'category', orderable: false, searchable: false },
                    { data: 7, name: 'unit', orderable: false, searchable: false },
                    { data: 8, name: 'quantity', orderable: true },
                    { data: 9, name: 'location', orderable: false, searchable: false },
                    { data: 10, name: 'type', orderable: true },
                    { data: 11, name: 'show_on_pos', orderable: false, searchable: false },
                    { data: 12, name: 'does_not_affect_stock', orderable: false, searchable: false },
                    { data: 13, name: 'action', orderable: false, searchable: false }
                ],
                drawCallback: function () {
                    if (typeof feather !== 'undefined') {
                        feather.replace();
                    }
                    var tooltipTriggerList = [].slice.call(document.querySelectorAll('#productservice-table [data-bs-toggle="tooltip"]'));
                    tooltipTriggerList.forEach(function (el) {
                        var existing = bootstrap.Tooltip.getInstance(el);
                        if (existing) {
                            existing.dispose();
                        }
                        new bootstrap.Tooltip(el);
                    });
                }
            });

            $('#productservice-category').on('change', function () {
                reloadProductServiceTable();
            });

            $('#productservice-filter-apply').on('click', function (e) {
                e.preventDefault();
                reloadProductServiceTable();
            });

            $(document).on('change', '#productservice-table .pos-show-toggle', function () {
                var $input = $(this);
                var id = $input.data('id');
                var show = $input.is(':checked') ? 1 : 0;
                $input.prop('disabled', true);
                $.ajax({
                    url: '{{ url('productservice') }}/' + id + '/show-on-pos',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: $('meta[name="csrf-token"]').attr('content'),
                        show_on_pos: show
                    },
                    success: function (res) {
                        if (res && typeof res.show_on_pos !== 'undefined') {
                            $input.prop('checked', !!res.show_on_pos);
                        }
                    },
                    error: function (xhr) {
                        $input.prop('checked', !show);
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to update. Please try again.') }}';
                        if (typeof show_toastr === 'function') {
                            show_toastr('Error', msg, 'error');
                        }
                    },
                    complete: function () {
                        $input.prop('disabled', false);
                    }
                });
            });

            $(document).on('change', '#productservice-table .affect-stock-toggle', function () {
                var $input = $(this);
                var id = $input.data('id');
                var doesNotAffect = $input.is(':checked') ? 1 : 0;
                $input.prop('disabled', true);
                $.ajax({
                    url: '{{ url('productservice') }}/' + id + '/affect-stock',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: $('meta[name="csrf-token"]').attr('content'),
                        does_not_affect_stock: doesNotAffect
                    },
                    success: function (res) {
                        if (res && typeof res.does_not_affect_stock !== 'undefined') {
                            $input.prop('checked', !!res.does_not_affect_stock);
                        }
                    },
                    error: function (xhr) {
                        $input.prop('checked', !doesNotAffect);
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to update. Please try again.') }}';
                        if (typeof show_toastr === 'function') {
                            show_toastr('Error', msg, 'error');
                        }
                    },
                    complete: function () {
                        $input.prop('disabled', false);
                    }
                });
            });

            $('#productservice-filter-reset').on('click', function (e) {
                e.preventDefault();
                setCategoryFilterValue('');
                reloadProductServiceTable();
            });

            $(document).off('click.productSkuGen', '.product-sku-generate-btn').on('click.productSkuGen', '.product-sku-generate-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var $input = $btn.closest('.input-group').find('.product-sku-input');
                if (!$input.length) {
                    return;
                }
                $btn.prop('disabled', true);
                $.ajax({
                    url: '{{ route('productservice.generate-sku') }}',
                    type: 'GET',
                    dataType: 'json',
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function (res) {
                        if (res && res.sku) {
                            $input.val(res.sku).trigger('change');
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to generate SKU. Please try again.') }}';
                        if (typeof show_toastr === 'function') {
                            show_toastr('Error', msg, 'error');
                        } else {
                            alert(msg);
                        }
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
    </script>
    <script>
        (function () {
            var ssCurrentId = null;
            var ssBaseUrl = '{{ url('productservice') }}';

            function ssEsc(s) {
                return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
            }

            function ssQty(q) {
                var n = Math.round((parseFloat(q) || 0) * 100) / 100;
                return String(n);
            }

            function ssCellClass(qty, threshold) {
                qty = parseFloat(qty) || 0;
                if (qty <= 0) {
                    return 'ss-zero';
                }
                if (threshold !== null && threshold !== undefined && threshold > 0 && qty <= threshold) {
                    return 'ss-low';
                }
                return '';
            }

            function ssTypeLabel(type) {
                if (!type) {
                    return '-';
                }
                return type.charAt(0).toUpperCase() + type.slice(1);
            }

            var ssUnlimited = 999999;

            function ssSellBadge(item, warehouseId) {
                var sellable = item.sellable ? item.sellable[warehouseId] : null;
                if (sellable === null || sellable === undefined) {
                    return '';
                }
                if (sellable >= ssUnlimited) {
                    return '<span class="ss-sellbadge ss-can">{{ __('Can sell') }}</span>';
                }
                var per = item.per_sale && item.per_sale > 0 ? parseFloat(item.per_sale) : 1;
                var times = Math.floor(sellable / per);
                if (times >= 1) {
                    return '<span class="ss-sellbadge ss-can">{{ __('Can sell') }} ×' + times + '</span>';
                }
                return '<span class="ss-sellbadge ss-cant">{{ __('Cannot sell') }}</span>';
            }

            function ssRowHtml(item, badgeLabel, badgeClass) {
                var noStock = item.affect_stock === false;
                var group = item.group ? ' <span class="ss-item-sku">(' + ssEsc(item.group) + ')</span>' : '';
                var tag = noStock ? '<span class="ss-badge ss-nostock">{{ __('Does not affect stock') }}</span>' : '';
                var html = '<tr>'
                    + '<td><span class="ss-item-name">' + ssEsc(item.name) + '</span> <span class="ss-item-sku">' + ssEsc(item.sku) + '</span>' + group + tag + '</td>'
                    + '<td><span class="ss-badge ' + badgeClass + '">' + ssEsc(badgeLabel) + '</span></td>'
                    + '<td>' + (item.per_sale === null || item.per_sale === undefined ? '—' : '× ' + ssQty(item.per_sale)) + '</td>';
                ssWarehouses.forEach(function (w) {
                    if (noStock) {
                        html += '<td>—</td>';
                        return;
                    }
                    var qty = item.stock ? (item.stock[w.id] || 0) : 0;
                    var threshold = (item.low_stock_thresholds && item.low_stock_thresholds[w.id] !== undefined && item.low_stock_thresholds[w.id] !== null)
                        ? item.low_stock_thresholds[w.id] : item.low_stock_threshold;
                    var cls = ssCellClass(qty, threshold);
                    html += '<td class="' + (cls || 'ss-qty') + '"><div>' + ssQty(qty) + '</div>' + ssSellBadge(item, w.id) + '</td>';
                });
                html += '<td class="ss-total">' + (noStock ? '—' : ssQty(item.total)) + '</td>';
                if (ssCanTransfer) {
                    html += '<td>' + (noStock
                        ? '<span class="text-muted small">—</span>'
                        : '<button type="button" class="btn btn-sm btn-outline-primary ss-transfer-btn" data-item-id="' + item.id + '" data-item-name="' + ssEsc(item.name) + '">{{ __('Transfer') }}</button>') + '</td>';
                }
                html += '</tr>';
                return html;
            }

            var ssWarehouses = [];
            var ssCanTransfer = false;
            var ssItemsById = {};
            var ssTransferItem = null;

            // One thing at a time in this popup: either the stock chart
            // or the transfer form — never both at once.
            function ssShowChart() {
                $('#stockStatusBody').show();
                $('.ss-legend').show();
                $('#stockStatusRefresh').show();
                $('#stockTransferPanel').hide();
            }

            function ssShowTransfer() {
                $('#stockStatusBody').hide();
                $('.ss-legend').hide();
                $('#stockStatusRefresh').hide();
                $('#stockTransferPanel').show();
            }

            function ssRender(data) {
                ssWarehouses = data.warehouses || [];
                ssCanTransfer = !!data.can_transfer;
                ssTransferItem = null;
                ssShowChart();
                if (data.unlimited_value) {
                    ssUnlimited = data.unlimited_value;
                }
                $('#stockStatusTitle').text('{{ __('Stock Status') }} — ' + data.product.name + ' (SKU ' + (data.product.sku || '-') + ')');

                if (!ssWarehouses.length) {
                    $('#stockStatusBody').html('<p class="text-muted text-center py-3">{{ __('No warehouses found.') }}</p>');
                    return;
                }

                // Lookup for the transfer panel (product + options + ingredients).
                ssItemsById = {};
                [data.product_stock].concat(data.options || [], data.ingredients || []).forEach(function (item) {
                    if (item) { ssItemsById[item.id] = item; }
                });

                var head = '<tr><th style="width:30%">{{ __('Item') }}</th><th>{{ __('Type') }}</th><th>{{ __('Per Sale') }}</th>';
                ssWarehouses.forEach(function (w) {
                    head += '<th>' + ssEsc(w.name) + '</th>';
                });
                head += '<th>{{ __('Total') }}</th>';
                if (ssCanTransfer) {
                    head += '<th>{{ __('Transfer') }}</th>';
                }
                head += '</tr>';

                var colspan = 3 + ssWarehouses.length + 1 + (ssCanTransfer ? 1 : 0);
                var body = '';
                body += '<tr class="ss-section"><td colspan="' + colspan + '">{{ __('Product') }}</td></tr>';
                body += ssRowHtml(data.product_stock, ssTypeLabel(data.product_stock.type), 'ss-b-product');

                if (data.options && data.options.length) {
                    body += '<tr class="ss-section"><td colspan="' + colspan + '">{{ __('Assigned Product Options') }}</td></tr>';
                    data.options.forEach(function (item) {
                        body += ssRowHtml(item, '{{ __('Option') }}', 'ss-b-option');
                    });
                }

                if (data.ingredients && data.ingredients.length) {
                    body += '<tr class="ss-section"><td colspan="' + colspan + '">{{ __('Recipe Ingredients') }}</td></tr>';
                    data.ingredients.forEach(function (item) {
                        body += ssRowHtml(item, ssTypeLabel(item.type), '');
                    });
                }

                $('#stockStatusBody').html(
                    '<div class="table-responsive"><table class="ss-chart"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>'
                );
            }

            function ssTransferAvail() {
                if (!ssTransferItem) { return; }
                var fromId = parseInt($('#ssTransferFrom').val(), 10);
                var qty = ssTransferItem.stock ? (ssTransferItem.stock[fromId] || 0) : 0;
                $('#ssTransferAvail').text('{{ __('Available in source') }}: ' + ssQty(qty));
            }

            function ssOpenTransfer(itemId) {
                var item = ssItemsById[itemId];
                if (!item || item.affect_stock === false) { return; }
                ssTransferItem = item;
                $('#ssTransferTitle').text('{{ __('Transfer') }} — ' + item.name + (item.sku ? ' (SKU ' + item.sku + ')' : ''));
                $('#stockStatusTitle').text('{{ __('Transfer') }} — ' + item.name + (item.sku ? ' (SKU ' + item.sku + ')' : ''));
                var fromHtml = '', toHtml = '';
                ssWarehouses.forEach(function (w) {
                    var qty = item.stock ? (item.stock[w.id] || 0) : 0;
                    fromHtml += '<option value="' + w.id + '">' + ssEsc(w.name) + ' (' + ssQty(qty) + ')</option>';
                    toHtml += '<option value="' + w.id + '">' + ssEsc(w.name) + '</option>';
                });
                $('#ssTransferFrom').html(fromHtml);
                $('#ssTransferTo').html(toHtml);
                // Default destination: first warehouse that is not the source.
                if (ssWarehouses.length > 1) {
                    $('#ssTransferTo').val(ssWarehouses[1].id);
                }
                var today = new Date().toISOString().slice(0, 10);
                $('#ssTransferDate').val(today);
                $('#ssTransferQty').val('');
                $('#ssTransferError').hide().text('');
                ssShowTransfer();
                ssTransferAvail();
            }

            $(document).on('click', '.ss-transfer-btn', function (e) {
                e.preventDefault();
                ssOpenTransfer(parseInt($(this).data('item-id'), 10));
            });

            $(document).on('change', '#ssTransferFrom', ssTransferAvail);

            $(document).on('click', '#ssTransferCancel', function () {
                ssTransferItem = null;
                ssShowChart();
            });

            $(document).on('click', '#ssTransferSubmit', function () {
                if (!ssTransferItem || !ssCurrentId) { return; }
                var $btn = $(this);
                var fromId = parseInt($('#ssTransferFrom').val(), 10);
                var toId = parseInt($('#ssTransferTo').val(), 10);
                var qty = parseFloat($('#ssTransferQty').val());
                if (!qty || qty <= 0) {
                    $('#ssTransferError').show().text('{{ __('Enter a quantity above 0.') }}');
                    return;
                }
                if (fromId === toId) {
                    $('#ssTransferError').show().text('{{ __('From and To warehouse must be different.') }}');
                    return;
                }
                $btn.prop('disabled', true);
                $.ajax({
                    url: ssBaseUrl + '/' + ssCurrentId + '/stock-transfer',
                    type: 'POST',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: {
                        item_id: ssTransferItem.id,
                        from_warehouse: fromId,
                        to_warehouse: toId,
                        quantity: qty,
                        date: $('#ssTransferDate').val()
                    },
                    success: function (res) {
                        if (typeof show_toastr === 'function') {
                            show_toastr('Success', res.success || '{{ __('Warehouse transfer created successfully.') }}', 'success');
                        }
                        ssLoad();
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to transfer stock.') }}';
                        $('#ssTransferError').show().text(msg);
                        if (typeof show_toastr === 'function') {
                            show_toastr('Error', msg, 'error');
                        }
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
            });

            function ssLoad() {
                if (!ssCurrentId) {
                    return;
                }
                $('#stockStatusBody').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');
                $.ajax({
                    url: ssBaseUrl + '/' + ssCurrentId + '/stock-status',
                    type: 'GET',
                    dataType: 'json',
                    success: function (data) {
                        ssRender(data);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to load stock status.') }}';
                        $('#stockStatusBody').html('<p class="text-danger text-center py-3">' + ssEsc(msg) + '</p>');
                    }
                });
            }

            $(document).on('click', '.stock-status-btn', function (e) {
                e.preventDefault();
                ssCurrentId = $(this).data('id');
                var modalEl = document.getElementById('stockStatusModal');
                var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
                ssLoad();
            });

            $(document).on('click', '#stockStatusRefresh', function () {
                ssLoad();
            });
        })();
    </script>
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Product & Services')}}</li>
@endsection
@section('action-btn')
    <div class="float-end">
        <a href="#" data-size="md"  data-bs-toggle="tooltip" title="{{__('Import')}}" data-url="{{ route('productservice.file.import') }}" data-ajax-popup="true" data-title="{{__('Import product CSV file')}}" class="btn btn-sm bg-brown-subtitle me-1">
            <i class="ti ti-file-import"></i>
        </a>
        <a href="{{route('productservice.export')}}" data-bs-toggle="tooltip" title="{{__('Export')}}" class="btn btn-sm btn-secondary me-1">
            <i class="ti ti-file-export"></i>
        </a>

        <a href="#" data-size="lg" data-url="{{ route('productservice.create') }}" data-ajax-popup="true" data-bs-toggle="tooltip" data-bs-original-title="{{__('Create')}}" data-title="{{__('Create New Product & Service')}}" class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i>
        </a>

    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="mt-2 show" id="multiCollapseExample1">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-end" id="product_service">
                            <div class="col-xl-3 col-lg-3 col-md-6 me-1">
                                <div class="btn-box">
                                    {{ Form::label('category', __('Category'),['class'=>'form-label']) }}
                                    {{ Form::select('category', $category, isset($_GET['category']) ? $_GET['category'] : '', ['class' => 'form-control', 'id' => 'productservice-category']) }}
                                </div>
                            </div>
                            <div class="col-auto float-end ms-2 mt-4">
                                <a href="#" class="btn btn-sm btn-primary me-1" id="productservice-filter-apply"
                                   data-bs-toggle="tooltip" title="{{ __('apply') }}">
                                    <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                </a>
                                <a href="#" class="btn btn-sm btn-danger" id="productservice-filter-reset" data-bs-toggle="tooltip"
                                   title="{{ __('Reset') }}">
                                    <span class="btn-inner--icon"><i class="ti ti-refresh "></i></span>
                                </a>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table" id="productservice-table" width="100%">
                            <thead>
                            <tr>
                                <th>{{__('Name')}}</th>
                                <th>{{__('Sku')}}</th>
                                <th>{{__('Sale Price')}}</th>
                                <th>{{__('Purchase Price')}}</th>
                                <th>{{__('Tax')}}</th>
                                <th>{{__('Discount Type')}}</th>
                                <th>{{__('Category')}}</th>
                                <th>{{__('Unit')}}</th>
                                <th>{{__('Quantity')}}</th>
                                <th>{{__('Location')}}</th>
                                <th>{{__('Type')}}</th>
                                <th>{{__('Show on POS')}}</th>
                                <th>{{__('Does not affect stock')}}</th>
                                <th>{{__('Action')}}</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="stockStatusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable ss-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="stockStatusTitle">{{ __('Stock Status') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="ss-legend">
                        <span><span class="ss-dot" style="background:#2e7d32"></span>{{ __('In stock') }}</span>
                        <span><span class="ss-dot" style="background:#e65100"></span>{{ __('Low — at or below the low stock alert') }}</span>
                        <span><span class="ss-dot" style="background:#d32f2f"></span>{{ __('Out of stock') }}</span>
                    </div>
                    <div id="stockStatusBody"></div>
                    <div id="stockTransferPanel" class="ss-transfer-panel">
                        <h6 id="ssTransferTitle" class="mb-3">{{ __('Transfer') }}</h6>
                        <div class="row">
                            <div class="col-md-3 form-group">
                                <label class="form-label" for="ssTransferFrom">{{ __('From Warehouse') }}</label>
                                <select class="form-control" id="ssTransferFrom"></select>
                                <small class="text-muted d-block mt-1" id="ssTransferAvail"></small>
                            </div>
                            <div class="col-md-3 form-group">
                                <label class="form-label" for="ssTransferTo">{{ __('To Warehouse') }}</label>
                                <select class="form-control" id="ssTransferTo"></select>
                            </div>
                            <div class="col-md-3 form-group">
                                <label class="form-label" for="ssTransferQty">{{ __('Quantity') }}</label>
                                <input type="number" step="0.0001" min="0.0001" class="form-control" id="ssTransferQty" placeholder="0">
                            </div>
                            <div class="col-md-3 form-group">
                                <label class="form-label" for="ssTransferDate">{{ __('Date') }}</label>
                                <input type="date" class="form-control" id="ssTransferDate">
                            </div>
                        </div>
                        <p class="text-danger small mb-2" id="ssTransferError" style="display:none"></p>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary btn-sm" id="ssTransferSubmit">{{ __('Transfer stock') }}</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="ssTransferCancel">{{ __('Back to stock status') }}</button>
                        </div>
                        <small class="text-muted d-block mt-2">{{ __('Same rules as Warehouse Transfer: From and To must differ, quantity cannot exceed stock in the source warehouse, and the move is recorded as a transfer document.') }}</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    <button type="button" class="btn btn-primary" id="stockStatusRefresh"><i class="ti ti-refresh"></i> {{ __('Refresh') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="warehouseStockAlertModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="wsaTitle">{{ __('Low / max stock per warehouse') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small" id="wsaSubtitle"></p>
                    <div id="wsaBody"></div>
                    <p class="text-danger small mb-0" id="wsaError" style="display:none"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    <button type="button" class="btn btn-primary" id="wsaSave">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('script-page')
    <script>
        (function () {
            var wsaCurrentId = null;
            var wsaBaseUrl = '{{ url('productservice') }}';

            function wsaEsc(s) {
                return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
            }

            function wsaLoad() {
                if (!wsaCurrentId) {
                    return;
                }
                $('#wsaBody').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');
                $('#wsaError').hide().text('');
                $('#wsaSave').prop('disabled', true);
                $.ajax({
                    url: wsaBaseUrl + '/' + wsaCurrentId + '/warehouse-stock-alerts',
                    type: 'GET',
                    dataType: 'json',
                    success: function (data) {
                        var p = data.product || {};
                        $('#wsaTitle').text('{{ __('Low / max stock per warehouse') }} — ' + wsaEsc(p.name || ''));
                        var lowNote = (p.low_stock_threshold !== null && p.low_stock_threshold !== undefined) ? p.low_stock_threshold : '{{ __('Default') }}';
                        var maxNote = (p.max_stock_threshold !== null && p.max_stock_threshold !== undefined) ? p.max_stock_threshold : '{{ __('Optional') }}';
                        $('#wsaSubtitle').text('{{ __('Leave a value empty to use the product value (Low: ') }}' + lowNote + '{{ __(', Max: ') }}' + maxNote + '{{ __(') or the system default.') }}');
                        var rows = data.warehouses || [];
                        if (rows.length === 0) {
                            $('#wsaBody').html('<p class="text-muted text-center py-3">{{ __('No warehouses found for this company.') }}</p>');
                            return;
                        }
                        var html = '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr>'
                            + '<th>{{ __('Warehouse') }}</th>'
                            + '<th>{{ __('Current stock') }}</th>'
                            + '<th>{{ __('Low stock alert at') }}</th>'
                            + '<th>{{ __('Max stock alert at') }}</th>'
                            + '</tr></thead><tbody>';
                        rows.forEach(function (w) {
                            html += '<tr data-warehouse-id="' + w.id + '">'
                                + '<td>' + wsaEsc(w.name) + '</td>'
                                + '<td>' + wsaEsc(w.current_quantity) + '</td>'
                                + '<td><input type="number" min="0" step="1" class="form-control form-control-sm wsa-low" value="' + (w.low_stock_threshold !== null && w.low_stock_threshold !== undefined ? w.low_stock_threshold : '') + '" placeholder="' + wsaEsc(lowNote) + '"></td>'
                                + '<td><input type="number" min="0" step="1" class="form-control form-control-sm wsa-max" value="' + (w.max_stock_threshold !== null && w.max_stock_threshold !== undefined ? w.max_stock_threshold : '') + '" placeholder="' + wsaEsc(maxNote) + '"></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table></div>';
                        $('#wsaBody').html(html);
                        $('#wsaSave').prop('disabled', false);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to load warehouse stock alerts.') }}';
                        $('#wsaBody').html('<p class="text-danger text-center py-3">' + wsaEsc(msg) + '</p>');
                    }
                });
            }

            $(document).on('click', '.warehouse-stock-alert-btn', function (e) {
                e.preventDefault();
                wsaCurrentId = $(this).data('id');
                var modalEl = document.getElementById('warehouseStockAlertModal');
                var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
                wsaLoad();
            });

            $(document).on('click', '#wsaSave', function () {
                if (!wsaCurrentId) {
                    return;
                }
                var $btn = $(this);
                var warehouses = [];
                $('#wsaBody tr[data-warehouse-id]').each(function () {
                    var $row = $(this);
                    warehouses.push({
                        warehouse_id: parseInt($row.data('warehouse-id'), 10),
                        low_stock_threshold: $row.find('.wsa-low').val(),
                        max_stock_threshold: $row.find('.wsa-max').val()
                    });
                });
                $('#wsaError').hide().text('');
                $btn.prop('disabled', true);
                $.ajax({
                    url: wsaBaseUrl + '/' + wsaCurrentId + '/warehouse-stock-alerts',
                    type: 'POST',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: { warehouses: warehouses },
                    success: function (res) {
                        if (typeof show_toastr === 'function') {
                            show_toastr('Success', res.success || '{{ __('Warehouse stock alerts saved.') }}', 'success');
                        }
                        var modalEl = document.getElementById('warehouseStockAlertModal');
                        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __('Unable to save warehouse stock alerts.') }}';
                        $('#wsaError').show().text(msg);
                        if (typeof show_toastr === 'function') {
                            show_toastr('Error', msg, 'error');
                        }
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
            });
        })();
    </script>
@endpush
