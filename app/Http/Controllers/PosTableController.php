<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosArea;
use App\Models\PosTable;
use Illuminate\Http\Request;

class PosTableController extends Controller
{
    public function index()
    {
        if (\Auth::user()->can('manage pos')) {
            $tables = PosTable::where('created_by', \Auth::user()->creatorId())
                ->with(['area', 'branch'])
                ->orderBy('branch_id')
                ->orderBy('area_id')
                ->orderBy('name')
                ->get();
            return view('pos_table.index', compact('tables'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function create()
    {
        if (\Auth::user()->can('manage pos')) {
            $branches = Branch::where('created_by', \Auth::user()->creatorId())
                ->where('is_active', true)
                ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
                ->get()
                ->pluck('name', 'id');
            $areas = PosArea::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            return view('pos_table.create', compact('branches', 'areas'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('manage pos')) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'area_id' => 'required|exists:pos_areas,id',
                'branch_id' => 'required|exists:branches,id',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $table = new PosTable();
            $table->area_id = $request->area_id;
            $table->branch_id = $request->branch_id;
            $table->name = $request->name;
            $table->capacity = (int) ($request->capacity ?? 4);
            $table->status = $request->status ?? PosTable::STATUS_AVAILABLE;
            $table->created_by = \Auth::user()->creatorId();
            $table->save();
            return redirect()->route('pos-table.index')->with('success', __('Table created successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function edit(PosTable $pos_table)
    {
        if (\Auth::user()->can('manage pos') && $pos_table->created_by == \Auth::user()->creatorId()) {
            $branches = Branch::where('created_by', \Auth::user()->creatorId())
                ->where('is_active', true)
                ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
                ->get()
                ->pluck('name', 'id');
            $areas = PosArea::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            return view('pos_table.edit', compact('pos_table', 'branches', 'areas'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, PosTable $pos_table)
    {
        if (\Auth::user()->can('manage pos') && $pos_table->created_by == \Auth::user()->creatorId()) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'area_id' => 'required|exists:pos_areas,id',
                'branch_id' => 'required|exists:branches,id',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $pos_table->area_id = $request->area_id;
            $pos_table->branch_id = $request->branch_id;
            $pos_table->name = $request->name;
            $pos_table->capacity = (int) ($request->capacity ?? 4);
            $pos_table->status = $request->status ?? $pos_table->status;
            $pos_table->save();
            return redirect()->route('pos-table.index')->with('success', __('Table updated successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function destroy(PosTable $pos_table)
    {
        if (\Auth::user()->can('manage pos') && $pos_table->created_by == \Auth::user()->creatorId()) {
            if ($pos_table->posSales()->count() > 0) {
                return redirect()->back()->with('error', __('Table has sales history and cannot be deleted.'));
            }
            $pos_table->delete();
            return redirect()->route('pos-table.index')->with('success', __('Table deleted successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function getTablesByBranch(Request $request)
    {
        $branchId = $request->branch_id;
        $tables = PosTable::where('created_by', \Auth::user()->creatorId())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->with('area')
            ->orderBy('name')
            ->get()
            ->map(fn($t) => ['id' => $t->id, 'name' => $t->name . ($t->area ? ' (' . $t->area->name . ')' : ''), 'status' => $t->status])
            ->toArray();
        return response()->json($tables);
    }

    public function getTablesByArea(Request $request)
    {
        $areaId = $request->area_id;
        $tables = PosTable::where('created_by', \Auth::user()->creatorId())
            ->when($areaId, fn($q) => $q->where('area_id', $areaId))
            ->orderBy('name')
            ->get()
            ->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status])
            ->toArray();
        return response()->json($tables);
    }
}
