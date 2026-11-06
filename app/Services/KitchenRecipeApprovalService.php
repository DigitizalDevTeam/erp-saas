<?php

namespace App\Services;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenRecipe;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recipe approval workflow (plan §6) — enforced in the service layer,
 * not only in the blade:
 *
 *   DRAFT -> PENDING APPROVAL -> APPROVED -> ARCHIVED
 *   PENDING APPROVAL -> DRAFT (returned / rejected)
 *
 * Rules:
 * - Only an APPROVED version can be used later (production orders / POS).
 * - No self-approval: the user who submitted the recipe cannot approve it.
 * - Approval is BLOCKED when a line cannot be converted to the
 *   ingredient's stock unit (missing conversion) — never guess a factor.
 * - Changing an APPROVED recipe creates a NEW version; the old version
 *   stays readable and its lines are copied, never edited in place.
 */
class KitchenRecipeApprovalService
{
    public function __construct(
        protected KitchenUnitConversionService $conversions,
    ) {
    }

    public function submit(KitchenRecipe $recipe, User $user): KitchenRecipe
    {
        $this->assertSameCompany($recipe, $user);

        if (! $recipe->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft recipe can be submitted for approval.'));
        }

        $this->validateApprovable($recipe);

        $recipe->status = KitchenRecipe::STATUS_PENDING_APPROVAL;
        $recipe->submitted_by = (int) $user->id;
        $recipe->approval_note = null;
        $recipe->save();

        return $recipe->fresh();
    }

    public function approve(KitchenRecipe $recipe, User $user): KitchenRecipe
    {
        $this->assertSameCompany($recipe, $user);

        if (! $recipe->isPendingApproval()) {
            throw new InvalidArgumentException(__('Only a recipe pending approval can be approved.'));
        }

        if ((int) $recipe->submitted_by === (int) $user->id) {
            throw new InvalidArgumentException(__('You cannot approve a recipe you submitted yourself.'));
        }

        $this->validateApprovable($recipe);

        $recipe->status = KitchenRecipe::STATUS_APPROVED;
        $recipe->approved_by = (int) $user->id;
        $recipe->approved_at = now();
        $recipe->approval_note = null;
        $recipe->save();

        return $recipe->fresh();
    }

    public function reject(KitchenRecipe $recipe, User $user, ?string $note = null): KitchenRecipe
    {
        $this->assertSameCompany($recipe, $user);

        if (! $recipe->isPendingApproval()) {
            throw new InvalidArgumentException(__('Only a recipe pending approval can be returned.'));
        }

        if ((int) $recipe->submitted_by === (int) $user->id) {
            throw new InvalidArgumentException(__('You cannot return a recipe you submitted yourself.'));
        }

        $recipe->status = KitchenRecipe::STATUS_DRAFT;
        $recipe->submitted_by = null;
        $recipe->approval_note = $note;
        $recipe->save();

        return $recipe->fresh();
    }

    public function archive(KitchenRecipe $recipe, User $user): KitchenRecipe
    {
        $this->assertSameCompany($recipe, $user);

        if (! $recipe->isApproved()) {
            throw new InvalidArgumentException(__('Only an approved recipe can be archived.'));
        }

        $recipe->status = KitchenRecipe::STATUS_ARCHIVED;
        $recipe->save();

        return $recipe->fresh();
    }

    /**
     * Create a new DRAFT version of an approved recipe, copying the
     * header and all lines (with their alternatives). The old version
     * is never edited in place.
     */
    public function createNewVersion(KitchenRecipe $recipe, User $user): KitchenRecipe
    {
        $this->assertSameCompany($recipe, $user);

        if (! $recipe->isApproved()) {
            throw new InvalidArgumentException(__('Only an approved recipe can be versioned.'));
        }

        return DB::transaction(function () use ($recipe, $user) {
            $nextVersion = (int) KitchenRecipe::where('created_by', $recipe->created_by)
                ->where('code', $recipe->code)
                ->max('version') + 1;

            $copy = $recipe->replicate();
            $copy->version = $nextVersion;
            $copy->status = KitchenRecipe::STATUS_DRAFT;
            $copy->submitted_by = null;
            $copy->approved_by = null;
            $copy->approved_at = null;
            $copy->approval_note = null;
            $copy->save();

            foreach ($recipe->lines()->with('alternatives')->get() as $line) {
                $lineCopy = $line->replicate();
                $lineCopy->kitchen_recipe_id = (int) $copy->id;
                $lineCopy->version = $nextVersion;
                $lineCopy->save();

                foreach ($line->alternatives as $alternative) {
                    $altCopy = $alternative->replicate();
                    $altCopy->product_recipe_id = (int) $lineCopy->id;
                    $altCopy->save();
                }
            }

            return $copy->fresh();
        });
    }

    /**
     * A recipe is approvable when it has at least one line and every
     * line can be converted to its ingredient's stock unit.
     *
     * @throws KitchenConversionMissingException
     */
    public function validateApprovable(KitchenRecipe $recipe): void
    {
        $lines = $recipe->lines()->with('ingredientProduct')->get();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException(__('A recipe needs at least one ingredient before it can be approved.'));
        }

        foreach ($lines as $line) {
            $ingredient = $line->ingredientProduct;
            if (! $ingredient instanceof ProductService) {
                continue;
            }

            $this->conversions->convertToStockUnit(
                (float) $line->quantity,
                $line->unit_id ? (int) $line->unit_id : null,
                $ingredient,
                (int) $recipe->created_by
            );
        }
    }

    protected function assertSameCompany(KitchenRecipe $recipe, User $user): void
    {
        if ((int) $user->creatorId() !== (int) $recipe->created_by) {
            throw new InvalidArgumentException(__('This recipe belongs to another company.'));
        }
    }
}
