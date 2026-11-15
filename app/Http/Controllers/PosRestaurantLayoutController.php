<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosArea;
use App\Models\PosTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosRestaurantLayoutController extends Controller
{
    /**
     * Interactive restaurant floor designer — one floor per POS area.
     *
     * The AREA IS THE FLOOR: the design lives on the area itself, in its
     * `layout` JSON column ({version, elements:[...]}). Table elements
     * reference real PosTable rows by id and show their live name, seats
     * and status; decoration elements (walls, bar, plants, ...) are plain
     * shapes. Nothing is kept in the browser — localStorage is not a save
     * path; the designer loads/saves through the area endpoints below.
     */
    public function show(Request $request)
    {
        // No canManagePos gate here: as in v1 the sidebar menu link is
        // the gate (hidden without the permission); the API endpoints
        // below are the ones that enforce it.
        $areas = $this->areasForUser();
        $branches = Branch::where('created_by', Auth::user()->creatorId())
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->orderBy('name')
            ->get(['id', 'name']);
        $initialAreaId = (int) $request->query('area', 0);
        if ($initialAreaId && ! $areas->contains('id', $initialAreaId)) {
            $initialAreaId = 0;
        }
        if (! $initialAreaId && $areas->isNotEmpty()) {
            $initialAreaId = (int) $areas->first()->id;
        }

        return view('pos.restaurant-layout', [
            'areas' => $areas,
            // Plain arrays for the JS (Blade's @json cannot compile the
            // nested map/ternary inline — it truncates the expression).
            'areasJson' => $areas->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'branch_id' => (int) $a->branch_id, 'branch_name' => $a->branch ? $a->branch->name : null])->values()->all(),
            'branches' => $branches->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name])->values()->all(),
            'initialAreaId' => $initialAreaId,
        ]);
    }

    /**
     * GET the saved floor for one area, plus that area's real tables.
     * Table elements whose PosTable row no longer exists are dropped here,
     * so a deleted table can never break the designer (or the POS floor).
     */
    public function areaData(PosArea $area)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $area = $this->ownedArea($area->id);
        if (! $area) {
            return response()->json(['error' => __('Area not found.')], 404);
        }

        return response()->json([
            'area' => [
                'id' => (int) $area->id,
                'name' => $area->name,
                'branch_id' => (int) $area->branch_id,
                'branch_name' => $area->branch ? $area->branch->name : null,
            ],
            'layout' => $this->cleanLayout($area->layout, $area, false),
            'tables' => $area->tables()->orderBy('name')->get()->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'capacity' => (int) $t->capacity,
                'status' => $t->status,
            ])->toArray(),
        ]);
    }

    /**
     * POST a floor design for one area — saved on the area's `layout`
     * column. Every table element must reference a real table of THIS
     * area (and company); anything else is rejected with a 422.
     */
    public function saveArea(Request $request, PosArea $area)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $area = $this->ownedArea($area->id);
        if (! $area) {
            return response()->json(['error' => __('Area not found.')], 404);
        }

        $data = $request->validate([
            'layout' => ['required', 'array'],
            'layout.version' => ['nullable', 'integer'],
            'layout.elements' => ['required', 'array'],
            'layout.elements.*.type' => ['required', 'string', 'max:50'],
            'layout.elements.*.table_id' => ['nullable', 'integer'],
            'layout.elements.*.x' => ['required', 'numeric'],
            'layout.elements.*.y' => ['required', 'numeric'],
            'layout.elements.*.w' => ['required', 'numeric', 'min:10', 'max:5000'],
            'layout.elements.*.h' => ['required', 'numeric', 'min:10', 'max:5000'],
            'layout.elements.*.rot' => ['nullable', 'numeric'],
            'layout.elements.*.label' => ['nullable', 'string', 'max:100'],
        ]);

        $layout = $this->cleanLayout($data['layout'], $area, true);
        if ($layout === null) {
            return response()->json(['error' => __('Invalid layout.')], 422);
        }

        $area->layout = $layout;
        $area->save();

        return response()->json(['saved' => true]);
    }

    /**
     * POST a new real POS table from the designer. The table is created in
     * the area (branch taken from the area, name auto-numbered unless a
     * name is given, status available) and the designer places it on the
     * floor.
     */
    public function storeTable(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'area_id' => ['required', 'integer', 'exists:pos_areas,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:99'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $area = $this->ownedArea((int) $request->area_id);
        if (! $area) {
            return response()->json(['error' => __('Area not found.')], 404);
        }

        $table = new PosTable();
        $table->area_id = $area->id;
        $table->branch_id = $area->branch_id;
        $table->name = $request->filled('name') ? trim((string) $request->name) : $this->nextTableName($area);
        $table->capacity = (int) $request->capacity;
        $table->status = PosTable::STATUS_AVAILABLE;
        $table->created_by = Auth::user()->creatorId();
        $table->save();

        return response()->json([
            'id' => (int) $table->id,
            'name' => $table->name,
            'capacity' => (int) $table->capacity,
            'status' => $table->status,
        ], 201);
    }

    /**
     * PATCH a real POS table's name / seats from the designer properties
     * panel. Same canManagePos gate as the layout API, plus the table
     * must belong to the user's company. The layout JSON is untouched —
     * the table element on the floor keeps referencing the same table id.
     */
    public function updateTable(Request $request, PosTable $table)
    {
        if (! Auth::user()->canManagePos() || (int) $table->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $table->name = trim($data['name']);
        $table->capacity = (int) $data['capacity'];
        $table->save();

        return response()->json([
            'id' => (int) $table->id,
            'name' => $table->name,
            'capacity' => (int) $table->capacity,
            'status' => $table->status,
        ]);
    }

    /**
     * POST a new real POS area (a new floor) from the designer. The branch
     * must belong to the user's company and be a restaurant branch.
     */
    public function storeArea(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branch = Branch::where('id', (int) $data['branch_id'])
            ->where('created_by', Auth::user()->creatorId())
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->first();
        if (! $branch) {
            return response()->json(['error' => __('Branch not found.')], 404);
        }

        $area = new PosArea();
        $area->branch_id = $branch->id;
        $area->name = trim($data['name']);
        $area->sort_order = (int) (PosArea::where('created_by', Auth::user()->creatorId())->max('sort_order') ?? -1) + 1;
        $area->created_by = Auth::user()->creatorId();
        $area->save();

        return response()->json([
            'id' => (int) $area->id,
            'name' => $area->name,
            'branch_id' => (int) $area->branch_id,
            'branch_name' => $branch->name,
        ], 201);
    }

    /** Areas of the user's company, with their branch, for the picker. */
    protected function areasForUser()
    {
        return PosArea::where('created_by', Auth::user()->creatorId())
            ->with('branch')
            ->orderBy('branch_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** The area, only when it belongs to the user's company. */
    protected function ownedArea(int $areaId): ?PosArea
    {
        return PosArea::where('id', $areaId)
            ->where('created_by', Auth::user()->creatorId())
            ->with('branch')
            ->first();
    }

    /**
     * Validate + normalise a layout payload.
     *
     * Strict (save): unknown element types, table elements without a real
     * table of this area, or tables of another area/company reject the
     * whole layout (null). Lenient (load): the same problems only drop the
     * offending element, so a deleted table never breaks the floor.
     */
    protected function cleanLayout($layout, ?PosArea $area = null, bool $strict = false): ?array
    {
        if (! is_array($layout) || ! isset($layout['elements']) || ! is_array($layout['elements'])) {
            return null;
        }

        $knownTypes = [
            'table-round-2', 'table-round-4', 'table-round-6',
            'table-square-2', 'table-square-4',
            'table-rect-4', 'table-rect-6', 'table-banquet',
            'wall-h', 'wall-v', 'door', 'window', 'bar', 'reception', 'plant', 'waiter',
        ];

        $areaTableIds = null;
        if ($area) {
            $areaTableIds = PosTable::where('area_id', $area->id)
                ->where('created_by', Auth::user()->creatorId())
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $elements = [];
        foreach ($layout['elements'] as $el) {
            if (! is_array($el) || ! in_array($el['type'] ?? null, $knownTypes, true)) {
                if ($strict) {
                    return null;
                }
                continue;
            }
            $isTable = str_starts_with($el['type'], 'table-');
            $tableId = isset($el['table_id']) ? (int) $el['table_id'] : null;
            if ($isTable) {
                if (! $tableId) {
                    if ($strict) {
                        return null;
                    }
                    continue;
                }
                if ($areaTableIds !== null && ! in_array($tableId, $areaTableIds, true)) {
                    if ($strict) {
                        return null;
                    }
                    continue;
                }
            }
            $elements[] = [
                'type' => $el['type'],
                'table_id' => $isTable ? $tableId : null,
                'x' => (float) $el['x'],
                'y' => (float) $el['y'],
                'w' => (float) $el['w'],
                'h' => (float) $el['h'],
                'rot' => isset($el['rot']) ? (float) $el['rot'] : 0,
                'label' => isset($el['label']) ? (string) $el['label'] : '',
            ];
        }

        return ['version' => 1, 'elements' => $elements];
    }

    /** Next free "Table N" name inside the area. */
    protected function nextTableName(PosArea $area): string
    {
        $used = PosTable::where('area_id', $area->id)
            ->where('created_by', Auth::user()->creatorId())
            ->pluck('name')->all();
        $n = count($used) + 1;
        while (in_array('Table ' . $n, $used, true)) {
            $n++;
        }

        return 'Table ' . $n;
    }
}
