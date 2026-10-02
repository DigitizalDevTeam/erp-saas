{{ Form::open(['route' => 'productstock.store', 'method' => 'post', 'class' => 'needs-validation', 'novalidate', 'id' => 'stock-intro-form']) }}
<div class="modal-body">
    <div class="row">
        <div class="form-group col-md-4">
            {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
            {{ Form::select('warehouse_id', $warehouses, null, ['class' => 'form-control select', 'required' => true]) }}
        </div>
        <div class="form-group col-md-4">
            {{ Form::label('intro_date', __('Date'), ['class' => 'form-label']) }}<x-required></x-required>
            {{ Form::date('intro_date', date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
        </div>
        <div class="form-group col-md-4">
            {{ Form::label('intro_no', __('Document #'), ['class' => 'form-label']) }}
            {{ Form::text('intro_no_display', $intro_no, ['class' => 'form-control', 'readonly' => true]) }}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
            {{ Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 2]) }}
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2 mt-2">
        <h6 class="mb-0">{{ __('Products') }}</h6>
        <button type="button" class="btn btn-sm btn-outline-primary" id="si-add-line">{{ __('Add line') }}</button>
    </div>
    <div class="table-responsive si-lines-wrap">
        <table class="table table-sm" id="si-lines-table">
            <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th style="width:110px;">{{ __('Qty') }}</th>
                    <th style="width:100px;">{{ __('Row') }}</th>
                    <th style="width:100px;">{{ __('Shelf') }}</th>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody id="si-lines-body"></tbody>
        </table>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Save Stock Introduction') }}" class="btn btn-primary">
</div>
{{ Form::close() }}

<style>
    #commonModal .si-lines-wrap,
    #commonModal .si-lines-wrap .table-responsive {
        overflow: visible;
    }
    #commonModal .choices.is-open {
        position: relative;
        z-index: 30;
    }
    .si-choices-dropdown {
        position: fixed !important;
        z-index: 20000 !important;
        overflow-y: auto !important;
    }
</style>

<script>
(function () {
    var products = @json($products);
    var lineIndex = 0;
    function esc(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function optionsHtml(selected) {
        var html = '';
        Object.keys(products).forEach(function (id) {
            html += '<option value="' + esc(id) + '"' + (String(selected) === String(id) ? ' selected' : '') + '>' + esc(products[id]) + '</option>';
        });
        return html;
    }
    function addLine() {
        var idx = lineIndex++;
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><select name="items[' + idx + '][product_id]" class="form-control si-product" required>' + optionsHtml('') + '</select></td>' +
            '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control" step="0.0001" min="0.0001" required></td>' +
            '<td><input type="text" name="items[' + idx + '][row_no]" class="form-control"></td>' +
            '<td><input type="text" name="items[' + idx + '][shelf_no]" class="form-control"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger si-remove">&times;</button></td>';
        document.getElementById('si-lines-body').appendChild(tr);
        var select = tr.querySelector('select');
        if (select && typeof Choices !== 'undefined') {
            new Choices(select, {
                searchEnabled: true,
                itemSelectText: '',
                shouldSort: false,
                searchResultLimit: 50,
                searchPlaceholderValue: @json(__('Search')),
                position: 'bottom'
            });
            select.addEventListener('showDropdown', function () {
                requestAnimationFrame(function () { placeProductDropdown(select); });
            });
            select.addEventListener('search', function () {
                requestAnimationFrame(function () { placeProductDropdown(select); });
            });
        }
    }
    function placeProductDropdown(select) {
        var choicesEl = select.closest('.choices');
        if (!choicesEl) return;
        var dropdown = choicesEl.querySelector('.choices__list--dropdown');
        if (!dropdown) return;
        var rect = choicesEl.getBoundingClientRect();
        var spaceBelow = window.innerHeight - rect.bottom - 12;
        var spaceAbove = rect.top - 12;
        var openUp = spaceBelow < 180 && spaceAbove > spaceBelow;
        var maxH = Math.max(140, Math.min(280, openUp ? spaceAbove : spaceBelow));
        dropdown.classList.add('si-choices-dropdown');
        dropdown.style.left = rect.left + 'px';
        dropdown.style.width = Math.max(rect.width, 240) + 'px';
        dropdown.style.maxHeight = maxH + 'px';
        dropdown.style.top = (openUp ? Math.max(8, rect.top - maxH) : rect.bottom) + 'px';
    }
    var addBtn = document.getElementById('si-add-line');
    if (addBtn) {
        addBtn.addEventListener('click', function (e) { e.preventDefault(); addLine(); });
    }
    var body = document.getElementById('si-lines-body');
    if (body) {
        body.addEventListener('click', function (e) {
            if (e.target && e.target.classList.contains('si-remove')) {
                if (body.querySelectorAll('tr').length <= 1) return;
                e.target.closest('tr').remove();
            }
        });
        addLine();
    }
})();
</script>
