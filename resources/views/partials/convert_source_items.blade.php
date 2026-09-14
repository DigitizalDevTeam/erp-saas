{{-- Source document meta + line selection for converts (uncheck items not to buy / invoice) --}}
@php
    $sourceLabel = $sourceLabel ?? __('Source document');
    $sourceValue = $sourceValue ?? '';
@endphp
<div class="row mb-3">
    <div class="col-md-12">
        <div class="alert alert-light border mb-0">
            <strong>{{ $sourceLabel }}:</strong>
            <span>{{ $sourceValue }}</span>
            <div class="small text-muted mt-1">
                {{ __('Uncheck products you will not include (rejected, high price, or supplier out of stock). You can also adjust quantities.') }}
            </div>
        </div>
    </div>
</div>

@if(!empty($sourceLines) && count($sourceLines))
    <div class="table-responsive mb-4">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th style="width:40px">
                        <input type="checkbox" id="select-all-items" checked title="{{ __('Select all') }}">
                    </th>
                    <th>{{ __('Item') }}</th>
                    <th>{{ __('Qty') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Description') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sourceLines as $row)
                    @php
                        $product = \App\Models\ProductService::find($row->product_id);
                        $name = $product?->name ?? ('#'.$row->product_id);
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" class="item-check" name="items[{{ $row->id }}][selected]" value="1" checked>
                        </td>
                        <td>{{ $name }}</td>
                        <td style="max-width:120px">
                            <input type="number" step="0.01" min="0.01" class="form-control item-qty"
                                   name="items[{{ $row->id }}][quantity]"
                                   value="{{ $row->quantity }}" required>
                        </td>
                        <td>{{ Auth::user()->priceFormat($row->price) }}</td>
                        <td>{{ $row->description }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @once
        @push('script-page')
        <script>
            (function () {
                function syncQty(check) {
                    var row = check.closest('tr');
                    if (!row) return;
                    var qty = row.querySelector('.item-qty');
                    if (!qty) return;
                    qty.disabled = !check.checked;
                    if (check.checked) {
                        qty.setAttribute('required', 'required');
                    } else {
                        qty.removeAttribute('required');
                    }
                }

                document.querySelectorAll('.item-check').forEach(function (el) {
                    syncQty(el);
                    el.addEventListener('change', function () {
                        syncQty(el);
                    });
                });

                var selectAll = document.getElementById('select-all-items');
                if (!selectAll) return;
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.item-check').forEach(function (el) {
                        el.checked = selectAll.checked;
                        syncQty(el);
                    });
                });
            })();
        </script>
        @endpush
    @endonce
@endif
