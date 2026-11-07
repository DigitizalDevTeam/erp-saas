@extends('layouts.admin')
@section('page-title')
    {{ $requisition->exists ? __('Edit Requisition') : __('Create Requisition') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.requisitions.index') }}">{{ __('Requisitions') }}</a></li>
    <li class="breadcrumb-item">{{ $requisition->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($requisition->exists)
                        {{ Form::model($requisition, ['route' => ['kitchen.requisitions.update', $requisition->id], 'method' => 'PUT', 'class' => 'needs-validation', 'novalidate']) }}
                    @else
                        {{ Form::open(['route' => 'kitchen.requisitions.store', 'method' => 'POST', 'class' => 'needs-validation', 'novalidate']) }}
                    @endif

                    @if ($prefill && $prefill['plan'])
                        <div class="alert alert-info">{{ __('Prefilled from the shortages of plan :number.', ['number' => $prefill['plan']->plan_number]) }}</div>
                        {{ Form::hidden('kitchen_production_plan_id', $prefill['plan']->id) }}
                    @endif

                    <div class="row">
                        <div class="form-group col-md-4">
                            {{ Form::label('source_warehouse_id', __('Source Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('source_warehouse_id', $warehouses->pluck('name', 'id'), null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Select source warehouse')]) }}
                            <small class="text-muted">{{ __('The warehouse the stock is moved from.') }}</small>
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('kitchen_warehouse_id', __('Kitchen Warehouse'), ['class' => 'form-label']) }}<x-required></x-required>
                            {{ Form::select('kitchen_warehouse_id', $warehouses->pluck('name', 'id'), $prefill && $prefill['plan'] ? $prefill['plan']->warehouse_id : null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Select kitchen warehouse')]) }}
                            <small class="text-muted">{{ __('The warehouse the stock is moved to.') }}</small>
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::select('branch_id', $branches->pluck('name', 'id'), null, ['class' => 'form-control', 'placeholder' => __('Select branch')]) }}
                        </div>
                        <div class="form-group col-md-12">
                            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
                            {{ Form::text('notes', null, ['class' => 'form-control', 'placeholder' => __('Optional')]) }}
                        </div>
                    </div>

                    <h6 class="mt-4">{{ __('Requested Ingredients') }}</h6>
                    <p class="text-muted small">{{ __('A requisition asks for stock that is already in a warehouse — it is not a purchase order.') }}</p>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Required Quantity') }}</th>
                                <th>{{ __('Unit') }}</th>
                                <th>{{ __('Unit Cost') }}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="requisition-lines-body">
                            @php
                                $oldLines = old('lines');
                                $rows = $oldLines ?: ($prefill ? $prefill['lines'] : ($requisition->exists ? $requisition->lines->map(fn ($l) => ['ingredient_product_id' => $l->ingredient_product_id, 'required_quantity' => $l->required_quantity, 'unit_id' => $l->unit_id, 'unit_cost' => $l->unit_cost])->all() : [['ingredient_product_id' => null, 'required_quantity' => null, 'unit_id' => null, 'unit_cost' => null]]));
                            @endphp
                            @foreach ($rows as $i => $row)
                                <tr class="requisition-line-row">
                                    <td>
                                        <select name="lines[{{ $i }}][ingredient_product_id]" class="form-control" required>
                                            <option value="">{{ __('Select an ingredient') }}</option>
                                            @foreach ($ingredients as $ingredient)
                                                <option value="{{ $ingredient->id }}" {{ (int) ($row['ingredient_product_id'] ?? 0) === (int) $ingredient->id ? 'selected' : '' }}>{{ $ingredient->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" name="lines[{{ $i }}][required_quantity]" value="{{ $row['required_quantity'] ?? '' }}" class="form-control" step="0.0001" min="0.0001" required placeholder="e.g. 5"></td>
                                    <td>
                                        <select name="lines[{{ $i }}][unit_id]" class="form-control">
                                            <option value="">{{ __('Stock unit') }}</option>
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" {{ (int) ($row['unit_id'] ?? 0) === (int) $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" name="lines[{{ $i }}][unit_cost]" value="{{ $row['unit_cost'] ?? '' }}" class="form-control" step="0.0001" min="0" placeholder="0"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger remove-line-btn"><i class="ti ti-trash"></i></button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div id="requisition-line-template" style="display: none;">
                        <tr class="requisition-line-row">
                            <td>
                                <select name="lines[__INDEX__][ingredient_product_id]" class="form-control" required>
                                    <option value="">{{ __('Select an ingredient') }}</option>
                                    @foreach ($ingredients as $ingredient)
                                        <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="number" name="lines[__INDEX__][required_quantity]" class="form-control" step="0.0001" min="0.0001" required placeholder="e.g. 5"></td>
                            <td>
                                <select name="lines[__INDEX__][unit_id]" class="form-control">
                                    <option value="">{{ __('Stock unit') }}</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="number" name="lines[__INDEX__][unit_cost]" class="form-control" step="0.0001" min="0" placeholder="0"></td>
                            <td><button type="button" class="btn btn-sm btn-danger remove-line-btn"><i class="ti ti-trash"></i></button></td>
                        </tr>
                    </div>

                    <button type="button" id="add-line-btn" class="btn btn-sm btn-outline-primary"><i class="ti ti-plus"></i> {{ __('Add Ingredient') }}</button>

                    <div class="mt-4 text-end">
                        <a href="{{ route('kitchen.requisitions.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ $requisition->exists ? __('Update Requisition') : __('Create Requisition') }}</button>
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
    var body = document.getElementById('requisition-lines-body');
    var template = document.getElementById('requisition-line-template');
    var index = body ? body.querySelectorAll('.requisition-line-row').length : 0;
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
            var row = btn.closest('.requisition-line-row');
            if (row) row.remove();
        }
    });
})();
</script>
@endpush
