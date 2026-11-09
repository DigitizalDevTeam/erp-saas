@extends('layouts.admin')
@section('page-title')
    {{ __('New Daily Special') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen-daily-specials.index') }}">{{ __('Daily Specials') }}</a></li>
    <li class="breadcrumb-item">{{ __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('kitchen-daily-specials.store') }}">
                        @csrf
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Date') }} *</label>
                                    <input type="date" name="special_date" class="form-control" value="{{ old('special_date', date('Y-m-d')) }}" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Kind') }} *</label>
                                    <select name="kind" id="kind" class="form-control">
                                        <option value="from_menu" {{ old('kind') === 'from_menu' ? 'selected' : '' }}>{{ __('From the menu') }}</option>
                                        <option value="on_the_spot" {{ old('kind') === 'on_the_spot' ? 'selected' : '' }}>{{ __('On-the-spot (new dish for one guest)') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Quantity planned') }} *</label>
                                    <input type="number" step="0.0001" min="0.0001" name="quantity_planned" class="form-control" value="{{ old('quantity_planned', 20) }}" required>
                                </div>
                            </div>
                        </div>

                        <div id="from-menu-fields">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('Dish') }} *</label>
                                        <select name="source_product_id" class="form-control">
                                            <option value="">{{ __('Select a dish') }}</option>
                                            @foreach ($dishes as $dish)
                                                <option value="{{ $dish->id }}" {{ (int) old('source_product_id') === (int) $dish->id ? 'selected' : '' }}>{{ $dish->name }} ({{ $dish->sale_price }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('Selling price') }}</label>
                                        <input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="{{ old('selling_price') }}" placeholder="{{ __('Leave empty to use the dish price') }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="on-the-spot-fields" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('Dish name') }} *</label>
                                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="{{ __('e.g. Boiled fish') }}">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('Selling price') }} *</label>
                                        <input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="{{ old('selling_price') }}">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('Unit') }}</label>
                                        <select name="unit_id" class="form-control">
                                            <option value="">{{ __('—') }}</option>
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" {{ (int) old('unit_id') === (int) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <h6 class="mt-2">{{ __('Ingredients (entered now, deducted on sale)') }}</h6>
                            <div class="table-responsive">
                                <table class="table" id="ingredients-table">
                                    <thead>
                                    <tr>
                                        <th>{{ __('Ingredient') }}</th>
                                        <th>{{ __('Quantity') }}</th>
                                        <th>{{ __('Unit') }}</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @php
                                        $oldIngredients = old('ingredients', [['ingredient_product_id' => '', 'quantity' => '', 'unit_id' => '']]);
                                    @endphp
                                    @foreach ($oldIngredients as $i => $ingredient)
                                        <tr class="ingredient-row">
                                            <td>
                                                <select name="ingredients[{{ $i }}][ingredient_product_id]" class="form-control">
                                                    <option value="">{{ __('Select') }}</option>
                                                    @foreach ($ingredients as $item)
                                                        <option value="{{ $item->id }}" {{ (int) ($ingredient['ingredient_product_id'] ?? 0) === (int) $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td><input type="number" step="0.0001" min="0" name="ingredients[{{ $i }}][quantity]" class="form-control" value="{{ $ingredient['quantity'] ?? '' }}"></td>
                                            <td>
                                                <select name="ingredients[{{ $i }}][unit_id]" class="form-control">
                                                    <option value="">{{ __('Stock unit') }}</option>
                                                    @foreach ($units as $unit)
                                                        <option value="{{ $unit->id }}" {{ (int) ($ingredient['unit_id'] ?? 0) === (int) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-ingredient">{{ __('Remove') }}</button></td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="add-ingredient">{{ __('Add ingredient') }}</button>
                        </div>

                        <div class="form-group mt-3">
                            <label class="form-label">{{ __('Notes') }}</label>
                            <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">{{ __('Create Special') }}</button>
                            <a href="{{ route('kitchen-daily-specials.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    var kindSelect = document.getElementById('kind');
    function toggleKind() {
        var onTheSpot = kindSelect.value === 'on_the_spot';
        document.getElementById('from-menu-fields').style.display = onTheSpot ? 'none' : '';
        document.getElementById('on-the-spot-fields').style.display = onTheSpot ? '' : 'none';
        // Both blocks post a selling_price input; clear the hidden
        // one so it can never override the visible one.
        if (onTheSpot) {
            document.querySelector('#from-menu-fields input[name="selling_price"]').value = '';
        } else {
            document.querySelector('#on-the-spot-fields input[name="selling_price"]').value = '';
        }
    }
    kindSelect.addEventListener('change', toggleKind);
    toggleKind();
    document.getElementById('add-ingredient').addEventListener('click', function () {
        var tbody = document.querySelector('#ingredients-table tbody');
        var index = tbody.querySelectorAll('tr.ingredient-row').length;
        var clone = tbody.querySelector('tr.ingredient-row').cloneNode(true);
        clone.querySelectorAll('input, select').forEach(function (el) {
            el.name = el.name.replace(/ingredients\[\d+\]/, 'ingredients[' + index + ']');
            if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
        });
        tbody.appendChild(clone);
    });
    document.querySelector('#ingredients-table tbody').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-ingredient')) {
            var rows = document.querySelectorAll('#ingredients-table tbody tr.ingredient-row');
            if (rows.length > 1) { e.target.closest('tr').remove(); }
        }
    });
</script>
@endpush
