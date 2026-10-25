@php
    $mode = $mode ?? 'create';
    $recipeItems = $recipeItems ?? collect();
    $ingredientProducts = $ingredientProducts ?? collect();
    $sectionId = $mode === 'edit' ? 'product_recipes_edit_section' : 'product_recipes_create_section';
    $tableId = $mode === 'edit' ? 'product_recipes_edit_table' : 'product_recipes_create_table';
    $selectId = $mode === 'edit' ? 'recipe_ingredient_edit_select' : 'recipe_ingredient_create_select';
    $qtyId = $mode === 'edit' ? 'recipe_ingredient_edit_qty' : 'recipe_ingredient_create_qty';
    $addBtnId = $mode === 'edit' ? 'recipe_ingredient_edit_add_btn' : 'recipe_ingredient_create_add_btn';
    $unitId = $mode === 'edit' ? 'recipe_ingredient_edit_unit' : 'recipe_ingredient_create_unit';
    $fieldPrefix = 'product_recipes';
@endphp
<div class="col-12 d-none" id="{{ $sectionId }}">
    <hr class="my-3">
    <h6 class="mb-2">{{ __('Recipe ingredients') }}</h6>
    <p class="text-muted small mb-2">{{ __('When this product is sold on POS, stock is deducted from each ingredient (per 1 unit sold). Ingredients are not shown on POS.') }}</p>

    <div class="table-responsive mb-2">
        <table class="table table-sm" id="{{ $tableId }}">
            <thead>
                <tr>
                    <th>{{ __('Ingredient') }}</th>
                    <th class="text-end">{{ __('Qty per unit sold') }}</th>
                    <th width="180">{{ __('Unit') }}</th>
                    <th width="180">{{ __('Alternatives') }}</th>
                    <th width="60"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($recipeItems as $ri)
                    @php $riAlts = ($recipeAlternativesAvailable ?? false) ? ($ri->alternatives ?? []) : []; @endphp
                    <tr data-existing-recipe-row="{{ $ri->id }}">
                        <td>{{ $ri->ingredientProduct ? $ri->ingredientProduct->name : '-' }}{{ $ri->ingredientProduct && $ri->ingredientProduct->sku ? ' (' . $ri->ingredientProduct->sku . ')' : '' }}@if($ri->ingredientProduct && $ri->ingredientProduct->unit)<div class="text-muted small">{{ __('Product unit') }}: {{ $ri->ingredientProduct->unit->name }}</div>@endif
                            <input type="hidden" name="product_recipes[existing_{{ $ri->id }}][ingredient_product_id]" value="{{ $ri->ingredient_product_id }}">
                        </td>
                        <td class="text-end">{{ $ri->quantity }}
                            <input type="hidden" name="product_recipes[existing_{{ $ri->id }}][quantity]" value="{{ $ri->quantity }}">
                        </td>
                        <td>
                            <select name="product_recipes[existing_{{ $ri->id }}][unit]" class="form-select form-select-sm measurement-unit-select">
                                <option value="">{{ __('Select unit') }}</option>
                                @foreach(\App\Support\MeasurementUnits::all() as $muCode => $muLabel)
                                    <option value="{{ $muCode }}" {{ ($ri->unit ?? null) === $muCode ? 'selected' : '' }}>{{ $muLabel }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-info recipe-alternatives-btn" data-recipe-key="existing_{{ $ri->id }}" data-ingredient-id="{{ $ri->ingredient_product_id }}" data-ingredient-name="{{ $ri->ingredientProduct ? $ri->ingredientProduct->name : '' }}">{{ __('Alternatives') }} (<span class="recipe-alt-count">{{ count($riAlts) }}</span>)</button>
                            <span class="recipe-alternatives-inputs">
                                @foreach($riAlts as $alt)
                                    <input type="hidden" name="product_recipes[existing_{{ $ri->id }}][alternatives][{{ $alt->alternative_product_id }}]" value="{{ $alt->quantity }}">
                                @endforeach
                            </span>
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger recipe-row-remove" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                <i class="ti ti-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr class="text-muted recipe-empty-row"><td colspan="5">{{ __('No ingredients added yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label">{{ __('Ingredient') }}</label>
            <select class="form-select" id="{{ $selectId }}">
                <option value="">{{ __('Select ingredient') }}</option>
                @foreach($ingredientProducts as $ing)
                    <option value="{{ $ing->id }}" data-suggest="{{ \App\Support\MeasurementUnits::suggestForProduct($ing) ?? '' }}">{{ $ing->name }}{{ $ing->sku ? ' (' . $ing->sku . ')' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('Quantity') }}</label>
            <input type="number" class="form-control" id="{{ $qtyId }}" step="0.0001" min="0.0001" value="1">
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('Unit') }}</label>
            <select class="form-select measurement-unit-select" id="{{ $unitId }}">
                <option value="">{{ __('Select unit') }}</option>
                @foreach(\App\Support\MeasurementUnits::all() as $muCode => $muLabel)
                    <option value="{{ $muCode }}">{{ $muLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button type="button" class="btn btn-primary w-100" id="{{ $addBtnId }}">{{ __('Add ingredient') }}</button>
        </div>
    </div>
    @if($mode === 'edit')
        <small class="text-muted d-block mt-2">{{ __('After adding/removing ingredients, click Update to save.') }}</small>
    @endif
</div>

{{-- Alternatives picker modal (shared by the create/edit recipe tables) --}}
<div class="modal fade" id="recipeAlternativesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Alternatives for') }} <span id="recipe-alt-ingredient-name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">{{ __('Pick alternative ingredients in order. At cooking time the cook picks one manually when the main ingredient is missing.') }}</p>
                <div id="recipe-alt-list" class="mb-3"></div>
                <div class="d-flex gap-2">
                    <select class="form-select" id="recipe-alt-select">
                        <option value="">{{ __('Select ingredient') }}</option>
                        @foreach($ingredientProducts as $ing)
                            <option value="{{ $ing->id }}">{{ $ing->name }}{{ $ing->sku ? ' (' . $ing->sku . ')' : '' }}</option>
                        @endforeach
                    </select>
                    <input type="number" class="form-control" id="recipe-alt-add-qty" step="any" min="0.0001" placeholder="{{ __('Qty (empty = same as main)') }}" style="max-width:12rem">
                    <button type="button" class="btn btn-primary" id="recipe-alt-add-btn">{{ __('Add') }}</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="recipe-alt-save-btn">{{ __('Save') }}</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('recipeAlternativesModal');
    if (!modalEl) return;
    var currentBtn = null;
    var currentList = [];
    function esc(s) { var el = document.createElement('textarea'); el.textContent = (s == null ? '' : String(s)); return el.innerHTML; }
    function normQty(v) { if (v === '' || v == null || isNaN(parseFloat(v)) || parseFloat(v) <= 0) return ''; return String(v); }
    function renderList() {
        var wrap = document.getElementById('recipe-alt-list');
        if (!currentList.length) { wrap.innerHTML = '<p class="text-muted small mb-0">{{ __('No alternatives yet.') }}</p>'; return; }
        var html = '';
        currentList.forEach(function (a, idx) {
            html += '<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1"><span>' + (idx + 1) + '. ' + esc(a.name) + '</span><span class="d-flex gap-2 align-items-center"><input type="number" class="form-control form-control-sm recipe-alt-qty" data-idx="' + idx + '" step="any" min="0.0001" placeholder="{{ __('Qty') }}" style="width:6rem" value="' + esc(a.qty || '') + '"><button type="button" class="btn btn-sm btn-outline-danger recipe-alt-remove" data-idx="' + idx + '"><i class="ti ti-trash"></i></button></span></div>';
        });
        wrap.innerHTML = html;
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.recipe-alternatives-btn');
        if (btn) {
            currentBtn = btn;
            document.getElementById('recipe-alt-ingredient-name').textContent = btn.getAttribute('data-ingredient-name') || '';
            var row = btn.closest('tr');
            currentList = [];
            if (row) {
                row.querySelectorAll('.recipe-alternatives-inputs input').forEach(function (inp) {
                    var m = inp.name.match(/\[alternatives\]\[(\d+)\]/);
                    var altId = m ? m[1] : null;
                    if (!altId) return;
                    var opt = document.querySelector('#recipe-alt-select option[value="' + altId + '"]');
                    currentList.push({ id: altId, name: opt ? opt.textContent : ('#' + altId), qty: normQty(inp.value) });
                });
            }
            renderList();
            $('#recipeAlternativesModal').modal('show');
            return;
        }
        var rm = e.target.closest('.recipe-alt-remove');
        if (rm) {
            currentList.splice(parseInt(rm.getAttribute('data-idx'), 10), 1);
            renderList();
        }
    });
    document.addEventListener('change', function (e) {
        var q = e.target.closest('.recipe-alt-qty');
        if (q) {
            var idx = parseInt(q.getAttribute('data-idx'), 10);
            if (currentList[idx]) { currentList[idx].qty = normQty(q.value); }
        }
    });
    document.getElementById('recipe-alt-add-btn').addEventListener('click', function () {
        var sel = document.getElementById('recipe-alt-select');
        if (!sel.value) return;
        var mainId = currentBtn ? currentBtn.getAttribute('data-ingredient-id') : null;
        if (mainId && String(sel.value) === String(mainId)) return;
        if (currentList.some(function (a) { return String(a.id) === String(sel.value); })) return;
        currentList.push({ id: sel.value, name: sel.options[sel.selectedIndex].text, qty: normQty(document.getElementById('recipe-alt-add-qty').value) });
        renderList();
        sel.value = '';
        document.getElementById('recipe-alt-add-qty').value = '';
    });
    document.getElementById('recipe-alt-save-btn').addEventListener('click', function () {
        if (!currentBtn) return;
        var row = currentBtn.closest('tr');
        var key = currentBtn.getAttribute('data-recipe-key');
        var container = row ? row.querySelector('.recipe-alternatives-inputs') : null;
        if (container) {
            var html = '';
            currentList.forEach(function (a) {
                html += '<input type="hidden" name="product_recipes[' + key + '][alternatives][' + a.id + ']" value="' + esc(normQty(a.qty)) + '">';
            });
            container.innerHTML = html;
        }
        var countEl = currentBtn.querySelector('.recipe-alt-count');
        if (countEl) { countEl.textContent = currentList.length; }
        $('#recipeAlternativesModal').modal('hide');
    });
})();
</script>
