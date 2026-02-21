<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchSalesTarget;
use Illuminate\Http\Request;

class BranchSalesTargetController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!\Auth::user()->canManagePos()) {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
            return $next($request);
        });
    }

    public function index()
    {
        $creatorId = \Auth::user()->creatorId();
        $targets = BranchSalesTarget::where('created_by', $creatorId)
            ->with('branch')
            ->orderBy('month', 'desc')
            ->orderBy('branch_id')
            ->paginate(20);

        $actualMap = BranchSalesTarget::actualSalesMapForTargets($targets->getCollection(), $creatorId);

        return view('branch_sales_targets.index', compact('targets', 'actualMap'));
    }

    public function create()
    {
        $branches = Branch::where('created_by', \Auth::user()->creatorId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('branch_sales_targets.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
            'branch_id' => 'required|exists:branches,id',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $creatorId = \Auth::user()->creatorId();
        $branch = Branch::where('id', $request->branch_id)->where('created_by', $creatorId)->first();
        if (!$branch) {
            return redirect()->back()->with('error', __('Invalid branch.'));
        }

        $exists = BranchSalesTarget::where('branch_id', $request->branch_id)
            ->where('month', $request->month)
            ->where('created_by', $creatorId)
            ->exists();
        if ($exists) {
            return redirect()->back()->with('error', __('Target already exists for this branch and month.'));
        }

        BranchSalesTarget::create([
            'branch_id' => $request->branch_id,
            'month' => $request->month,
            'target_amount' => $request->target_amount,
            'created_by' => $creatorId,
        ]);

        return redirect()->route('branch-sales-targets.index')->with('success', __('Target created successfully.'));
    }

    public function edit(BranchSalesTarget $branch_sales_target)
    {
        if ($branch_sales_target->created_by != \Auth::user()->creatorId()) {
            return redirect()->route('branch-sales-targets.index')->with('error', __('Permission denied.'));
        }

        $branches = Branch::where('created_by', \Auth::user()->creatorId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('branch_sales_targets.edit', compact('branch_sales_target', 'branches'));
    }

    public function update(Request $request, BranchSalesTarget $branch_sales_target)
    {
        if ($branch_sales_target->created_by != \Auth::user()->creatorId()) {
            return redirect()->route('branch-sales-targets.index')->with('error', __('Permission denied.'));
        }

        $request->validate([
            'month' => 'required|date_format:Y-m',
            'branch_id' => 'required|exists:branches,id',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $creatorId = \Auth::user()->creatorId();
        $exists = BranchSalesTarget::where('branch_id', $request->branch_id)
            ->where('month', $request->month)
            ->where('created_by', $creatorId)
            ->where('id', '!=', $branch_sales_target->id)
            ->exists();
        if ($exists) {
            return redirect()->back()->with('error', __('Target already exists for this branch and month.'));
        }

        $branch_sales_target->update([
            'branch_id' => $request->branch_id,
            'month' => $request->month,
            'target_amount' => $request->target_amount,
        ]);

        return redirect()->route('branch-sales-targets.index')->with('success', __('Target updated successfully.'));
    }

    public function destroy(BranchSalesTarget $branch_sales_target)
    {
        if ($branch_sales_target->created_by != \Auth::user()->creatorId()) {
            return redirect()->route('branch-sales-targets.index')->with('error', __('Permission denied.'));
        }
        $branch_sales_target->delete();
        return redirect()->route('branch-sales-targets.index')->with('success', __('Target deleted successfully.'));
    }
}
