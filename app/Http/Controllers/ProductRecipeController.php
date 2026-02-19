<?php

namespace App\Http\Controllers;

use App\Models\ProductRecipe;
use App\Models\ProductService;
use Illuminate\Http\Request;

/**
 * Phase 5: Recipe/BOM – manage product ingredients (which stock items to deduct per unit sold).
 */
class ProductRecipeController extends Controller
{
    public function index(Request $request)
    {
        if (!\Auth::user()->can('manage product & service') && !\Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $products = ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->orderBy('name')
            ->get();
        $productId = $request->get('product_id');
        $recipes = collect();
        $ingredients = collect();
        if ($productId) {
            $recipes = ProductRecipe::where('product_id', $productId)
                ->where('created_by', $creatorId)
                ->with(['ingredientProduct', 'unit'])
                ->orderBy('id')
                ->get();
            $ingredients = ProductService::where('created_by', $creatorId)
                ->where('type', 'product')
                ->orderBy('name')
                ->get();
        }
        return view('product_recipe.index', compact('products', 'productId', 'recipes', 'ingredients'));
    }

    public function store(Request $request)
    {
        if (!\Auth::user()->can('manage product & service') && !\Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        $request->validate([
            'product_id' => 'required|exists:product_services,id',
            'ingredient_product_id' => 'required|exists:product_services,id',
            'quantity' => 'required|numeric|min:0.0001',
        ]);
        $creatorId = \Auth::user()->creatorId();
        $product = ProductService::where('id', $request->product_id)->where('created_by', $creatorId)->first();
        $ingredient = ProductService::where('id', $request->ingredient_product_id)->where('created_by', $creatorId)->first();
        if (!$product || !$ingredient) {
            return redirect()->back()->with('error', __('Invalid product or ingredient.'));
        }
        if ((int) $request->product_id === (int) $request->ingredient_product_id) {
            return redirect()->back()->with('error', __('Product and ingredient cannot be the same.'));
        }
        ProductRecipe::create([
            'product_id' => $request->product_id,
            'ingredient_product_id' => $request->ingredient_product_id,
            'quantity' => $request->quantity,
            'unit_id' => $request->unit_id ?: null,
            'created_by' => $creatorId,
        ]);
        return redirect()->route('product-recipe.index', ['product_id' => $request->product_id])->with('success', __('Ingredient added.'));
    }

    public function destroy(ProductRecipe $product_recipe)
    {
        if (!\Auth::user()->can('manage product & service') && !\Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        if ($product_recipe->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $productId = $product_recipe->product_id;
        $product_recipe->delete();
        return redirect()->route('product-recipe.index', ['product_id' => $productId])->with('success', __('Ingredient removed.'));
    }
}
