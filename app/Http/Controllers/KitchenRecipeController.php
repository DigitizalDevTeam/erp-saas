<?php

namespace App\Http\Controllers;

use App\Models\KitchenRecipe;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeAlternative;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Services\KitchenRecipeApprovalService;
use App\Services\KitchenRecipeCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Kitchen Production — Recipes and technical sheets (Phase 0).
 *
 * A recipe header (kitchen_recipes) + lines in the existing
 * product_recipes table (kitchen_recipe_id + version). Editing an
 * APPROVED recipe creates a NEW version; the old version is never
 * edited in place. Approval runs through KitchenRecipeApprovalService
 * (service layer, not only the blade).
 */
class KitchenRecipeController extends Controller
{
    public function __construct(
        protected KitchenRecipeApprovalService $approval,
        protected KitchenRecipeCostService $costs,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $recipes = KitchenRecipe::where('created_by', $creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)->orWhere('code', 'like', $search);
                });
            })
            ->with('product')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('kitchen.recipes.index', compact('recipes'));
    }

    public function create()
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $recipe = new KitchenRecipe(['status' => KitchenRecipe::STATUS_DRAFT, 'version' => 1]);

        return view('kitchen.recipes.form', array_merge($this->formData($creatorId), [
            'recipe' => $recipe,
            'code' => $this->nextCode($creatorId),
            'lines' => [],
        ]));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateHeader($request, $creatorId);

        $recipe = DB::transaction(function () use ($request, $data, $creatorId) {
            $recipe = KitchenRecipe::create(array_merge($data, [
                'version' => 1,
                'status' => KitchenRecipe::STATUS_DRAFT,
                'created_by' => $creatorId,
            ]));

            if ($request->hasFile('photo')) {
                $recipe->photo = $request->file('photo')->store('kitchen-recipes', 'public');
                $recipe->save();
            }

            $this->syncLines($recipe, $request, $creatorId);
            $this->hideIntermediateFromPos($recipe);

            return $recipe;
        });

        return redirect()->route('kitchen.recipes.show', $recipe->id)
            ->with('success', __('Recipe successfully created.'));
    }

    public function show(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        $cost = null;
        $costError = null;
        try {
            $cost = $this->costs->calculate($recipe->load('lines.ingredientProduct', 'product'));
        } catch (\Throwable $e) {
            $costError = $e->getMessage();
        }

        return view('kitchen.recipes.show', [
            'recipe' => $recipe->load('lines.ingredientProduct', 'lines.alternatives', 'product'),
            'cost' => $cost,
            'costError' => $costError,
        ]);
    }

    public function edit(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        // An APPROVED recipe is never edited in place: open a new version.
        if ($recipe->isApproved()) {
            $copy = $this->approval->createNewVersion($recipe, Auth::user());

            return redirect()->route('kitchen.recipes.edit', $copy->id)
                ->with('success', __('Version :version created. The approved version :old stays unchanged.', [
                    'version' => $copy->version,
                    'old' => $recipe->version,
                ]));
        }

        if ($recipe->status === KitchenRecipe::STATUS_ARCHIVED) {
            return redirect()->back()->with('error', __('An archived recipe cannot be edited.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('kitchen.recipes.form', array_merge($this->formData($creatorId), [
            'recipe' => $recipe,
            'code' => $recipe->code,
            'lines' => $recipe->lines()->with('alternatives')->get(),
        ]));
    }

    public function update(Request $request, KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        if ($recipe->isApproved() || $recipe->status === KitchenRecipe::STATUS_ARCHIVED) {
            return redirect()->back()->with('error', __('An approved or archived recipe cannot be edited. Create a new version instead.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateHeader($request, $creatorId, $recipe->id, (int) $recipe->version);

        DB::transaction(function () use ($request, $recipe, $data, $creatorId) {
            // Editing a recipe that is pending approval sends it back to draft.
            $data['status'] = KitchenRecipe::STATUS_DRAFT;
            $data['submitted_by'] = null;

            $recipe->fill($data);

            if ($request->hasFile('photo')) {
                $recipe->photo = $request->file('photo')->store('kitchen-recipes', 'public');
            }

            $recipe->save();
            $this->syncLines($recipe, $request, $creatorId);
            $this->hideIntermediateFromPos($recipe);
        });

        return redirect()->route('kitchen.recipes.show', $recipe->id)
            ->with('success', __('Recipe successfully updated.'));
    }

    public function destroy(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        if (! $recipe->isDraft()) {
            return redirect()->back()->with('error', __('Only a draft recipe can be deleted.'));
        }

        DB::transaction(function () use ($recipe) {
            $lineIds = $recipe->lines()->pluck('id')->all();
            ProductRecipeAlternative::whereIn('product_recipe_id', $lineIds)->delete();
            ProductRecipe::where('kitchen_recipe_id', $recipe->id)->delete();
            $recipe->delete();
        });

        return redirect()->route('kitchen.recipes.index')
            ->with('success', __('Recipe successfully deleted.'));
    }

    public function submit(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        try {
            $this->approval->submit($recipe, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.recipes.show', $recipe->id)
            ->with('success', __('Recipe submitted for approval.'));
    }

    public function approve(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('approve kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        try {
            $this->approval->approve($recipe, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.recipes.show', $recipe->id)
            ->with('success', __('Recipe approved.'));
    }

    public function reject(Request $request, KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('approve kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        try {
            $this->approval->reject($recipe, Auth::user(), $request->input('approval_note'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.recipes.show', $recipe->id)
            ->with('success', __('Recipe returned for correction.'));
    }

    public function archive(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('approve kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        try {
            $this->approval->archive($recipe, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.recipes.show', $recipe->id)
            ->with('success', __('Recipe archived.'));
    }

    public function newVersion(KitchenRecipe $recipe)
    {
        if (! Auth::user()->can('create kitchen recipes')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($recipe);

        try {
            $copy = $this->approval->createNewVersion($recipe, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.recipes.edit', $copy->id)
            ->with('success', __('Version :version created.', ['version' => $copy->version]));
    }

    protected function formData(int $creatorId): array
    {
        return [
            'dishes' => ProductService::where('created_by', $creatorId)
                ->whereIn('type', ['product', 'ingredient'])
                ->orderBy('name')->get(['id', 'name', 'type', 'sale_price', 'purchase_price']),
            'ingredients' => ProductService::where('created_by', $creatorId)
                ->where('type', 'ingredient')
                ->orderBy('name')->get(['id', 'name', 'unit_id', 'purchase_price']),
            'units' => ProductServiceUnit::where('created_by', $creatorId)
                ->orderBy('name')->get(['id', 'name']),
        ];
    }

    protected function validateHeader(Request $request, int $creatorId, ?int $ignoreId = null, int $version = 1): array
    {
        return $request->validate([
            // Versions of one recipe share the code, so uniqueness is
            // scoped per company AND version (migration 230000).
            'code' => ['required', 'string', 'max:50', Rule::unique('kitchen_recipes', 'code')->where('created_by', $creatorId)->where('version', $version)->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'product_id' => ['required', 'integer', Rule::exists('product_services', 'id')->where('created_by', $creatorId)->whereIn('type', ['product', 'ingredient', 'kit'])],
            'category_id' => ['nullable', 'integer'],
            'expected_yield_quantity' => ['nullable', 'numeric', 'min:0'],
            'expected_yield_unit_id' => ['nullable', 'integer'],
            'standard_portion_quantity' => ['nullable', 'numeric', 'min:0'],
            'standard_portion_unit_id' => ['nullable', 'integer'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:0'],
            'equipment' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'storage_conditions' => ['nullable', 'string'],
            'allergens' => ['nullable', 'string'],
            'loss_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'cost_centre_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    /**
     * Sync recipe lines for this recipe version. Mirrors the rules of
     * ProductServiceController::syncProductRecipes(): the ingredient must
     * be an ingredient product of this company, quantity must be > 0,
     * and the ingredient cannot be the dish itself.
     */
    protected function syncLines(KitchenRecipe $recipe, Request $request, int $creatorId): void
    {
        $rows = $request->input('lines', []);
        if (! is_array($rows)) {
            $rows = [];
        }

        $oldLineIds = $recipe->lines()->pluck('id')->all();
        ProductRecipeAlternative::whereIn('product_recipe_id', $oldLineIds)->delete();
        ProductRecipe::where('kitchen_recipe_id', $recipe->id)
            ->where('version', $recipe->version)
            ->delete();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ingredientId = (int) ($row['ingredient_product_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            if ($ingredientId <= 0 || $qty <= 0 || $ingredientId === (int) $recipe->product_id) {
                continue;
            }

            $ingredient = ProductService::where('id', $ingredientId)
                ->where('created_by', $creatorId)
                ->where('type', 'ingredient')
                ->first();
            if (! $ingredient) {
                continue;
            }

            $line = ProductRecipe::create([
                'product_id' => (int) $recipe->product_id,
                'kitchen_recipe_id' => (int) $recipe->id,
                'version' => (int) $recipe->version,
                'ingredient_product_id' => $ingredientId,
                'quantity' => $qty,
                'loss_percent' => max(0, min(99.99, (float) ($row['loss_percent'] ?? 0))),
                'unit_id' => ! empty($row['unit_id']) ? (int) $row['unit_id'] : ($ingredient->unit_id ? (int) $ingredient->unit_id : null),
                'created_by' => $creatorId,
            ]);

            $alternatives = $row['alternatives'] ?? [];
            if (is_array($alternatives)) {
                $order = 0;
                foreach (array_unique(array_map('intval', $alternatives)) as $altId) {
                    if ($altId <= 0 || $altId === $ingredientId || $altId === (int) $recipe->product_id) {
                        continue;
                    }
                    $valid = ProductService::where('id', $altId)
                        ->where('created_by', $creatorId)
                        ->where('type', 'ingredient')
                        ->exists();
                    if (! $valid) {
                        continue;
                    }
                    ProductRecipeAlternative::create([
                        'product_recipe_id' => (int) $line->id,
                        'alternative_product_id' => $altId,
                        'quantity' => null,
                        'sort_order' => $order++,
                        'created_by' => $creatorId,
                    ]);
                }
            }
        }
    }

    /** Decision D7 / spec §1: intermediate preparations are hidden from POS. */
    protected function hideIntermediateFromPos(KitchenRecipe $recipe): void
    {
        $dish = $recipe->product;
        if ($dish && $dish->type === 'ingredient' && (bool) $dish->show_on_pos) {
            $dish->show_on_pos = false;
            $dish->save();
        }
    }

    protected function nextCode(int $creatorId): string
    {
        $count = KitchenRecipe::where('created_by', $creatorId)->count() + 1;

        return sprintf('KR-%s-%04d', date('Y'), $count);
    }

    protected function assertSameCompany(KitchenRecipe $recipe): void
    {
        if ((int) $recipe->created_by !== (int) Auth::user()->creatorId()) {
            abort(403, __('This recipe belongs to another company.'));
        }
    }
}
