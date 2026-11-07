@extends('layouts.admin')
@section('page-title')
    {{ $plan->exists ? __('Edit Production Plan') : __('Create Production Plan') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.production-plans.index') }}">{{ __('Production Plans') }}</a></li>
    <li class="breadcrumb-item">{{ $plan->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($plan->exists)
                        {{ Form::model($plan, ['route' => ['kitchen.production-plans.update', $plan->id], 'method' => 'PUT', 'class' => 'needs-validation', 'novalidate']) }}
                    @else
                        {{ Form::open(['route' => 'kitchen.production-plans.store', 'method' => 'POST', 'class' => 'needs-validation', 'novalidate']) }}
                    @endif

                    <div class="row">
                        <div class="form-group col-md-3">
                            {{ Form::label('plan_date', __('Plan Date'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::date('plan_date', $plan->plan_date ? $plan->plan_date->format('Y-m-d') : date('Y-m-d'), ['class' => 'form-control', 'required' => 'required']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('meal_service', __('Meal Service'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('meal_service', array_combine($mealServices, array_map(fn ($s) => ucfirst($s), $mealServices)), $plan->meal_service, ['class' => 'form-control', 'required' => 'required']) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::select('branch_id', $branches->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('All branches')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('warehouse_id', __('Kitchen Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('warehouse_id', $warehouses->pluck('name', 'id'), null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Select kitchen warehouse')]) }}
                            <small class="text-muted">{{ __('Requirements and shortages are checked against this warehouse.') }}</small>
                        </div>

                        <div class="form-group col-md-3">
                            {{ Form::label('section', __('Kitchen / Section'), ['class' => 'form-label']) }}
                            {{ Form::select('section', ['hot_kitchen' => __('Hot Kitchen'), 'pastry' => __('Pastry'), 'cold_kitchen' => __('Cold Kitchen')], null, ['class' => 'form-control', 'placeholder' => __('Select section')]) }}
                        </div>
                        <div class="form-group col-md-9">
                            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
                            {{ Form::text('notes', null, ['class' => 'form-control', 'placeholder' => __('e.g. Wedding of 40 guests at 19:00')]) }}
                        </div>
                    </div>

                    <h6 class="mt-4">{{ __('Planned Dishes') }}</h6>
                    <p class="text-muted small">{{ __('Pick an approved recipe and the quantity for this date and meal service. The source records what the quantity is based on (occupancy, sales history, event, minimum stock, special order).') }}</p>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Dish (Recipe)') }}</th>
                                <th>{{ __('Planned Quantity') }}</th>
                                <th>{{ __('Unit') }}</th>
                                <th>{{ __('Based On') }}</th>
                                <th>{{ __('Notes') }}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="plan-lines-body">
                            @php
                                $oldLines = old('lines');
                                $rows = $oldLines ?: ($plan->exists ? $plan->lines->map(fn ($l) => ['kitchen_recipe_id' => $l->kitchen_recipe_id, 'planned_quantity' => $l->planned_quantity, 'planned_unit_id' => $l->planned_unit_id, 'source' => $l->source, 'notes' => $l->notes])->all() : [['kitchen_recipe_id' => null, 'planned_quantity' => null, 'planned_unit_id' => null, 'source' => 'manual', 'notes' => null]]);
                            @endphp
                            @foreach ($rows as $i => $row)
                                <tr class="plan-line-row">
                                    <td>
                                        <select name="lines[{{ $i }}][kitchen_recipe_id]" class="form-control" required>
                                            <option value="">{{ __('Select a recipe') }}</option>
                                            @foreach ($recipes as $recipe)
                                                <option value="{{ $recipe->id }}" {{ (int) ($row['kitchen_recipe_id'] ?? 0) === (int) $recipe->id ? 'selected' : '' }}>{{ $recipe->product ? $recipe->product->name : $recipe->code }} ({{ $recipe->code }} v{{ $recipe->version }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" name="lines[{{ $i }}][planned_quantity]" value="{{ $row['planned_quantity'] ?? '' }}" class="form-control" step="0.0001" min="0.0001" required placeholder="e.g. 20"></td>
                                    <td>
                                        <select name="lines[{{ $i }}][planned_unit_id]" class="form-control">
                                            <option value="">{{ __('Portions (default)') }}</option>
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" {{ (int) ($row['planned_unit_id'] ?? 0) === (int) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="lines[{{ $i }}][source]" class="form-control">
                                            @foreach ($sources as $source)
                                                <option value="{{ $source }}" {{ ($row['source'] ?? 'manual') === $source ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $source)) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="text" name="lines[{{ $i }}][notes]" value="{{ $row['notes'] ?? '' }}" class="form-control" placeholder="{{ __('Optional') }}"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger remove-line-btn"><i class="ti ti-trash"></i></button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div id="plan-line-template" style="display: none;">
                        <tr class="plan-line-row">
                            <td>
                                <select name="lines[__INDEX__][kitchen_recipe_id]" class="form-control" required>
                                    <option value="">{{ __('Select a recipe') }}</option>
                                    @foreach ($recipes as $recipe)
                                        <option value="{{ $recipe->id }}">{{ $recipe->product ? $recipe->product->name : $recipe->code }} ({{ $recipe->code }} v{{ $recipe->version }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="number" name="lines[__INDEX__][planned_quantity]" class="form-control" step="0.0001" min="0.0001" required placeholder="e.g. 20"></td>
                            <td>
                                <select name="lines[__INDEX__][planned_unit_id]" class="form-control">
                                    <option value="">{{ __('Portions (default)') }}</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="lines[__INDEX__][source]" class="form-control">
                                    @foreach ($sources as $source)
                                        <option value="{{ $source }}">{{ ucfirst(str_replace('_', ' ', $source)) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="text" name="lines[__INDEX__][notes]" class="form-control" placeholder="{{ __('Optional') }}"></td>
                            <td><button type="button" class="btn btn-sm btn-danger remove-line-btn"><i class="ti ti-trash"></i></button></td>
                        </tr>
                    </div>

                    <button type="button" id="add-line-btn" class="btn btn-sm btn-outline-primary"><i class="ti ti-plus"></i> {{ __('Add Dish') }}</button>

                    <div class="mt-4 text-end">
                        <a href="{{ route('kitchen.production-plans.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ $plan->exists ? __('Update Plan') : __('Create Plan') }}</button>
                    </div>

                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
(function () {
    var body = document.getElementById('plan-lines-body');
    var template = document.getElementById('plan-line-template');
    var index = body ? body.querySelectorAll('.plan-line-row').length : 0;
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
            var row = btn.closest('.plan-line-row');
            if (row) row.remove();
        }
    });
})();
</script>
@endpush
