<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\PosShift;
use Illuminate\Http\Request;

/**
 * Phase 1: Production screen for Kitchen/Bar – view orders by zone and update line status.
 */
class PosProductionController extends Controller
{
    public function index(Request $request)
    {
        if (!\Auth::user()->canManagePos() && !\Auth::user()->can('manage pos production')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $user = \Auth::user();
        $creatorId = $user->creatorId();
        $user->loadMissing('branches');
        $restaurantPool = Branch::where('created_by', $creatorId)
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->pluck('id')
            ->toArray();
        $allowedRestaurantIds = $user->filterBranchIdsForPosAccess($restaurantPool);
        $branches = Branch::whereIn('id', $allowedRestaurantIds)
            ->orderBy('name')
            ->get();

        $showZoneSelector = $user->can('manage pos');
        $showBranchSelector = $showZoneSelector || $branches->count() > 1;

        $branchId = $request->get('branch_id');
        $zone = $request->get('zone', PosProduct::PRINT_ZONE_KITCHEN);
        $warehouseId = $request->get('warehouse_id');

        if (! $showZoneSelector) {
            if ($user->can('manage pos production')) {
                $roleNames = $user->roles->pluck('name')->toArray();
                if (in_array(\App\Services\PosRestaurantRoleService::ROLE_BAR, $roleNames)) {
                    $zone = PosProduct::PRINT_ZONE_BAR;
                } else {
                    $zone = PosProduct::PRINT_ZONE_KITCHEN;
                }
            }
        }

        if (! $branchId && $branches->count() === 1) {
            $branchId = $branches->first()->id;
        }
        if (! $branchId && $branches->count() > 1) {
            $branchId = $branches->first()->id;
        }

        if ($branches->isEmpty() || ! $branchId) {
            return redirect()->route('dashboard')->with('error', __('No branch assigned. Please contact admin to assign a branch.'));
        }
        if (! $user->canAccessBranchForPos((int) $branchId, 'restaurant')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $warehouses = \App\Models\warehouse::where('created_by', $creatorId)
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->orderBy('name')
            ->get();
        if ($warehouseId !== null && $warehouseId !== '' && ! $warehouses->contains('id', (int) $warehouseId)) {
            $warehouseId = null;
        }

        $lines = collect();
        if ($branchId) {
            $openShiftIds = PosShift::where('branch_id', $branchId)
                ->where('status', PosShift::STATUS_OPEN)
                ->where('created_by', $creatorId)
                ->pluck('id');

            $lines = PosProduct::with(['pos.table.waiterUser', 'pos.shift', 'pos.posUser', 'product'])
                ->whereHas('pos', function ($q) use ($openShiftIds, $creatorId, $warehouseId) {
                    $q->whereIn('shift_id', $openShiftIds)
                        ->where('created_by', $creatorId)
                        ->whereNull('cancelled_at');
                    if ($warehouseId) {
                        $q->where('warehouse_id', (int) $warehouseId);
                    }
                })
                ->forProductionZone($zone)
                ->pendingOrInProgress()
                ->orderBy('sent_at')
                ->orderBy('pos_id')
                ->get();
        }

        // Manual substitutes (recipe ingredient alternatives): per line,
        // recipe ingredients that are missing in the line's warehouse and
        // have defined alternatives, plus labels for substitutes already
        // picked on the line.
        $lineSubstitutes = [];
        $lineSubstitutedLabels = [];
        if ($lines->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('product_recipe_alternatives') && \Illuminate\Support\Facades\Schema::hasTable('pos_line_ingredient_substitutions')) {
            $recipesByProduct = \App\Models\ProductRecipe::whereIn('product_id', $lines->pluck('product_id')->unique()->values()->all())
                ->where('created_by', $creatorId)
                ->with(['alternatives.alternativeProduct', 'ingredientProduct'])
                ->get()
                ->groupBy('product_id');
            $doneByLine = \App\Models\PosLineIngredientSubstitution::whereIn('pos_product_id', $lines->pluck('id')->all())
                ->with(['mainProduct', 'alternativeProduct'])
                ->get()
                ->groupBy('pos_product_id');
            $ingredientIds = $recipesByProduct->flatten()->map(fn ($r) => $r->ingredient_product_id)->unique()->values()->all();
            $warehouseIds = $lines->map(fn ($l) => (int) ($l->warehouse_id ?: ($l->pos->warehouse_id ?? 0)))->filter()->unique()->values()->all();
            $stockByKey = \App\Models\WarehouseProduct::whereIn('product_id', $ingredientIds)
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereNull('product_variant_id')
                ->get()
                ->keyBy(fn ($w) => $w->warehouse_id . '_' . $w->product_id);
            foreach ($lines as $line) {
                $doneForLine = $doneByLine->get($line->id, collect());
                foreach ($doneForLine as $done) {
                    $lineSubstitutedLabels[$line->id][] = ($done->alternativeProduct ? $done->alternativeProduct->name : __('Alternative'))
                        . ' for ' . ($done->mainProduct ? $done->mainProduct->name : __('Ingredient'));
                }
                $lineWarehouseId = (int) ($line->warehouse_id ?: ($line->pos->warehouse_id ?? 0));
                if ($lineWarehouseId <= 0) {
                    continue;
                }
                foreach ($recipesByProduct->get($line->product_id, collect()) as $recipe) {
                    $main = $recipe->ingredientProduct;
                    if (! $main || ($main->type ?? '') !== 'ingredient' || ! ($main->affect_stock ?? true)) {
                        continue;
                    }
                    if ($doneForLine->contains(fn ($d) => (int) $d->product_recipe_id === (int) $recipe->id)) {
                        continue;
                    }
                    $stock = (float) ($stockByKey->get($lineWarehouseId . '_' . $main->id)->quantity ?? 0);
                    if ($stock > 0) {
                        continue;
                    }
                    $alts = [];
                    foreach ($recipe->alternatives as $alt) {
                        $ap = $alt->alternativeProduct;
                        if (! $ap || ($ap->type ?? '') !== 'ingredient' || ! ($ap->affect_stock ?? true) || (int) $ap->id === (int) $main->id) {
                            continue;
                        }
                        $alts[] = ['id' => (int) $ap->id, 'name' => (string) $ap->name];
                    }
                    if ($alts === []) {
                        continue;
                    }
                    $lineSubstitutes[$line->id][] = [
                        'recipe_id' => (int) $recipe->id,
                        'main_id' => (int) $main->id,
                        'main_name' => (string) $main->name,
                        'needed_qty' => (float) $recipe->quantity * (float) $line->quantity,
                        'alternatives' => $alts,
                    ];
                }
            }
        }

        return view('pos_production.index', compact('branches', 'branchId', 'zone', 'lines', 'showBranchSelector', 'showZoneSelector', 'warehouses', 'warehouseId', 'lineSubstitutes', 'lineSubstitutedLabels'));
    }

    public function updateLineStatus(Request $request, PosProduct $line)
    {
        if (!\Auth::user()->canManagePos() && !\Auth::user()->can('manage pos production')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'status' => 'required|in:' . PosProduct::STATUS_IN_PREPARATION . ',' . PosProduct::STATUS_READY,
        ]);

        if ($line->pos->created_by != \Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $line->status = $request->status;
        if ($request->status === PosProduct::STATUS_READY) {
            $line->ready_at = $line->ready_at ?? now();
        }
        $line->save();

        return response()->json(['success' => true, 'message' => __('Status updated.'), 'line' => $line->fresh()]);
    }

    /**
     * Manual substitute at cooking time: the cook picks a defined
     * alternative for a missing recipe ingredient; stock is deducted
     * from the alternative actually used.
     */
    public function substituteLineIngredient(Request $request, PosProduct $line)
    {
        if (!\Auth::user()->canManagePos() && !\Auth::user()->can('manage pos production')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'recipe_id' => 'required|exists:product_recipes,id',
            'alternative_product_id' => 'required|exists:product_services,id',
        ]);

        if ($line->pos->created_by != \Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        try {
            (new \App\Services\RecipeIngredientSubstitutionService())->substitute(
                $line,
                (int) $request->recipe_id,
                (int) $request->alternative_product_id,
                (int) \Auth::user()->creatorId()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => __($e->getMessage())], 422);
        }

        return response()->json(['success' => true, 'message' => __('Substitute applied. Stock deducted from the alternative.')]);
    }
}
