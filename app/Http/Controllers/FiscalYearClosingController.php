<?php

namespace App\Http\Controllers;

use App\Services\Accounting\FiscalYearClosingService;
use App\Services\Accounting\VatSettlementService;
use Illuminate\Http\Request;

class FiscalYearClosingController extends Controller
{
    public function index()
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $defaultYear = (int) date('Y');

        return view('fiscalYearClosing.index', compact('defaultYear'));
    }

    public function runClosing(Request $request, FiscalYearClosingService $service)
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validated = $request->validate([
            'fiscal_year' => 'required|integer|min:2000|max:2100',
            'closing_date' => 'required|date',
            'accounting_period' => 'required|integer|min:0|max:15',
            'allow_duplicate' => 'sometimes|boolean',
        ]);

        $creatorId = \Auth::user()->creatorId();
        $skipIfReferenceExists = ! $request->boolean('allow_duplicate');

        $result = $service->run(
            $creatorId,
            (int) $validated['fiscal_year'],
            $validated['closing_date'],
            (int) $validated['accounting_period'],
            $skipIfReferenceExists
        );

        if (! $result['success']) {
            return redirect()->back()->with('error', implode(' ', $result['messages']));
        }

        return redirect()->back()->with('success', implode(' ', $result['messages']));
    }

    public function runVat(Request $request, VatSettlementService $service)
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validated = $request->validate([
            'vat_fiscal_year' => 'required|integer|min:2000|max:2100',
            'vat_date' => 'required|date',
            'vat_accounting_period' => 'required|integer|min:0|max:15',
            'vat_inv' => 'nullable|numeric',
            'vat_tang' => 'nullable|numeric',
            'vat_srv' => 'nullable|numeric',
            'vat_collected' => 'nullable|numeric',
            'vat_payable' => 'nullable|numeric',
            'vat_recover' => 'nullable|numeric',
        ]);

        $creatorId = \Auth::user()->creatorId();

        $result = $service->run(
            $creatorId,
            (int) $validated['vat_fiscal_year'],
            $validated['vat_date'],
            (int) $validated['vat_accounting_period'],
            [
                'inv' => $validated['vat_inv'] ?? 0,
                'tang' => $validated['vat_tang'] ?? 0,
                'srv' => $validated['vat_srv'] ?? 0,
                'collected' => $validated['vat_collected'] ?? 0,
                'to_payable' => $validated['vat_payable'] ?? 0,
                'to_recover' => $validated['vat_recover'] ?? 0,
            ]
        );

        if (! $result['success']) {
            return redirect()->back()->with('error', $result['message']);
        }

        return redirect()->back()->with('success', $result['message']);
    }
}
