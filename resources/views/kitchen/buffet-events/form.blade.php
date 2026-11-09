@extends('layouts.admin')
@section('page-title')
    {{ isset($event) ? __('Edit Buffet Event') : __('New Buffet Event') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen-buffet-events.index') }}">{{ __('Buffet Events') }}</a></li>
    <li class="breadcrumb-item">{{ isset($event) ? __('Edit') : __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ isset($event) ? route('kitchen-buffet-events.update', $event->id) : route('kitchen-buffet-events.store') }}">
                        @csrf
                        @if (isset($event))
                            @method('PUT')
                        @endif

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Event date') }} *</label>
                                    <input type="date" name="event_date" class="form-control" value="{{ old('event_date', isset($event) ? $event->event_date->format('Y-m-d') : ($date ?? date('Y-m-d'))) }}" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Meal service') }}</label>
                                    <select name="meal_service" class="form-control">
                                        <option value="">{{ __('—') }}</option>
                                        @foreach ($meal_services as $service)
                                            <option value="{{ $service }}" {{ old('meal_service', isset($event) ? $event->meal_service : ($meal_service ?? '')) === $service ? 'selected' : '' }}>{{ ucfirst($service) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Warehouse') }} *</label>
                                    <select name="warehouse_id" class="form-control" required>
                                        @foreach ($warehouses as $warehouse)
                                            <option value="{{ $warehouse->id }}" {{ (int) old('warehouse_id', isset($event) ? $event->warehouse_id : '') === (int) $warehouse->id ? 'selected' : '' }}>{{ $warehouse->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Price per person') }}</label>
                                    <input type="number" step="0.01" min="0" name="price_per_person" class="form-control" value="{{ old('price_per_person', isset($event) ? $event->price_per_person : '') }}" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Name / occasion') }}</label>
                                    <input type="text" name="name" class="form-control" value="{{ old('name', isset($event) ? $event->name : '') }}" placeholder="{{ __('e.g. Sunday buffet, conference lunch') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Event / conference link') }}</label>
                                    <input type="text" name="event_link" class="form-control" value="{{ old('event_link', isset($event) ? $event->event_link : '') }}" placeholder="{{ __('e.g. conference or group booking reference') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Expected covers from') }} *</label>
                                    <select name="covers_source" id="covers_source" class="form-control">
                                        <option value="manual" {{ old('covers_source', isset($event) ? $event->covers_source : 'manual') === 'manual' ? 'selected' : '' }}>{{ __('Manual headcount') }}</option>
                                        <option value="occupancy" {{ old('covers_source', isset($event) ? $event->covers_source : '') === 'occupancy' ? 'selected' : '' }}>{{ __('Hotel occupancy (guests with a meal plan)') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Expected covers') }} *</label>
                                    <input type="number" min="1" name="expected_covers" id="expected_covers" class="form-control" value="{{ old('expected_covers', isset($event) ? $event->expected_covers : ($occupancy_covers ?? '')) }}">
                                    @if (isset($occupancy) && $occupancy['total_guests'] > 0)
                                        <small class="text-muted">{{ __('Hotel occupancy on this date: :guests guests (:breakfast breakfast, :lunch lunch, :dinner dinner).', ['guests' => $occupancy['total_guests'], 'breakfast' => $occupancy['breakfast_covers'], 'lunch' => $occupancy['lunch_covers'], 'dinner' => $occupancy['dinner_covers']]) }}</small>
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Notes') }}</label>
                                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', isset($event) ? $event->notes : '') }}</textarea>
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-3">{{ __('Preparations / items') }}</h6>
                        <p class="text-muted small">{{ __('For each line: pick the dish or item, then either a quantity per cover (planned = per cover × covers) or a planned quantity directly. A recipe line uses the dish\'s approved recipe — its ingredients are deducted when the event is prepared.') }}</p>
                        <div class="table-responsive">
                            <table class="table" id="lines-table">
                                <thead>
                                <tr>
                                    <th>{{ __('Dish / item') }}</th>
                                    <th>{{ __('Recipe') }}</th>
                                    <th>{{ __('Per cover') }}</th>
                                    <th>{{ __('Planned qty') }}</th>
                                    <th>{{ __('Notes') }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @php
                                    $oldLines = old('lines');
                                    $lines = $oldLines !== null ? $oldLines : (isset($event) ? $event->lines->map(fn ($l) => ['product_id' => $l->product_id, 'kitchen_recipe_id' => $l->kitchen_recipe_id, 'per_cover_quantity' => $l->per_cover_quantity, 'planned_quantity' => $l->planned_quantity, 'notes' => $l->notes])->all() : [['product_id' => '', 'kitchen_recipe_id' => '', 'per_cover_quantity' => '', 'planned_quantity' => '', 'notes' => '']]);
                                @endphp
                                @foreach ($lines as $i => $line)
                                    <tr class="line-row">
                                        <td>
                                            <select name="lines[{{ $i }}][product_id]" class="form-control" required>
                                                <option value="">{{ __('Select') }}</option>
                                                @foreach ($products as $product)
                                                    <option value="{{ $product->id }}" {{ (int) ($line['product_id'] ?? 0) === (int) $product->id ? 'selected' : '' }}>{{ $product->name }}</option>
                                                @endforeach
                                                @foreach ($ingredients as $ingredient)
                                                    <option value="{{ $ingredient->id }}" {{ (int) ($line['product_id'] ?? 0) === (int) $ingredient->id ? 'selected' : '' }}>{{ $ingredient->name }} ({{ __('item') }})</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <select name="lines[{{ $i }}][kitchen_recipe_id]" class="form-control">
                                                <option value="">{{ __('—') }}</option>
                                                @foreach ($recipes as $recipe)
                                                    <option value="{{ $recipe->id }}" {{ (int) ($line['kitchen_recipe_id'] ?? 0) === (int) $recipe->id ? 'selected' : '' }}>{{ $recipe->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][per_cover_quantity]" class="form-control" value="{{ $line['per_cover_quantity'] ?? '' }}"></td>
                                        <td><input type="number" step="0.0001" min="0" name="lines[{{ $i }}][planned_quantity]" class="form-control" value="{{ $line['planned_quantity'] ?? '' }}"></td>
                                        <td><input type="text" name="lines[{{ $i }}][notes]" class="form-control" value="{{ $line['notes'] ?? '' }}"></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line">{{ __('Remove') }}</button></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="add-line">{{ __('Add line') }}</button>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">{{ isset($event) ? __('Update Draft') : __('Create Draft') }}</button>
                            <a href="{{ route('kitchen-buffet-events.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    document.getElementById('add-line').addEventListener('click', function () {
        var tbody = document.querySelector('#lines-table tbody');
        var index = tbody.querySelectorAll('tr.line-row').length;
        var first = tbody.querySelector('tr.line-row');
        var clone = first.cloneNode(true);
        clone.querySelectorAll('input, select').forEach(function (el) {
            el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
            if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
        });
        tbody.appendChild(clone);
    });
    document.querySelector('#lines-table tbody').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-line')) {
            var rows = document.querySelectorAll('#lines-table tbody tr.line-row');
            if (rows.length > 1) { e.target.closest('tr').remove(); }
        }
    });
</script>
@endpush
