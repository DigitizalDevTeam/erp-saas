<?php

namespace App\Services;

use App\Models\PosLineIngredientSubstitution;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeAlternative;
use App\Models\ProductService;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Manual ingredient substitution at cooking time (production screen).
 *
 * The cook picks a defined alternative for a recipe ingredient that is
 * missing (zero stock in the line's warehouse). Stock is then deducted
 * from the alternative actually used, never from the missing one.
 * One substitution per order line + recipe: picking twice would deduct
 * the alternative twice, so a second pick is rejected.
 */
class RecipeIngredientSubstitutionService
{
    /**
     * @return array{substitution: PosLineIngredientSubstitution, quantity: float}
     *
     * @throws InvalidArgumentException
     */
    public function substitute(PosProduct $line, int $recipeId, int $alternativeId, int $creatorId): array
    {
        // Clean error instead of a 500 when the migration has not run yet.
        if (! Schema::hasTable('product_recipe_alternatives') || ! Schema::hasTable('pos_line_ingredient_substitutions')) {
            throw new InvalidArgumentException('Ingredient alternatives are not set up yet. Run the migrations first.');
        }
        if ($line->sent_at === null) {
            throw new InvalidArgumentException('Only sent order lines can use a substitute.');
        }
        if (! in_array($line->status, [PosProduct::STATUS_PENDING, PosProduct::STATUS_IN_PREPARATION, PosProduct::STATUS_READY], true)) {
            throw new InvalidArgumentException('This order line can no longer use a substitute.');
        }
        if (! empty($line->voided_at) || ! empty($line->removed_from_cart_at)) {
            throw new InvalidArgumentException('This order line can no longer use a substitute.');
        }

        $recipe = ProductRecipe::where('id', $recipeId)
            ->where('product_id', $line->product_id)
            ->where('created_by', $creatorId)
            ->first();
        if (! $recipe) {
            throw new InvalidArgumentException('Recipe ingredient not found for this order line.');
        }

        $main = ProductService::where('id', $recipe->ingredient_product_id)->where('created_by', $creatorId)->first();
        if (! $main || ($main->type ?? '') !== 'ingredient') {
            throw new InvalidArgumentException('Recipe ingredient not found.');
        }
        // An ingredient that never moves stock has nothing to substitute.
        if (! ($main->affect_stock ?? true)) {
            throw new InvalidArgumentException('This ingredient does not affect stock.');
        }

        $defined = ProductRecipeAlternative::where('product_recipe_id', $recipe->id)
            ->where('alternative_product_id', $alternativeId)
            ->first();
        if (! $defined) {
            throw new InvalidArgumentException('This alternative is not defined for the ingredient.');
        }
        $alternative = ProductService::where('id', $alternativeId)->where('created_by', $creatorId)->first();
        if (! $alternative || ($alternative->type ?? '') !== 'ingredient' || (int) $alternative->id === (int) $main->id) {
            throw new InvalidArgumentException('Invalid alternative ingredient.');
        }
        if (! ($alternative->affect_stock ?? true)) {
            throw new InvalidArgumentException('The alternative ingredient does not affect stock.');
        }

        $already = PosLineIngredientSubstitution::where('pos_product_id', $line->id)
            ->where('product_recipe_id', $recipe->id)
            ->exists();
        if ($already) {
            throw new InvalidArgumentException('A substitute was already picked for this ingredient on this order.');
        }

        $line->loadMissing('pos');
        $warehouseId = (int) ($line->warehouse_id ?: ($line->pos->warehouse_id ?? 0));
        if ($warehouseId <= 0) {
            throw new InvalidArgumentException('No warehouse found for this order line.');
        }

        // The trigger is a missing main ingredient: zero stock in the
        // warehouse the order was deducted from. (At send time its
        // deduction clamped to zero, so deducting the alternative now
        // cannot double-count it.)
        $mainStock = (float) (WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $main->id)
            ->whereNull('product_variant_id')
            ->value('quantity') ?? 0);
        if ($mainStock > 0) {
            throw new InvalidArgumentException('The main ingredient is still in stock.');
        }

        $quantity = (float) $recipe->quantity * (float) $line->quantity;
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Nothing to substitute for this ingredient.');
        }

        $pos = $line->pos;
        $posNumber = $pos ? (string) ($pos->pos_id ?? $pos->id) : (string) $line->pos_id;
        PosRecipeDeductionService::deductForSubstitution(
            (int) $alternative->id,
            $quantity,
            $warehouseId,
            $creatorId,
            $posNumber,
            (int) $line->pos_id,
            (string) $main->name
        );

        $substitution = PosLineIngredientSubstitution::create([
            'pos_product_id' => $line->id,
            'product_recipe_id' => $recipe->id,
            'main_ingredient_id' => $main->id,
            'alternative_id' => $alternative->id,
            'quantity' => $quantity,
            'warehouse_id' => $warehouseId,
            'created_by' => $creatorId,
        ]);

        return ['substitution' => $substitution, 'quantity' => $quantity];
    }
}
