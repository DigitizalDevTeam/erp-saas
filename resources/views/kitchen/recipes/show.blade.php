@extends('layouts.admin')
@section('page-title')
    {{ $recipe->name }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.recipes.index') }}">{{ __('Recipes') }}</a></li>
    <li class="breadcrumb-item">{{ $recipe->code }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create kitchen recipes')
            @if ($recipe->isDraft() || $recipe->isPendingApproval())
                <a href="{{ route('kitchen.recipes.edit', $recipe->id) }}" class="btn btn-sm btn-primary">{{ __('Edit') }}</a>
            @endif
            @if ($recipe->isApproved())
                <form method="POST" action="{{ route('kitchen.recipes.new-version', $recipe->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('New Version') }}</button>
                </form>
            @endif
        @endcan
        @can('create kitchen recipes')
            @if ($recipe->isDraft())
                <form method="POST" action="{{ route('kitchen.recipes.submit', $recipe->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-warning">{{ __('Submit for Approval') }}</button>
                </form>
            @endif
        @endcan
        @can('approve kitchen recipes')
            @if ($recipe->isPendingApproval())
                <form method="POST" action="{{ route('kitchen.recipes.approve', $recipe->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">{{ __('Approve') }}</button>
                </form>
                <form method="POST" action="{{ route('kitchen.recipes.reject', $recipe->id) }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="approval_note" value="">
                    <button type="submit" class="btn btn-sm btn-danger">{{ __('Return for Correction') }}</button>
                </form>
            @endif
            @if ($recipe->isApproved())
                <form method="POST" action="{{ route('kitchen.recipes.archive', $recipe->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-secondary">{{ __('Archive') }}</button>
                </form>
            @endif
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5>{{ $recipe->name }} <small class="text-muted">v{{ $recipe->version }} · {{ $recipe->code }}</small></h5>
                    <p class="mb-1"><strong>{{ __('Dish / Preparation') }}:</strong> {{ $recipe->product ? $recipe->product->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Status') }}:</strong> {{ ucfirst(str_replace('_', ' ', $recipe->status)) }}</p>
                    <p class="mb-1"><strong>{{ __('Expected Yield') }}:</strong> {{ $recipe->expected_yield_quantity ?? '-' }} {{ $recipe->expectedYieldUnit ? $recipe->expectedYieldUnit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Standard Portion') }}:</strong> {{ $recipe->standard_portion_quantity ?? '-' }} {{ $recipe->standardPortionUnit ? $recipe->standardPortionUnit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Preparation Time') }}:</strong> {{ $recipe->preparation_time_minutes ? $recipe->preparation_time_minutes . ' ' . __('minutes') : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Cleaning / Preparation Loss') }}:</strong> {{ $recipe->loss_percent }}%</p>
                    @if ($recipe->approval_note)
                        <p class="mb-1 text-danger"><strong>{{ __('Approval Note') }}:</strong> {{ $recipe->approval_note }}</p>
                    @endif
                    @if ($recipe->equipment)
                        <p class="mb-1"><strong>{{ __('Equipment') }}:</strong> {{ $recipe->equipment }}</p>
                    @endif
                    @if ($recipe->allergens)
                        <p class="mb-1"><strong>{{ __('Allergens') }}:</strong> {{ $recipe->allergens }}</p>
                    @endif
                    @if ($recipe->instructions)
                        <p class="mb-1"><strong>{{ __('Instructions') }}:</strong> {{ $recipe->instructions }}</p>
                    @endif
                    @if ($recipe->storage_conditions)
                        <p class="mb-1"><strong>{{ __('Storage Conditions') }}:</strong> {{ $recipe->storage_conditions }}</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Ingredients') }}</h5>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Quantity') }}</th>
                                <th>{{ __('Unit') }}</th>
                                <th>{{ __('Loss %') }}</th>
                                <th>{{ __('Alternatives') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($recipe->lines as $line)
                                <tr>
                                    <td>{{ $line->ingredientProduct ? $line->ingredientProduct->name : '-' }}</td>
                                    <td>{{ $line->quantity }}</td>
                                    <td>{{ $line->unit ? $line->unit->name : '-' }}</td>
                                    <td>{{ $line->loss_percent }}%</td>
                                    <td>
                                        @foreach ($line->alternatives as $alternative)
                                            {{ $alternative->alternativeProduct ? $alternative->alternativeProduct->name : '' }}@if (! $loop->last), @endif
                                        @endforeach
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5">{{ __('No ingredients yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Cost Preview') }}</h5>
                    @if ($costError)
                        <div class="alert alert-warning">{{ $costError }}</div>
                    @elseif ($cost)
                        <p class="mb-1"><strong>{{ __('Recipe Cost') }}:</strong> {{ number_format($cost['recipe_cost'], 2) }}</p>
                        <p class="mb-1"><strong>{{ __('Portions per Batch') }}:</strong> {{ $cost['portions'] ?? '-' }}</p>
                        <p class="mb-1"><strong>{{ __('Cost per Portion') }}:</strong> {{ $cost['cost_per_portion'] !== null ? number_format($cost['cost_per_portion'], 2) : '-' }}</p>
                        <p class="mb-1"><strong>{{ __('Selling Price (ex-VAT)') }}:</strong> {{ number_format($cost['selling_price_ex_vat'], 2) }}</p>
                        <p class="mb-1"><strong>{{ __('Food Cost %') }}:</strong> {{ $cost['food_cost_percent'] !== null ? $cost['food_cost_percent'] . '%' : '-' }}</p>
                        <p class="mb-1"><strong>{{ __('Band') }}:</strong> {{ \App\Services\KitchenRecipeCostService::bandLabel($cost['band']) }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
