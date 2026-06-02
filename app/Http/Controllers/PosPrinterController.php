<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosPrinter;
use Illuminate\Http\Request;

class PosPrinterController extends Controller
{
    public function index(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $branches = Branch::where('created_by', $creatorId)
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->get()
            ->pluck('name', 'id');
        $branchId = $request->input('branch_id', array_key_first($branches->toArray()));
        $printers = collect();
        if ($branchId) {
            $printers = PosPrinter::where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->orderBy('type')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->with('branch')
                ->get();
        }
        return view('pos_printer.index', compact('branches', 'printers', 'branchId'));
    }

    public function create()
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $branches = Branch::where('created_by', \Auth::user()->creatorId())
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->get()
            ->pluck('name', 'id');
        return view('pos_printer.create', compact('branches'));
    }

    public function store(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'branch_id' => 'required|exists:branches,id',
            'type' => 'required|in:kitchen,bar,restaurant',
            'connection' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        $p = new PosPrinter();
        $p->branch_id = $request->branch_id;
        $p->name = $request->name;
        $p->type = $request->type;
        $p->connection = $request->connection ?: PosPrinter::CONNECTION_BROWSER;
        $p->sort_order = (int) ($request->sort_order ?? 0);
        $p->created_by = \Auth::user()->creatorId();
        $p->save();
        return redirect()->route('pos-printer.index', ['branch_id' => $p->branch_id])->with('success', __('Printer added successfully.'));
    }

    public function edit(PosPrinter $pos_printer)
    {
        if (!\Auth::user()->canManagePos() || $pos_printer->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $branches = Branch::where('created_by', \Auth::user()->creatorId())
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->get()
            ->pluck('name', 'id');
        return view('pos_printer.edit', compact('pos_printer', 'branches'));
    }

    public function update(Request $request, PosPrinter $pos_printer)
    {
        if (!\Auth::user()->canManagePos() || $pos_printer->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'branch_id' => 'required|exists:branches,id',
            'type' => 'required|in:kitchen,bar,restaurant',
            'connection' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        $pos_printer->branch_id = $request->branch_id;
        $pos_printer->name = $request->name;
        $pos_printer->type = $request->type;
        $pos_printer->connection = $request->connection ?: PosPrinter::CONNECTION_BROWSER;
        $pos_printer->sort_order = (int) ($request->sort_order ?? 0);
        $pos_printer->save();
        return redirect()->route('pos-printer.index', ['branch_id' => $pos_printer->branch_id])->with('success', __('Printer updated successfully.'));
    }

    public function destroy(PosPrinter $pos_printer)
    {
        if (!\Auth::user()->canManagePos() || $pos_printer->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $branchId = $pos_printer->branch_id;
        $pos_printer->delete();
        return redirect()->route('pos-printer.index', ['branch_id' => $branchId])->with('success', __('Printer deleted successfully.'));
    }
}
