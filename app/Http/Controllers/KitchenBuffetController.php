<?php

namespace App\Http\Controllers;

use App\Models\KitchenBuffetEvent;
use App\Models\KitchenBuffetEventLine;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRecipe;
use App\Models\ProductService;
use App\Models\User;
use App\Models\warehouse;
use App\Services\KitchenBuffetService;
use App\Services\KitchenOccupancyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class KitchenBuffetController extends Controller
{
    public function __construct(
        protected KitchenBuffetService $buffets,
        protected KitchenOccupancyService $occupancy,
    ) {
    }

    public function index()
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $events = KitchenBuffetEvent::where('created_by', Auth::user()->creatorId())
            ->with(['lines.product', 'lines.recipe'])
            ->orderBy('event_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return view('kitchen.buffet-events.index', compact('events'));
    }

    public function create(Request $request)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $date = $request->get('event_date', date('Y-m-d'));
        $mealService = $request->get('meal_service');
        $creatorId = Auth::user()->creatorId();

        return view('kitchen.buffet-events.create', [
            'date' => $date,
            'meal_service' => $mealService,
            'meal_services' => KitchenProductionPlan::MEAL_SERVICES,
            'occupancy' => $this->occupancy->snapshot($date, $creatorId),
            'occupancy_covers' => $mealService ? $this->occupancy->coversForMeal($date, $mealService, $creatorId) : null,
            'warehouses' => warehouse::where('created_by', $creatorId)->get(),
            'products' => ProductService::where('created_by', $creatorId)->where('type', 'product')->orderBy('name')->get(),
            'ingredients' => ProductService::where('created_by', $creatorId)->where('type', 'ingredient')->orderBy('name')->get(),
            'recipes' => KitchenRecipe::where('created_by', $creatorId)->where('status', KitchenRecipe::STATUS_APPROVED)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $event = $this->buffets->create($this->eventInput($request), Auth::user());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('kitchen-buffet-events.show', $event->id)
            ->with('success', __('Buffet event :number created as a draft.', ['number' => $event->event_number]));
    }

    public function show(KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $kitchenBuffetEvent->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('This buffet event belongs to another company.'));
        }

        $kitchenBuffetEvent->load(['lines.product', 'lines.recipe', 'lines.losses']);
        $summary = $kitchenBuffetEvent->summary();

        return view('kitchen.buffet-events.show', [
            'event' => $kitchenBuffetEvent,
            'summary' => $summary,
            'reconciles' => $summary['balanced'],
        ]);
    }

    public function edit(KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $kitchenBuffetEvent->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('This buffet event belongs to another company.'));
        }
        if (! $kitchenBuffetEvent->isDraft()) {
            return redirect()->route('kitchen-buffet-events.show', $kitchenBuffetEvent->id)
                ->with('error', __('Only a draft buffet event can be edited.'));
        }

        $creatorId = Auth::user()->creatorId();
        $kitchenBuffetEvent->load('lines');

        return view('kitchen.buffet-events.edit', [
            'event' => $kitchenBuffetEvent,
            'meal_services' => KitchenProductionPlan::MEAL_SERVICES,
            'occupancy' => $this->occupancy->snapshot($kitchenBuffetEvent->event_date->format('Y-m-d'), $creatorId),
            'warehouses' => warehouse::where('created_by', $creatorId)->get(),
            'products' => ProductService::where('created_by', $creatorId)->where('type', 'product')->orderBy('name')->get(),
            'ingredients' => ProductService::where('created_by', $creatorId)->where('type', 'ingredient')->orderBy('name')->get(),
            'recipes' => KitchenRecipe::where('created_by', $creatorId)->where('status', KitchenRecipe::STATUS_APPROVED)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $event = $this->buffets->updateDraft($kitchenBuffetEvent, $this->eventInput($request), Auth::user());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('kitchen-buffet-events.show', $event->id)
            ->with('success', __('Buffet event :number updated.', ['number' => $event->event_number]));
    }

    public function prepare(KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $event = $this->buffets->prepare($kitchenBuffetEvent, Auth::user());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen-buffet-events.show', $event->id)
            ->with('success', __('Buffet event :number prepared — the food is made and the items are issued.', ['number' => $event->event_number]));
    }

    public function close(Request $request, KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $lines = [];
        foreach ((array) $request->input('lines', []) as $lineId => $row) {
            $lines[(int) $lineId] = [
                'used_quantity' => (float) ($row['used_quantity'] ?? 0),
                'leftover_quantity' => (float) ($row['leftover_quantity'] ?? 0),
                'waste_quantity' => (float) ($row['waste_quantity'] ?? 0),
            ];
        }

        try {
            $event = $this->buffets->close(
                $kitchenBuffetEvent,
                Auth::user(),
                $lines,
                $request->input('food_safety_note')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('kitchen-buffet-events.show', $event->id)
            ->with('success', __('Buffet event :number closed — used, leftover and waste reconcile.', ['number' => $event->event_number]));
    }

    public function cancel(KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $event = $this->buffets->cancel($kitchenBuffetEvent, Auth::user());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen-buffet-events.index')
            ->with('success', __('Buffet event :number cancelled.', ['number' => $event->event_number]));
    }

    public function destroy(KitchenBuffetEvent $kitchenBuffetEvent)
    {
        if (! Auth::user()->can('manage kitchen buffets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $kitchenBuffetEvent->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('This buffet event belongs to another company.'));
        }
        if (! $kitchenBuffetEvent->isDraft()) {
            return redirect()->back()->with('error', __('Only a draft buffet event can be deleted.'));
        }

        $kitchenBuffetEvent->delete();

        return redirect()->route('kitchen-buffet-events.index')
            ->with('success', __('Buffet event deleted.'));
    }

    /**
     * Normalize the buffet form into the service input. The lines
     * table posts one row per line; empty rows are skipped.
     */
    protected function eventInput(Request $request): array
    {
        $lines = [];
        foreach ((array) $request->input('lines', []) as $row) {
            if (! is_array($row) || empty($row['product_id'])) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $row['product_id'],
                'kitchen_recipe_id' => ! empty($row['kitchen_recipe_id']) ? (int) $row['kitchen_recipe_id'] : null,
                'unit_id' => ! empty($row['unit_id']) ? (int) $row['unit_id'] : null,
                'per_cover_quantity' => isset($row['per_cover_quantity']) && $row['per_cover_quantity'] !== '' ? (float) $row['per_cover_quantity'] : null,
                'planned_quantity' => isset($row['planned_quantity']) && $row['planned_quantity'] !== '' ? (float) $row['planned_quantity'] : null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        return [
            'event_date' => $request->input('event_date'),
            'meal_service' => $request->input('meal_service'),
            'name' => $request->input('name'),
            'event_link' => $request->input('event_link'),
            'branch_id' => $request->input('branch_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'covers_source' => $request->input('covers_source', KitchenBuffetEvent::COVERS_MANUAL),
            'expected_covers' => $request->input('expected_covers'),
            'price_per_person' => $request->input('price_per_person'),
            'notes' => $request->input('notes'),
            'lines' => $lines,
        ];
    }
}
