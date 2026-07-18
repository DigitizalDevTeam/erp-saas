<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Services\Accounting\FiscalYearClosingService;
use App\Services\Accounting\ManualOpeningBalanceService;
use App\Services\Accounting\OpeningBalanceCarryForwardService;
use App\Services\Accounting\VatSettlementService;
use Illuminate\Http\Request;

class FiscalYearClosingController extends Controller
{
    public function index(Request $request, OpeningBalanceCarryForwardService $openingService)
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $defaultYear = (int) date('Y');
        $activeTab = $this->resolveTab($request->query('tab'));
        $openingMode = $request->query('opening_mode') === 'manual' ? 'manual' : 'automatic';
        $openingTargetYear = (int) ($request->query('opening_target_year') ?: ($defaultYear + 1));
        $openingPreview = null;
        $manualOpeningLines = $this->defaultManualLines();

        if ($request->has('opening_target_year') && $openingMode === 'automatic') {
            $openingPreview = $openingService->preview($creatorId, $openingTargetYear);
        }

        if ($openingMode === 'manual') {
            if ($request->boolean('prefill_auto') && $request->has('opening_target_year')) {
                $autoPreview = $openingService->preview($creatorId, $openingTargetYear);
                $manualOpeningLines = $this->manualLinesFromAutoPreview($autoPreview['lines']);
            } elseif (is_array($request->old('lines'))) {
                $manualOpeningLines = $this->manualLinesFromInput($request->old('lines'));
            }
        }

        $openingAccounts = $this->openingBalanceAccounts($creatorId);

        return view('fiscalYearClosing.index', compact(
            'defaultYear',
            'activeTab',
            'openingMode',
            'openingTargetYear',
            'openingPreview',
            'manualOpeningLines',
            'openingAccounts',
        ));
    }

    public function runClosing(Request $request, FiscalYearClosingService $service)
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validated = $request->validate([
            'fiscal_year' => 'required|integer|min:2000|max:2100',
            'closing_date' => 'required|date',
            'accounting_period' => 'required|integer|in:13,14,15',
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

        $redirect = redirect()->route('fiscal-year-closing.index', ['tab' => 'year-end']);

        if (! $result['success']) {
            return $redirect->with('error', implode(' ', $result['messages']));
        }

        return $redirect->with('success', implode(' ', $result['messages']));
    }

    public function postOpeningBalances(Request $request, OpeningBalanceCarryForwardService $openingService)
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validated = $request->validate([
            'opening_target_year' => 'required|integer|min:2001|max:2100',
            'allow_duplicate_opening' => 'sometimes|boolean',
        ]);

        $result = $openingService->post(
            (int) \Auth::user()->creatorId(),
            (int) $validated['opening_target_year'],
            ! $request->boolean('allow_duplicate_opening')
        );

        $redirect = redirect()->route('fiscal-year-closing.index', [
            'tab' => 'opening',
            'opening_mode' => 'automatic',
            'opening_target_year' => (int) $validated['opening_target_year'],
        ]);

        if (! $result['success']) {
            return $redirect->with('error', implode(' ', $result['messages']));
        }

        return $redirect->with('success', implode(' ', $result['messages']));
    }

    public function postManualOpeningBalances(Request $request, ManualOpeningBalanceService $manualOpeningService)
    {
        if (! \Auth::user()->can('manage journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validated = $request->validate([
            'opening_target_year' => 'required|integer|min:2001|max:2100',
            'allow_duplicate_manual_opening' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.account_id' => 'nullable|integer',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
        ]);

        $result = $manualOpeningService->post(
            (int) \Auth::user()->creatorId(),
            (int) $validated['opening_target_year'],
            $validated['lines'],
            ! $request->boolean('allow_duplicate_manual_opening')
        );

        $redirect = redirect()->route('fiscal-year-closing.index', [
            'tab' => 'opening',
            'opening_mode' => 'manual',
            'opening_target_year' => (int) $validated['opening_target_year'],
        ]);

        if (! $result['success']) {
            return $redirect
                ->withInput(['lines' => $validated['lines']])
                ->with('error', implode(' ', $result['messages']));
        }

        return $redirect->with('success', implode(' ', $result['messages']));
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

        $redirect = redirect()->route('fiscal-year-closing.index', ['tab' => 'vat']);

        if (! $result['success']) {
            return $redirect->with('error', $result['message']);
        }

        return $redirect->with('success', $result['message']);
    }

    private function resolveTab(?string $tab): string
    {
        return in_array($tab, ['opening', 'year-end', 'vat', 'reference'], true) ? $tab : 'opening';
    }

    /**
     * @return list<array{id: int, code: string, label: string}>
     */
    private function openingBalanceAccounts(int $creatorId): array
    {
        $nameColumn = $this->chartNameColumn();

        return ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->get(['id', 'code', $nameColumn])
            ->filter(static fn (ChartOfAccount $account) => OpeningBalanceCarryForwardService::isOpeningBalanceClass($account->code))
            ->map(static fn (ChartOfAccount $account) => [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'label' => sprintf('%s - %s', $account->code, $account->{$nameColumn} ?? $account->name),
            ])
            ->values()
            ->all();
    }

    private function chartNameColumn(): string
    {
        return match (auth()->user()->lang ?? 'en') {
            'fr' => 'name_fr',
            'zh' => 'name_zh',
            'es' => 'name_es',
            'pt' => 'name_pt_br',
            default => 'name',
        };
    }

    /**
     * @return list<array{account_id: string, debit: string, credit: string}>
     */
    private function defaultManualLines(): array
    {
        return [
            ['account_id' => '', 'debit' => '', 'credit' => ''],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: string, debit: string, credit: string}>
     */
    private function manualLinesFromAutoPreview(array $lines): array
    {
        if ($lines === []) {
            return $this->defaultManualLines();
        }

        return array_map(static fn (array $line) => [
            'account_id' => (string) ($line['account_id'] ?? ''),
            'debit' => ($line['debit'] ?? 0) > 0 ? (string) $line['debit'] : '',
            'credit' => ($line['credit'] ?? 0) > 0 ? (string) $line['credit'] : '',
        ], $lines);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array{account_id: string, debit: string, credit: string}>
     */
    private function manualLinesFromInput(array $lines): array
    {
        $normalized = array_map(static fn (array $line) => [
            'account_id' => (string) ($line['account_id'] ?? ''),
            'debit' => (string) ($line['debit'] ?? ''),
            'credit' => (string) ($line['credit'] ?? ''),
        ], array_values($lines));

        return $normalized === [] ? $this->defaultManualLines() : $normalized;
    }
}
