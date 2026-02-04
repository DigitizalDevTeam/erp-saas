<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosArea;
use Illuminate\Http\Request;

class PosAreaController extends Controller
{
    public function index()
    {
        if (\Auth::user()->can('manage pos')) {
            $areas = PosArea::where('created_by', \Auth::user()->creatorId())
                ->with('branch')
                ->orderBy('branch_id')
                ->orderBy('sort_order')
                ->get();
            return view('pos_area.index', compact('areas'));
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
            return view('pos_area.create', compact('branches'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('manage pos')) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'branch_id' => 'required|exists:branches,id',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $area = new PosArea();
            $area->branch_id = $request->branch_id;
            $area->name = $request->name;
            $area->sort_order = (int) ($request->sort_order ?? 0);
            $area->created_by = \Auth::user()->creatorId();
            $area->save();
            return redirect()->route('pos-area.index')->with('success', __('Area created successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function edit(PosArea $pos_area)
    {
        if (\Auth::user()->can('manage pos') && $pos_area->created_by == \Auth::user()->creatorId()) {
            $branches = Branch::where('created_by', \Auth::user()->creatorId())
                ->where('is_active', true)
                ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
                ->get()
                ->pluck('name', 'id');
            return view('pos_area.edit', compact('pos_area', 'branches'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, PosArea $pos_area)
    {
        if (\Auth::user()->can('manage pos') && $pos_area->created_by == \Auth::user()->creatorId()) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'branch_id' => 'required|exists:branches,id',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $pos_area->branch_id = $request->branch_id;
            $pos_area->name = $request->name;
            $pos_area->sort_order = (int) ($request->sort_order ?? 0);
            $pos_area->save();
            return redirect()->route('pos-area.index')->with('success', __('Area updated successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function destroy(PosArea $pos_area)
    {
        if (\Auth::user()->can('manage pos') && $pos_area->created_by == \Auth::user()->creatorId()) {
            if ($pos_area->tables()->count() > 0) {
                return redirect()->back()->with('error', __('Remove tables from this area first.'));
            }
            $pos_area->delete();
            return redirect()->route('pos-area.index')->with('success', __('Area deleted successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function getByBranch(Request $request)
    {
        $branchId = $request->branch_id;
        $areas = PosArea::where('created_by', \Auth::user()->creatorId())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderBy('sort_order')
            ->get()
            ->pluck('name', 'id')
            ->toArray();
        return response()->json($areas);
    }
}
