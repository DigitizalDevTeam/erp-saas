<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Models\PaySlip;
use App\Services\PayrollDimensionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PayrollDimensionController extends Controller
{
    public function __construct(
        protected PayrollDimensionService $dimensions = new PayrollDimensionService(),
    ) {}

    /**
     * Store (replace) a payslip's cost-centre split. Percentages must
     * sum to 100% (validated by the Phase 1 allocation engine); the
     * split is re-applied to the posted payroll journal when there is
     * one, so the payroll cost lands in the centres' actuals.
     */
    public function store(Request $request, PaySlip $payslip)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $payslip->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $rows = [];
        $centres = (array) $request->input('cost_centre_id', []);
        $percentages = (array) $request->input('percentage', []);
        foreach ($centres as $index => $centreId) {
            if ($centreId === '' || $centreId === null) {
                continue;
            }
            $rows[] = [
                'cost_centre_id' => (int) $centreId,
                'percentage' => (string) ($percentages[$index] ?? 0),
            ];
        }

        if ($rows === []) {
            return redirect()->back()->with('error', __('Add at least one cost centre split line.'));
        }

        $projectId = $request->filled('project_id') ? (int) $request->input('project_id') : null;

        try {
            $this->dimensions->setSplit($payslip, $rows, (int) Auth::id(), $projectId);
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->back()->with('success', __('Cost centre split saved.'));
    }
}
