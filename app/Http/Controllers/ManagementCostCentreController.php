<?php

namespace App\Http\Controllers;

use App\Models\ManagementCostCentre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Cost centres — the company's own cost-centre list (the file's
 * "101 | Restaurante" / "102 | Group"). 101 / 102 are NOT fixed: each
 * company defines its own centres (hotel example: hotel / restaurant /
 * events / office) or none at all. Deleting a centre leaves its
 * documents with no centre (they move to "Sem centro de custo").
 */
class ManagementCostCentreController extends Controller
{
    private function authorizeManagement(): void
    {
        $user = Auth::user();
        if ($user && ($user->can('menu.dashboard.accounting.reports.management.management_rules') || $user->can('manage report'))) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $data = $this->validateCentre($request, $creatorId);
        $data['created_by'] = $creatorId;
        ManagementCostCentre::create($data);

        return redirect()->route('management-rules.index')
            ->with('success', __('Cost centre created successfully.'));
    }

    public function edit(ManagementCostCentre $managementCostCentre)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        if ((int) $managementCostCentre->created_by !== $creatorId) {
            abort(404);
        }

        return view('management-cost-centres.edit', ['centre' => $managementCostCentre]);
    }

    public function update(Request $request, ManagementCostCentre $managementCostCentre)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        if ((int) $managementCostCentre->created_by !== $creatorId) {
            abort(404);
        }

        $managementCostCentre->update($this->validateCentre($request, $creatorId, $managementCostCentre->id));

        return redirect()->route('management-rules.index')
            ->with('success', __('Cost centre updated successfully.'));
    }

    public function destroy(ManagementCostCentre $managementCostCentre)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        if ((int) $managementCostCentre->created_by !== $creatorId) {
            abort(404);
        }

        $managementCostCentre->delete();

        return redirect()->route('management-rules.index')
            ->with('success', __('Cost centre deleted successfully.'));
    }

    private function validateCentre(Request $request, int $creatorId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('management_cost_centres', 'code')->where('created_by', $creatorId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
        ]);
    }
}
