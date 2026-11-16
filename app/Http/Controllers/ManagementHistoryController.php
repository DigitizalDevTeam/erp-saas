<?php

namespace App\Http\Controllers;

use App\Models\ManagementReportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Management History — the UI for management_report_history, the
 * imported Excel figures. One row per (year, month, line) wins for
 * its line-month over the system value in the P&L (see
 * MonthlyManagementData): editing a row here changes the P&L for
 * that line-month. Company-scoped (created_by), same permission
 * pattern as the other management reports ('manage report' fallback).
 *
 * Line keys: revenue_ex, revenue_iva, cogs, variables, fixed, other,
 * depreciation, amortization, personnel:{department name},
 * personnel:Impostos e Outros Custos, channel:{name}:ex|iva.
 */
class ManagementHistoryController extends Controller
{
    private function authorizeManagement(): void
    {
        $user = Auth::user();
        if ($user && ($user->can('menu.dashboard.accounting.reports.management.management_history') || $user->can('manage report'))) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    public function index(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $year = (int) $request->input('year', (int) date('Y'));
        $month = $request->input('month');

        $rows = ManagementReportHistory::where('created_by', $creatorId)
            ->when($year, fn ($q) => $q->where('year', $year))
            ->when($month, fn ($q) => $q->where('month', $month))
            ->orderByDesc('year')
            ->orderBy('month')
            ->orderBy('line')
            ->paginate(25)
            ->withQueryString();

        return view('management-reports.history', [
            'rows' => $rows,
            'year' => $year,
            'month' => $month,
            'years' => range((int) date('Y'), (int) date('Y') - 5),
            'lineSuggestions' => self::lineSuggestions(),
        ]);
    }

    public function create()
    {
        $this->authorizeManagement();

        return view('management-reports.history-create', [
            'lineSuggestions' => self::lineSuggestions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $data = $this->validateRow($request, $creatorId);
        $data['created_by'] = $creatorId;
        ManagementReportHistory::create($data);

        return redirect()->route('management-history.index')
            ->with('success', __('History row added successfully.'));
    }

    public function edit(ManagementReportHistory $managementHistory)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementHistory, $creatorId);

        return view('management-reports.history-edit', ['row' => $managementHistory]);
    }

    public function update(Request $request, ManagementReportHistory $managementHistory)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementHistory, $creatorId);

        $data = $request->validate([
            'amount' => ['required', 'numeric'],
        ]);
        $managementHistory->update($data);

        return redirect()->route('management-history.index')
            ->with('success', __('History row updated successfully.'));
    }

    public function destroy(ManagementReportHistory $managementHistory)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementHistory, $creatorId);

        $managementHistory->delete();

        return redirect()->route('management-history.index')
            ->with('success', __('History row deleted successfully.'));
    }

    private function validateRow(Request $request, int $creatorId): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'line' => [
                'required', 'string', 'max:160',
                function ($attribute, $value, $fail) {
                    if (! ManagementReportHistory::isValidLine((string) $value)) {
                        $fail(__('The line key is not valid.'));
                    }
                },
                Rule::unique('management_report_history', 'line')->where(function ($query) use ($request, $creatorId) {
                    return $query->where('created_by', $creatorId)
                        ->where('year', $request->input('year'))
                        ->where('month', $request->input('month'));
                }),
            ],
            'amount' => ['required', 'numeric'],
        ]);

        return $data;
    }

    private function ensureOwned(ManagementReportHistory $row, int $creatorId): void
    {
        if ((int) $row->created_by !== $creatorId) {
            abort(404);
        }
    }

    /** Line keys offered in the add form (datalist suggestions). */
    public static function lineSuggestions(): array
    {
        $keys = ManagementReportHistory::SIMPLE_LINES;
        foreach (ManagementReportHistory::SHEET_CHANNELS as $channel) {
            $keys[] = "channel:{$channel}:ex";
            $keys[] = "channel:{$channel}:iva";
        }
        foreach (ManagementReportHistory::SHEET_PERSONNEL as $name) {
            $keys[] = "personnel:{$name}";
        }

        return $keys;
    }
}
