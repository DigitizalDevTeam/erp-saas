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
                    <th width="60"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($recipeItems as $ri)
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
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger recipe-row-remove" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                <i class="ti ti-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr class="text-muted recipe-empty-row"><td colspan="4">{{ __('No ingredients added yet.') }}</td></tr>
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
