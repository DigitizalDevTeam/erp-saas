@extends('layouts.admin')
@section('page-title')
    {{ __('Product Recipes (BOM)') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('productservice.index') }}">{{ __('Product') }}</a></li>
    <li class="breadcrumb-item">{{ __('Recipes') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Product (dish)') }}</label>
                            <select name="product_id" class="form-select" onchange="this.form.submit()" style="min-width:220px;">
                                <option value="">{{ __('Select product') }}</option>
                                @foreach($products as $p)
                                    <option value="{{ $p->id }}" {{ (string)$productId === (string)$p->id ? 'selected' : '' }}>{{ $p->name }}{{ $p->sku ? ' (' . $p->sku . ')' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if($productId)
                    <h6>{{ __('Recipe ingredients') }}</h6>
                    <p class="text-muted small">{{ __('When this product is sold, stock is deducted from each ingredient by the quantity below (per 1 unit sold).') }}</p>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Ingredient') }}</th>
                                    <th class="text-end">{{ __('Quantity per unit') }}</th>
                                    <th>{{ __('Unit') }}</th>
                                    <th width="80"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recipes as $r)
                                    <tr>
                                        <td>{{ $r->ingredientProduct ? $r->ingredientProduct->name : '-' }}</td>
                                        <td class="text-end">{{ $r->quantity }}</td>
                                        <td>{{ $r->unit ? $r->unit->name : '—' }}</td>
                                        <td>
                                            {{ Form::open(['url' => route('product-recipe.destroy', $r), 'method' => 'DELETE', 'class' => 'd-inline']) }}
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __("Remove this ingredient?") }}');"><i class="ti ti-trash"></i></button>
                                            {{ Form::close() }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">{{ __('No recipe lines. Add ingredients below.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ Form::open(['route' => 'product-recipe.store', 'method' => 'post']) }}
                    <input type="hidden" name="product_id" value="{{ $productId }}">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Ingredient') }}</label>
                            <select name="ingredient_product_id" class="form-select" required>
                                <option value="">{{ __('Select ingredient') }}</option>
                                @foreach($ingredients as $i)
                                    @if($i->id != $productId)
                                        <option value="{{ $i->id }}">{{ $i->name }}{{ $i->sku ? ' (' . $i->sku . ')' : '' }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Quantity') }}</label>
                            <input type="number" name="quantity" class="form-control" step="0.0001" min="0.0001" value="1" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">{{ __('Add') }}</button>
                        </div>
                    </div>
                    {{ Form::close() }}
                    @else
                    <p class="text-muted">{{ __('Select a product to manage its recipe (ingredients).') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
