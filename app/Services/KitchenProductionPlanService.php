<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenProductionPlanLine;
use App\Models\KitchenRecipe;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Models\User;
use App\Models\warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Kitchen Production — production plans (Phase 3, plan §3, §5–§6).
 *
 * A plan is the chef's forecast for one date + meal service: which dishes,
 * in what quantity, and why (occupancy, sales history, events, minimum
 * stock, special orders, manual). A plan never deducts stock itself —
 * it turns into production orders (one DRAFT order per plan line) and
 * those orders do the deduction.
 *
 * Workflow: DRAFT -> PENDING APPROVAL -> APPROVED -> IN PRODUCTION ->
 * COMPLETED, with REJECTED returning the plan to draft for correction.
 *
 * Approval chain (decision D5): Chef plans -> Head Chef approves ->
 * F&B Manager approves (ONLY if the company has an F&B Manager role
 * AND at least one company user holds it; otherwise the step is
 * skipped cleanly and the plan can never strand on it, plan §11.8) ->
 * General Manager approves. Every step is enforced here, in the
 * service layer, not only in the blade. No step may be approved by
 * the user who submitted the plan, nor by a user who already approved
 * an earlier step.
 *
 * Buffet and plate of the day are UNDECIDED by the client and DROP TO
 * V2 (plan §10 gate) — they are NOT built here, and nothing in this
 * service assumes they exist.
 */
class KitchenProductionPlanService
{
    public function __construct(
        protected KitchenOccupancyService $occupancy,
        protected KitchenProductionOrderService $orders,
    ) {
    }

    // -----------------------------------------------------------------
    // Creation / editing (draft only)
    // -----------------------------------------------------------------

    /**
     * @param array{plan_date: string, meal_service: string, warehouse_id: int, branch_id?: int|null, section?: string|null, notes?: string|null, lines: array<int, array{kitchen_recipe_id: int, planned_quantity: float|int, planned_unit_id?: int|null, source?: string, notes?: string|null}>} $data
     */
    public function create(array $data, User $user): KitchenProductionPlan
    {
        $creatorId = (int) $user->creatorId();

        $this->validateHeader($data, $creatorId);
        $lines = $this->validateLines($data['lines'] ?? [], $creatorId);

        return DB::transaction(function () use ($data, $lines, $user, $creatorId) {
            $plan = KitchenProductionPlan::create([
                'plan_number' => $this->nextPlanNumber($creatorId),
                'plan_date' => $data['plan_date'],
                'meal_service' => $data['meal_service'],
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => (int) $data['warehouse_id'],
                'section' => $data['section'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => KitchenProductionPlan::STATUS_DRAFT,
                'approval_step' => null,
                'occupancy_snapshot' => $this->occupancy->snapshot(
                    $data['plan_date'],
                    $creatorId,
                    $data['branch_id'] ?? null,
                ),
                'submitted_by' => (int) $user->id,
                'created_by' => $creatorId,
            ]);

            $this->syncLines($plan, $lines, $creatorId);

            return $plan->fresh('lines');
        });
    }

    /**
     * Edit a draft (or a rejected plan, which returns to draft and has
     * its approval trail cleared so the corrected plan goes through the
     * full chain again).
     */
    public function updateDraft(KitchenProductionPlan $plan, array $data, User $user): KitchenProductionPlan
    {
        $creatorId = (int) $plan->created_by;

        if (! $plan->isDraft() && ! $plan->isRejected()) {
            throw new InvalidArgumentException(__('Only a draft or rejected plan can be edited.'));
        }

        $merged = array_merge($plan->only(['plan_date', 'meal_service', 'warehouse_id', 'branch_id', 'section', 'notes']), $data);
        $this->validateHeader($merged, $creatorId);

        // plan_date may be a Carbon (from only()) or a string (from the
        // form): normalize to Y-m-d so the snapshot stores a plain date.
        $planDate = $merged['plan_date'] instanceof \DateTimeInterface
            ? $merged['plan_date']->format('Y-m-d')
            : (string) $merged['plan_date'];

        $lines = null;
        if (array_key_exists('lines', $data)) {
            $lines = $this->validateLines($data['lines'], $creatorId);
        }

        return DB::transaction(function () use ($plan, $merged, $planDate, $lines, $user, $creatorId) {
            $plan->update([
                'plan_date' => $planDate,
                'meal_service' => $merged['meal_service'],
                'branch_id' => $merged['branch_id'] ?? null,
                'warehouse_id' => (int) $merged['warehouse_id'],
                'section' => $merged['section'] ?? null,
                'notes' => $merged['notes'] ?? null,
                'status' => KitchenProductionPlan::STATUS_DRAFT,
                'approval_step' => null,
                // A fresh occupancy picture for the (possibly new) date.
                'occupancy_snapshot' => $this->occupancy->snapshot(
                    $planDate,
                    $creatorId,
                    $merged['branch_id'] ?? null,
                ),
                'submitted_by' => (int) $user->id,
                'head_chef_approved_by' => null,
                'head_chef_approved_at' => null,
                'fb_manager_approved_by' => null,
                'fb_manager_approved_at' => null,
                'gm_approved_by' => null,
                'gm_approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_note' => null,
            ]);

            if ($lines !== null) {
                $this->syncLines($plan, $lines, $creatorId);
            }

            return $plan->fresh('lines');
        });
    }

    // -----------------------------------------------------------------
    // Submission / approval chain
    // -----------------------------------------------------------------

    public function submit(KitchenProductionPlan $plan, User $user): KitchenProductionPlan
    {
        if (! $plan->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft plan can be submitted for approval.'));
        }

        if ($plan->lines()->count() === 0) {
            throw new InvalidArgumentException(__('A plan needs at least one dish before it can be submitted.'));
        }

        // Fresh occupancy at submit time, so approvers see today's numbers.
        $plan->update([
            'occupancy_snapshot' => $this->occupancy->snapshot(
                $plan->plan_date->format('Y-m-d'),
                (int) $plan->created_by,
                $plan->branch_id,
            ),
            'submitted_by' => (int) $user->id,
            'status' => KitchenProductionPlan::STATUS_PENDING_APPROVAL,
            'approval_step' => KitchenProductionPlan::STEP_HEAD_CHEF,
        ]);

        return $plan->fresh();
    }

    /**
     * Approve the plan's current step. The chain is:
     * head_chef -> fb_manager (only if the company has one) -> general_manager.
     * Each step must be approved by a different user, and nobody may
     * approve a plan they submitted.
     */
    public function approveStep(KitchenProductionPlan $plan, User $user): KitchenProductionPlan
    {
        if (! $plan->isPendingApproval()) {
            throw new InvalidArgumentException(__('Only a plan waiting for approval can be approved.'));
        }

        $step = $plan->approval_step ?? KitchenProductionPlan::STEP_HEAD_CHEF;

        if ((int) $user->id === (int) $plan->submitted_by) {
            throw new InvalidArgumentException(__('You cannot approve a plan you submitted yourself.'));
        }

        foreach ($this->priorApproverIds($plan) as $priorApproverId) {
            if ((int) $user->id === $priorApproverId) {
                throw new InvalidArgumentException(__('One user cannot approve two steps of the same plan.'));
            }
        }

        return DB::transaction(function () use ($plan, $user, $step) {
            $now = now();

            if ($step === KitchenProductionPlan::STEP_HEAD_CHEF) {
                $plan->update([
                    'head_chef_approved_by' => (int) $user->id,
                    'head_chef_approved_at' => $now,
                ]);
                $next = $this->fbManagerRequired((int) $plan->created_by)
                    ? KitchenProductionPlan::STEP_FB_MANAGER
                    : KitchenProductionPlan::STEP_GENERAL_MANAGER;
                $plan->update(['approval_step' => $next]);

                return $plan->fresh();
            }

            if ($step === KitchenProductionPlan::STEP_FB_MANAGER) {
                if (! $this->userIsFbManager($user, (int) $plan->created_by)) {
                    throw new InvalidArgumentException(__('Only the F&B Manager can approve this step of the plan.'));
                }

                $plan->update([
                    'fb_manager_approved_by' => (int) $user->id,
                    'fb_manager_approved_at' => $now,
                    'approval_step' => KitchenProductionPlan::STEP_GENERAL_MANAGER,
                ]);

                return $plan->fresh();
            }

            // General Manager — the last step. The plan is approved.
            $plan->update([
                'gm_approved_by' => (int) $user->id,
                'gm_approved_at' => $now,
                'status' => KitchenProductionPlan::STATUS_APPROVED,
                'approval_step' => null,
            ]);

            return $plan->fresh();
        });
    }

    /** Reject a plan waiting for approval; it returns to draft for correction. */
    public function reject(KitchenProductionPlan $plan, User $user, ?string $note = null): KitchenProductionPlan
    {
        if (! $plan->isPendingApproval()) {
            throw new InvalidArgumentException(__('Only a plan waiting for approval can be rejected.'));
        }

        if ((int) $user->id === (int) $plan->submitted_by) {
            throw new InvalidArgumentException(__('You cannot reject a plan you submitted yourself.'));
        }

        $plan->update([
            'status' => KitchenProductionPlan::STATUS_REJECTED,
            'approval_step' => null,
            'rejected_by' => (int) $user->id,
            'rejected_at' => now(),
            'rejection_note' => $note,
        ]);

        return $plan->fresh();
    }

    /** Re-take the occupancy snapshot while the plan is still a draft. */
    public function refreshOccupancy(KitchenProductionPlan $plan): KitchenProductionPlan
    {
        if (! $plan->isDraft()) {
            throw new InvalidArgumentException(__('Occupancy can only be refreshed while the plan is a draft.'));
        }

        $plan->update([
            'occupancy_snapshot' => $this->occupancy->snapshot(
                $plan->plan_date->format('Y-m-d'),
                (int) $plan->created_by,
                $plan->branch_id,
            ),
        ]);

        return $plan->fresh();
    }

    // -----------------------------------------------------------------
    // Requirements + shortages
    // -----------------------------------------------------------------

    /**
     * Ingredient requirements for the whole plan: every planned dish
     * scaled through its approved recipe, aggregated by ingredient, and
     * compared with what the kitchen warehouse already holds.
     *
     * @return array{lines: array<int, array{ingredient_id: int, ingredient_name: string, unit_id: int|null, unit_name: string|null, needed: float, available: float, shortage: float, unit_cost: float}>, total_cost: float}
     */
    public function requirements(KitchenProductionPlan $plan): array
    {
        $creatorId = (int) $plan->created_by;
        $aggregated = [];

        foreach ($plan->lines as $planLine) {
            $recipe = $planLine->recipe;
            if (! $recipe) {
                continue;
            }

            // planLines() scales the recipe to the planned quantity (with
            // unit conversion and per-line loss gross-up), exactly as an
            // order would. Its lines live under the 'lines' key.
            $planned = $this->orders->planLines(
                $recipe,
                (float) $planLine->planned_quantity,
                $planLine->planned_unit_id,
            );

            foreach ($planned['lines'] as $scaledLine) {
                $ingredientId = (int) $scaledLine['ingredient_product_id'];
                if (! isset($aggregated[$ingredientId])) {
                    $product = ProductService::where('id', $ingredientId)
                        ->where('created_by', $creatorId)
                        ->first();
                    $unit = $scaledLine['planned_unit_id'] !== null
                        ? ProductServiceUnit::find((int) $scaledLine['planned_unit_id'])
                        : null;
                    $aggregated[$ingredientId] = [
                        'ingredient_id' => $ingredientId,
                        'ingredient_name' => $product ? $product->name : '#'.$ingredientId,
                        'unit_id' => $scaledLine['planned_unit_id'] ?? null,
                        'unit_name' => $unit ? $unit->name : null,
                        'needed' => 0.0,
                        'available' => 0.0,
                        'shortage' => 0.0,
                        'unit_cost' => (float) ($scaledLine['planned_unit_cost'] ?? 0),
                    ];
                }
                $aggregated[$ingredientId]['needed'] = round(
                    $aggregated[$ingredientId]['needed'] + (float) $scaledLine['planned_quantity'],
                    4,
                );
            }
        }

        $totalCost = 0.0;
        foreach ($aggregated as $ingredientId => $row) {
            $product = ProductService::where('id', $ingredientId)
                ->where('created_by', $creatorId)
                ->first();
            $available = $this->orders->warehouseQty($ingredientId, (int) $plan->warehouse_id, $creatorId);
            // Negative-stock-allowed products are never short (same rule
            // as production orders): the kitchen may plan past zero.
            $shortage = $product && $product->allowsNegativeStock()
                ? 0.0
                : max(0.0, round($row['needed'] - $available, 4));

            $aggregated[$ingredientId]['available'] = $available;
            $aggregated[$ingredientId]['shortage'] = $shortage;
            $totalCost += $row['needed'] * $row['unit_cost'];
        }

        return [
            'lines' => array_values($aggregated),
            'total_cost' => round($totalCost, 2),
        ];
    }

    // -----------------------------------------------------------------
    // Plan -> production orders
    // -----------------------------------------------------------------

    /**
     * Turn an approved plan into production orders: one DRAFT order per
     * plan line, linked back to the plan. Orders then follow their own
     * lifecycle (approve -> start -> complete -> validate). The plan
     * moves to IN PRODUCTION; calling this twice is impossible because
     * only an APPROVED plan can be converted.
     *
     * @return KitchenProductionOrder[]
     */
    public function createOrdersFromPlan(KitchenProductionPlan $plan, User $user): array
    {
        if (! $plan->isApproved()) {
            throw new InvalidArgumentException(__('Only an approved plan can be turned into production orders.'));
        }

        if ($plan->lines()->count() === 0) {
            throw new InvalidArgumentException(__('A plan needs at least one dish before it can be turned into production orders.'));
        }

        return DB::transaction(function () use ($plan, $user) {
            $created = [];

            foreach ($plan->lines as $planLine) {
                $created[] = $this->orders->create([
                    'kitchen_recipe_id' => (int) $planLine->kitchen_recipe_id,
                    'kitchen_production_plan_id' => (int) $plan->id,
                    'production_date' => $plan->plan_date->format('Y-m-d'),
                    'section' => $plan->section,
                    'branch_id' => $plan->branch_id,
                    'warehouse_id' => (int) $plan->warehouse_id,
                    'planned_quantity' => (float) $planLine->planned_quantity,
                    'planned_unit_id' => $planLine->planned_unit_id,
                    'notes' => $planLine->notes,
                ], $user);
            }

            $plan->update(['status' => KitchenProductionPlan::STATUS_IN_PRODUCTION]);

            return $created;
        });
    }

    /** A plan is complete only when every order it generated is done. */
    public function complete(KitchenProductionPlan $plan, User $user): KitchenProductionPlan
    {
        if (! $plan->isInProduction()) {
            throw new InvalidArgumentException(__('Only a plan in production can be completed.'));
        }

        $open = $plan->orders()
            ->whereNotIn('status', [
                KitchenProductionOrder::STATUS_COMPLETED,
                KitchenProductionOrder::STATUS_VALIDATED,
            ])
            ->orderBy('id')
            ->first();

        if ($open) {
            throw new InvalidArgumentException(__(
                'Production order :order is not finished yet.',
                ['order' => $open->order_number ?? ('#'.$open->id)],
            ));
        }

        $plan->update(['status' => KitchenProductionPlan::STATUS_COMPLETED]);

        return $plan->fresh();
    }

    // -----------------------------------------------------------------
    // F&B Manager detection (plan §11.8)
    // -----------------------------------------------------------------

    /**
     * Does this company have an F&B Manager who can approve plans? The
     * step exists only when a role named like "F&B Manager" / "Food and
     * Beverage Manager" belongs to the company AND at least one company
     * user holds it. A role that exists but is unassigned does NOT
     * count — otherwise a plan could strand forever waiting for an
     * approver who does not exist.
     */
    public function fbManagerRequired(int $creatorId): bool
    {
        $roleIds = $this->fbManagerRoleIds($creatorId);
        if ($roleIds === []) {
            return false;
        }

        if (! Schema::hasTable('model_has_roles')) {
            return false;
        }

        $companyUserIds = User::where('id', $creatorId)
            ->orWhere('created_by', $creatorId)
            ->pluck('id')
            ->all();

        if ($companyUserIds === []) {
            return false;
        }

        return DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('model_type', User::class)
            ->whereIn('model_id', $companyUserIds)
            ->exists();
    }

    /** Does this user hold the company's F&B Manager role? */
    public function userIsFbManager(User $user, int $creatorId): bool
    {
        $roleIds = $this->fbManagerRoleIds($creatorId);
        if ($roleIds === []) {
            return false;
        }

        if (! Schema::hasTable('model_has_roles')) {
            return false;
        }

        return DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('model_type', User::class)
            ->where('model_id', (int) $user->id)
            ->exists();
    }

    /** Spatie role ids for the company whose name means "F&B Manager". */
    protected function fbManagerRoleIds(int $creatorId): array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasColumn('roles', 'created_by')) {
            return [];
        }

        return Role::where('created_by', $creatorId)
            ->pluck('id', 'name')
            ->filter(fn ($id, $name) => in_array(
                $this->normalizeRoleName($name),
                ['fbmanager', 'fandbmanager', 'foodandbeveragemanager'],
                true,
            ))
            ->values()
            ->all();
    }

    protected function normalizeRoleName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function validateHeader(array $data, int $creatorId): void
    {
        if (empty($data['plan_date'])) {
            throw new InvalidArgumentException(__('The plan date is required.'));
        }

        if (! in_array($data['meal_service'] ?? null, KitchenProductionPlan::MEAL_SERVICES, true)) {
            throw new InvalidArgumentException(__('The meal service is required.'));
        }

        if (empty($data['warehouse_id']) || ! warehouse::where('id', (int) $data['warehouse_id'])
            ->where('created_by', $creatorId)->exists()) {
            throw new InvalidArgumentException(__('The kitchen warehouse is required.'));
        }

        if (! empty($data['branch_id']) && ! Branch::where('id', (int) $data['branch_id'])
            ->where('created_by', $creatorId)->exists()) {
            throw new InvalidArgumentException(__('The selected branch does not exist.'));
        }

        if (! empty($data['section']) && ! in_array($data['section'], KitchenProductionOrder::SECTIONS, true)) {
            throw new InvalidArgumentException(__('The selected section is not valid.'));
        }
    }

    /** @return array<int, array{kitchen_recipe_id: int, recipe_version: int, planned_quantity: float, planned_unit_id: int|null, source: string, notes: string|null}> */
    protected function validateLines(array $lines, int $creatorId): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException(__('A plan needs at least one dish.'));
        }

        $validated = [];
        foreach ($lines as $line) {
            $recipeId = (int) ($line['kitchen_recipe_id'] ?? 0);
            $recipe = KitchenRecipe::where('id', $recipeId)
                ->where('created_by', $creatorId)
                ->first();

            if (! $recipe) {
                throw new InvalidArgumentException(__('One of the selected recipes does not exist.'));
            }

            if (! $recipe->isApproved()) {
                throw new InvalidArgumentException(__('Only an approved recipe can be planned.'));
            }

            $quantity = (float) ($line['planned_quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new InvalidArgumentException(__('Planned quantities must be greater than zero.'));
            }

            $unitId = isset($line['planned_unit_id']) && $line['planned_unit_id'] !== ''
                ? (int) $line['planned_unit_id']
                : null;
            if ($unitId !== null && ! ProductServiceUnit::where('id', $unitId)
                ->where('created_by', $creatorId)->exists()) {
                throw new InvalidArgumentException(__('One of the selected units does not exist.'));
            }

            $source = $line['source'] ?? KitchenProductionPlan::SOURCE_MANUAL;
            if (! in_array($source, KitchenProductionPlan::SOURCES, true)) {
                $source = KitchenProductionPlan::SOURCE_MANUAL;
            }

            $validated[] = [
                'kitchen_recipe_id' => $recipeId,
                'recipe_version' => (int) $recipe->version,
                'planned_quantity' => $quantity,
                'planned_unit_id' => $unitId,
                'source' => $source,
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $validated;
    }

    protected function syncLines(KitchenProductionPlan $plan, array $lines, int $creatorId): void
    {
        $plan->lines()->delete();

        foreach ($lines as $line) {
            KitchenProductionPlanLine::create(array_merge($line, [
                'kitchen_production_plan_id' => (int) $plan->id,
                'created_by' => $creatorId,
            ]));
        }
    }

    /** @return int[] user ids who already approved a step of this plan. */
    protected function priorApproverIds(KitchenProductionPlan $plan): array
    {
        return array_values(array_filter([
            $plan->head_chef_approved_by !== null ? (int) $plan->head_chef_approved_by : null,
            $plan->fb_manager_approved_by !== null ? (int) $plan->fb_manager_approved_by : null,
            $plan->gm_approved_by !== null ? (int) $plan->gm_approved_by : null,
        ]));
    }

    public function nextPlanNumber(int $creatorId): string
    {
        $year = now()->format('Y');
        $prefix = 'PL-'.$year.'-';

        $count = KitchenProductionPlan::where('created_by', $creatorId)
            ->where('plan_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
