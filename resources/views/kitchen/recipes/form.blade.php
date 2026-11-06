@extends('layouts.admin')
@section('page-title')
    {{ $recipe->exists ? __('Edit Recipe') : __('Create Recipe') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.recipes.index') }}">{{ __('Recipes') }}</a></li>
    <li class="breadcrumb-item">{{ $recipe->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($recipe->exists)
                        {{ Form::model($recipe, ['route' => ['kitchen.recipes.update', $recipe->id], 'method' => 'PUT', 'files' => true, 'class' => 'needs-validation', 'novalidate']) }}
                    @else
                        {{ Form::open(['route' => 'kitchen.recipes.store', 'method' => 'POST', 'files' => true, 'class' => 'needs-validation', 'novalidate']) }}
                    @endif

                    <div class="row">
                        <div class="form-group col-md-3">
                            {{ Form::label('code', __('Code'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::text('code', $recipe->exists ? $recipe->code : $code, ['class' => 'form-control', 'required' => 'required']) }}
                        </div>
                        <div class="form-group col-md-5">
                            {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('e.g. Coconut Rice')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('product_id', __('Dish / Preparation'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('product_id', $dishes->pluck('name', 'id'), null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Select dish or preparation')]) }}
                        </div>

                        <div class="form-group col-md-3">
                            {{ Form::label('expected_yield_quantity', __('Expected Yield'), ['class' => 'form-label']) }}
                            {{ Form::number('expected_yield_quantity', null, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0', 'placeholder' => __('e.g. 3')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('expected_yield_unit_id', __('Yield Unit'), ['class' => 'form-label']) }}
                            {{ Form::select('expected_yield_unit_id', $units->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('Select unit')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('standard_portion_quantity', __('Standard Portion'), ['class' => 'form-label']) }}
                            {{ Form::number('standard_portion_quantity', null, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0', 'placeholder' => __('e.g. 150')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('standard_portion_unit_id', __('Portion Unit'), ['class' => 'form-label']) }}
                            {{ Form::select('standard_portion_unit_id', $units->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('Select unit')]) }}
                        </div>

                        <div class="form-group col-md-3">
                            {{ Form::label('preparation_time_minutes', __('Preparation Time (minutes)'), ['class' => 'form-label']) }}
                            {{ Form::number('preparation_time_minutes', null, ['class' => 'form-control', 'step' => '1', 'min' => '0']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('loss_percent', __('Cleaning / Preparation Loss %'), ['class' => 'form-label']) }}
                            {{ Form::number('loss_percent', null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'max' => '99.99']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::number('branch_id', null, ['class' => 'form-control', 'step' => '1', 'min' => '0']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('photo', __('Dish Photograph'), ['class' => 'form-label']) }}
                            {{ Form::file('photo', ['class' => 'form-control']) }}
                        </div>

                        <div class="form-group col-md-6">
                            {{ Form::label('equipment', __('Equipment'), ['class' => 'form-label']) }}
                            {{ Form::textarea('equipment', null, ['class' => 'form-control', 'rows' => 2]) }}
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('allergens', __('Allergens'), ['class' => 'form-label']) }}
                            {{ Form::textarea('allergens', null, ['class' => 'form-control', 'rows' => 2]) }}
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('instructions', __('Preparation, Plating and Presentation Instructions'), ['class' => 'form-label']) }}
                            {{ Form::textarea('instructions', null, ['class' => 'form-control', 'rows' => 3]) }}
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('storage_conditions', __('Storage Conditions'), ['class' => 'form-label']) }}
                            {{ Form::textarea('storage_conditions', null, ['class' => 'form-control', 'rows' => 3]) }}
                        </div>
                    </div>

                    <h5 class="mt-4">{{ __('Ingredients') }}</h5>
                    <div class="table-responsive">
                        <table class="table" id="recipe-lines-table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Quantity') }}</th>
                                <th>{{ __('Unit') }}</th>
                                <th>{{ __('Loss %') }}</th>
                                <th>{{ __('Alternatives') }}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="recipe-lines-body">
                            @foreach ($lines as $index => $line)
                                <tr class="recipe-line-row">
                                    <td>
                                        <select name="lines[{{ $index }}][ingredient_product_id]" class="form-control" required>
                                            <option value="">{{ __('Select ingredient') }}</option>
                                            @foreach ($ingredients as $ingredient)
                                                <option value="{{ $ingredient->id }}" {{ (int) $line->ingredient_product_id === (int) $ingredient->id ? 'selected' : '' }}>{{ $ingredient->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" name="lines[{{ $index }}][quantity]" value="{{ $line->quantity }}" class="form-control" step="0.0001" min="0" required></td>
                                    <td>
                                        <select name="lines[{{ $index }}][unit_id]" class="form-control">
                                            <option value="">{{ __('Stock unit') }}</option>
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" {{ (int) $line->unit_id === (int) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" name="lines[{{ $index }}][loss_percent]" value="{{ $line->loss_percent }}" class="form-control" step="0.01" min="0" max="99.99"></td>
                                    <td>
                                        <select name="lines[{{ $index }}][alternatives][]" class="form-control" multiple>
                                            @foreach ($ingredients as $ingredient)
                                                <option value="{{ $ingredient->id }}" {{ $line->alternatives->contains('alternative_product_id', (int) $ingredient->id) ? 'selected' : '' }}>{{ $ingredient->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><button type="button" class="btn btn-sm btn-danger remove-line-btn">{{ __('Remove') }}</button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" id="add-line-btn">{{ __('Add Ingredient') }}</button>

                    <div class="mt-4">
                        <a href="{{ route('kitchen.recipes.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        <input type="submit" value="{{ $recipe->exists ? __('Update') : __('Create') }}" class="btn btn-primary">
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <template id="recipe-line-template">
        <tr class="recipe-line-row">
            <td>
                <select name="lines[__INDEX__][ingredient_product_id]" class="form-control" required>
                    <option value="">{{ __('Select ingredient') }}</option>
                    @foreach ($ingredients as $ingredient)
                        <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="number" name="lines[__INDEX__][quantity]" class="form-control" step="0.0001" min="0" required></td>
            <td>
                <select name="lines[__INDEX__][unit_id]" class="form-control">
                    <option value="">{{ __('Stock unit') }}</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="number" name="lines[__INDEX__][loss_percent]" value="0" class="form-control" step="0.01" min="0" max="99.99"></td>
            <td>
                <select name="lines[__INDEX__][alternatives][]" class="form-control" multiple>
                    @foreach ($ingredients as $ingredient)
                        <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option>
                    @endforeach
                </select>
            </td>
            <td><button type="button" class="btn btn-sm btn-danger remove-line-btn">{{ __('Remove') }}</button></td>
        </tr>
    </template>
@endsection

@push('script-page')
<script>
(function () {
    var body = document.getElementById('recipe-lines-body');
    var template = document.getElementById('recipe-line-template');
    var index = body ? body.querySelectorAll('.recipe-line-row').length : 0;
    var addBtn = document.getElementById('add-line-btn');

    if (addBtn && body && template) {
        addBtn.addEventListener('click', function () {
            var html = template.innerHTML.replaceAll('__INDEX__', String(index++));
            body.insertAdjacentHTML('beforeend', html);
        });
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.remove-line-btn');
        if (btn) {
            var row = btn.closest('.recipe-line-row');
            if (row) row.remove();
        }
    });
})();
</script>
@endpush
