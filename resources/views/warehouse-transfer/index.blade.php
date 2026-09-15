@extends('layouts.admin')
@section('page-title')
    {{ __('Warehouse Transfer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Warehouse Transfer') }}</li>
@endsection
@section('action-btn')
    <div class="float-end">
        @if(Auth::user()->can('create warehouse') || Auth::user()->can('menu.products_system.transfer'))
        <a href="#" data-size="lg" data-url="{{ route('warehouse-transfer.create') }}" data-ajax-popup="true"
            data-bs-toggle="tooltip" title="{{ __('Create') }}" data-title="{{ __('Create Warehouse Transfer') }}"
            class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i>
        </a>
        @endif
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Batch') }}</th>
                                    <th>{{ __('From Warehouse') }}</th>
                                    <th>{{ __('To Warehouse') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Quantity') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>

                                @foreach ($warehouse_transfers as $warehouse_transfer)
                                    <tr class="font-style">
                                        <td>
                                            @if(!empty($warehouse_transfer->transfer_batch_id))
                                                <span class="badge bg-light text-dark border" title="{{ $warehouse_transfer->transfer_batch_id }}">{{ \Illuminate\Support\Str::limit($warehouse_transfer->transfer_batch_id, 10, '…') }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ !empty($warehouse_transfer->fromWarehouse) ? $warehouse_transfer->fromWarehouse->name : '' }}
                                        </td>
                                        <td>{{ !empty($warehouse_transfer->toWarehouse) ? $warehouse_transfer->toWarehouse->name : '' }}
                                        </td>
                                        <td>
                                            @if(!empty($warehouse_transfer->product))
                                                {{ $warehouse_transfer->product->name }}@if(method_exists($warehouse_transfer->product, 'trashed') && $warehouse_transfer->product->trashed()) <span class="text-muted">({{ __('Product deleted') }})</span>@endif
                                            @else
                                                {{ __('Product deleted') }}
                                            @endif
                                        </td>
                                        <td>{{ $warehouse_transfer->quantity }}</td>
                                        <td>{{ Auth::user()->dateFormat($warehouse_transfer->date) }}</td>
                                        <td class="Action">
                                            <span class="d-flex align-items-center">
                                                <div class="action-btn me-2">
                                                    <a href="{{ route('warehouse-transfer.print', $warehouse_transfer->id) }}"
                                                        target="_blank"
                                                        class="mx-3 btn btn-sm align-items-center bg-primary"
                                                        data-bs-toggle="tooltip" title="{{ __('Print') }}">
                                                        <i class="ti ti-printer text-white"></i>
                                                    </a>
                                                </div>
                                                @if(Auth::user()->can('delete warehouse') || Auth::user()->can('menu.products_system.transfer'))
                                                    <div class="action-btn">
                                                        {!! Form::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['warehouse-transfer.destroy', $warehouse_transfer->id],
                                                            'id' => 'delete-form-' . $warehouse_transfer->id,
                                                        ]) !!}
                                                        <a href="#"
                                                            class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                                                            data-bs-toggle="tooltip" title="{{ __('Delete') }}"><i
                                                                class="ti ti-trash text-white"></i></a>
                                                        {!! Form::close() !!}
                                                    </div>
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach

                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        (function () {
            var wtLineIndex = 0;

            function wtFillProductSelects($root, wareProducts) {
                $root.find('.wt-product-select').each(function () {
                    var $sel = $(this);
                    var v = $sel.val();
                    $sel.empty().append('<option value="">{{ __('Select Product') }}</option>');
                    $.each(wareProducts, function (key, value) {
                        $sel.append('<option value="' + key + '">' + value + '</option>');
                    });
                    if (v) {
                        $sel.val(v);
                    }
                });
            }

            function wtRefreshRemoveButtons($root) {
                var n = $root.find('#wt-lines-body tr').length;
                $root.find('.wt-remove-line').toggleClass('d-none', n <= 1);
            }

            $(document).on('change', '#commonModal .wt-from-warehouse, #commonModal #wt_warehouse_id', function () {
                var wid = $(this).val();
                var $form = $(this).closest('form');
                if (!wid) return;
                $.ajax({
                    url: '{{ route('warehouse-transfer.getproduct') }}',
                    type: 'POST',
                    data: { warehouse_id: wid, _token: '{{ csrf_token() }}' },
                    success: function (data) {
                        wtFillProductSelects($form, data.ware_products || {});
                        var $to = $form.find('select[name="to_warehouse"]');
                        $to.empty();
                        $.each(data.to_warehouses || {}, function (key, value) {
                            $to.append('<option value="' + key + '">' + value + '</option>');
                        });
                    }
                });
            });

            $(document).on('change', '#commonModal .wt-product-select', function () {
                var pid = $(this).val();
                var $row = $(this).closest('tr');
                var $form = $(this).closest('form');
                var wid = $form.find('#wt_warehouse_id').val();
                var $hint = $row.find('.wt-max-hint');
                var $qty = $row.find('.wt-qty-input');
                if (!pid || !wid) {
                    $hint.addClass('d-none').text('');
                    return;
                }
                $.ajax({
                    url: '{{ route('warehouse-transfer.getquantity') }}',
                    type: 'POST',
                    data: { product_id: pid, warehouse_id: wid, _token: '{{ csrf_token() }}' },
                    success: function (max) {
                        var m = parseFloat(max);
                        if (!isNaN(m) && m >= 0) {
                            $qty.attr('max', m);
                            $hint.removeClass('d-none').text('{{ __('Max in source') }}: ' + m);
                        } else {
                            $hint.addClass('d-none').text('');
                        }
                    }
                });
            });

            $(document).on('click', '#commonModal #wt-add-line', function (e) {
                e.preventDefault();
                var $form = $(this).closest('form');
                var $tbody = $form.find('#wt-lines-body');
                var $first = $tbody.find('tr').first();
                wtLineIndex++;
                var idx = $tbody.find('tr').length;
                var $tr = $first.clone();
                $tr.find('input,select').val('');
                $tr.find('.wt-max-hint').addClass('d-none').text('');
                $tr.find('.wt-product-select').attr('name', 'items[' + idx + '][product_id]');
                $tr.find('.wt-qty-input').attr('name', 'items[' + idx + '][quantity]');
                $tbody.append($tr);
                wtRefreshRemoveButtons($form);
                var wid = $form.find('#wt_warehouse_id').val();
                if (wid) {
                    $form.find('.wt-from-warehouse').trigger('change');
                }
            });

            $(document).on('click', '#commonModal .wt-remove-line', function (e) {
                e.preventDefault();
                var $form = $(this).closest('form');
                var $tbody = $form.find('#wt-lines-body');
                if ($tbody.find('tr').length <= 1) return;
                $(this).closest('tr').remove();
                $tbody.find('tr').each(function (i) {
                    $(this).find('.wt-product-select').attr('name', 'items[' + i + '][product_id]');
                    $(this).find('.wt-qty-input').attr('name', 'items[' + i + '][quantity]');
                });
                wtRefreshRemoveButtons($form);
            });

            $(document).on('shown.bs.modal', '#commonModal', function () {
                var $f = $('#commonModal #warehouse-transfer-form');
                if ($f.length) {
                    wtLineIndex = 0;
                    wtRefreshRemoveButtons($f);
                }
            });
        })();
    </script>
@endpush
