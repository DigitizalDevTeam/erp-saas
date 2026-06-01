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
            ->orderBy('period_value', 'desc')
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
            'period_type' => 'required|in:day,week,month,year',
            'period_value' => 'required|string|max:20',
            'branch_id' => 'required|exists:branches,id',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $periodValue = $this->normalizePeriodValue($request->period_type, $request->period_value);
        if ($periodValue === null) {
            return redirect()->back()->with('error', __('Invalid period value.'))->withInput();
        }

        $creatorId = \Auth::user()->creatorId();
        $branch = Branch::where('id', $request->branch_id)->where('created_by', $creatorId)->first();
        if (!$branch) {
            return redirect()->back()->with('error', __('Invalid branch.'));
        }

        $target = BranchSalesTarget::updateOrCreate(
            [
                'branch_id' => $request->branch_id,
                'period_type' => $request->period_type,
                'period_value' => $periodValue,
                'created_by' => $creatorId,
            ],
            [
                'target_amount' => $request->target_amount,
            ]
        );

        $message = $target->wasRecentlyCreated
            ? __('Target created successfully.')
            : __('Target updated successfully.');

        return redirect()->route('branch-sales-targets.index')->with('success', $message);
    }

    /**
     * Normalize period value for storage: day Y-m-d, week Y-m-d (Monday), month Y-m, year Y.
     */
    private function normalizePeriodValue(string $periodType, $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            switch ($periodType) {
                case 'day':
                    return \Carbon\Carbon::parse($value)->format('Y-m-d');
                case 'week':
                    return \Carbon\Carbon::parse($value)->startOfWeek()->format('Y-m-d');
                case 'month':
                    return \Carbon\Carbon::createFromFormat('Y-m', $value)->format('Y-m');
                case 'year':
                    return (string) (int) $value;
                default:
                    return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
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
            'period_type' => 'required|in:day,week,month,year',
            'period_value' => 'required|string|max:20',
            'branch_id' => 'required|exists:branches,id',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $periodValue = $this->normalizePeriodValue($request->period_type, $request->period_value);
        if ($periodValue === null) {
            return redirect()->back()->with('error', __('Invalid period value.'))->withInput();
        }

        $creatorId = \Auth::user()->creatorId();
        $exists = BranchSalesTarget::where('branch_id', $request->branch_id)
            ->where('period_type', $request->period_type)
            ->where('period_value', $periodValue)
            ->where('created_by', $creatorId)
            ->where('id', '!=', $branch_sales_target->id)
            ->exists();
        if ($exists) {
            return redirect()->back()->with('error', __('Target already exists for this branch and period.'));
        }

        $branch_sales_target->update([
            'branch_id' => $request->branch_id,
            'period_type' => $request->period_type,
            'period_value' => $periodValue,
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
