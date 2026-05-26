@extends('layouts.admin')
@section('page-title')
    {{__('Manage Product & Services')}}
@endsection
@push('css-page')
    <link rel="stylesheet" href="{{ asset('css/jquery.dataTables.min.css') }}">
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
                    { data: 6, name: 'category', orderable: false },
                    { data: 7, name: 'unit', orderable: false },
                    { data: 8, name: 'quantity', orderable: true },
                    { data: 9, name: 'location', orderable: false, searchable: false },
                    { data: 10, name: 'type', orderable: true },
                    { data: 11, name: 'action', orderable: false, searchable: false }
                ],
                columnDefs: [
                    { targets: [4, 5, 9, 11], orderable: false }
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

            $('#productservice-filter-reset').on('click', function (e) {
                e.preventDefault();
                setCategoryFilterValue('');
                reloadProductServiceTable();
            });
        });
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
@endsection
