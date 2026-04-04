<?php

namespace App\Http\Controllers;

use App\Models\BillProduct;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\Tax;
use App\Models\Utility;
use Illuminate\Http\Request;

class MonthlyVatReportController extends Controller
{
    private const RATE_IVA = 16.0;

    private const RATE_ICMS = 5.0;

    public function modeloA(Request $request)
    {
        if (!\Auth::user()->can('tax report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $start = !empty($request->start_date) ? $request->start_date : date('Y-m-01');
        $end = !empty($request->end_date) ? $request->end_date : date('Y-m-t');

        $settings = Utility::settings();
        $creatorId = \Auth::user()->creatorId();

        $exportKeywords = $this->keywordList($settings['modelo_a_export_tax_keywords'] ?? 'export,exportação,exportacao,exportacion');
        $art9Keywords = $this->keywordList($settings['modelo_a_art9_tax_keywords'] ?? 'art. 9,art 9,artigo 9,isenção,isencao');

        $fixedAssetCategoryIds = array_filter(array_map('intval', explode(',', (string) ($settings['modelo_a_fixed_asset_category_ids'] ?? ''))));

        $rates = [
            'iva' => (float) ($settings['modelo_a_iva_rate'] ?? self::RATE_IVA),
            'icms' => (float) ($settings['modelo_a_icms_rate'] ?? self::RATE_ICMS),
        ];

        $ivaTaxName = trim((string) ($settings['vat_tax_name'] ?? 'IVA'));
        $icmsTaxName = trim((string) ($settings['modelo_a_icms_tax_name'] ?? 'ICMS'));
        $ivaTax = $ivaTaxName !== ''
            ? $this->findTaxByNameCaseInsensitive($creatorId, $ivaTaxName)
            : null;
        $icmsTax = $icmsTaxName !== ''
            ? $this->findTaxByNameCaseInsensitive($creatorId, $icmsTaxName)
            : null;

        $c = array_fill(1, 21, 0.0);

        $stats = [
            'invoice_lines_in_period' => 0,
            'invoice_lines_no_tax' => 0,
            'bill_lines_in_period' => 0,
            'bill_lines_no_tax' => 0,
        ];

        $invoiceProducts = InvoiceProduct::query()
            ->select('invoice_products.*')
            ->leftJoin('invoices', 'invoice_products.invoice_id', '=', 'invoices.id')
            ->where('invoices.created_by', $creatorId)
            ->whereRaw('COALESCE(invoices.send_date, invoices.issue_date) BETWEEN ? AND ?', [$start, $end])
            ->get();

        foreach ($invoiceProducts as $row) {
            $qty = (float) $row->quantity;
            $price = (float) $row->price;
            $discount = (float) ($row->discount ?? 0);
            $lineBase = max(0.0, ($price - $discount) * $qty);

            $stats['invoice_lines_in_period']++;
            $taxes = $this->resolveTaxesForLine($row->tax, $row->product_id, $creatorId);
            if ($taxes === []) {
                $stats['invoice_lines_no_tax']++;

                continue;
            }

            $this->allocateSalesLine($c, $lineBase, $taxes, $rates, $exportKeywords, $art9Keywords, $ivaTax, $icmsTax);
        }

        $billProducts = BillProduct::query()
            ->select('bill_products.*')
            ->leftJoin('bills', 'bill_products.bill_id', '=', 'bills.id')
            ->where('bills.created_by', $creatorId)
            ->whereBetween('bills.bill_date', [$start, $end])
            ->get();

        foreach ($billProducts as $row) {
            $qty = (float) $row->quantity;
            $price = (float) $row->price;
            $discount = (float) ($row->discount ?? 0);
            $lineBase = max(0.0, ($price * $qty) - $discount);

            $stats['bill_lines_in_period']++;
            $taxes = $this->resolveTaxesForLine($row->tax, $row->product_id, $creatorId);
            if ($taxes === []) {
                $stats['bill_lines_no_tax']++;

                continue;
            }

            $product = ProductService::where('id', $row->product_id)->where('created_by', $creatorId)->first();
            $this->allocatePurchaseLine($c, $lineBase, $taxes, $rates, $product, $fixedAssetCategoryIds, $ivaTax, $icmsTax);
        }

        $c[6] += $this->manualAmount($request, 'manual_06');
        $c[11] += $this->manualAmount($request, 'manual_11');
        $c[12] += $this->manualAmount($request, 'manual_12');
        $c[13] += $this->manualAmount($request, 'manual_13');

        foreach (range(1, 13) as $i) {
            $c[$i] = round($c[$i], 2);
        }

        $c[14] = round($c[1] + $c[3] + $c[5] + $c[7], 2);
        $c[15] = round($c[8] + $c[9] + $c[10] + $c[11] + $c[12], 2);
        $c[16] = round($c[2] + $c[4] + $c[6] + $c[13], 2);

        if ($c[16] > $c[15]) {
            $c[17] = round($c[16] - $c[15], 2);
            $c[18] = 0.0;
        } else {
            $c[17] = 0.0;
            $c[18] = round($c[15] - $c[16], 2);
        }

        $creditsCommunicated = round($this->manualAmount($request, 'credits_communicated'), 2);
        $c[19] = $creditsCommunicated;
        $c[20] = round(max(0.0, $c[18] - $creditsCommunicated), 2);

        $tabela9 = [
            'additional_pay' => round($this->manualAmount($request, 't9_additional_pay'), 2),
            'additional_recover' => round($this->manualAmount($request, 't9_additional_recover'), 2),
        ];

        $filter = [
            'startDateRange' => $start,
            'endDateRange' => $end,
            'vatTaxName' => $settings['vat_tax_name'] ?? 'IVA',
        ];

        return view('reports.monthly.vat_modelo_a', [
            'filter' => $filter,
            'c' => $c,
            'tabela9' => $tabela9,
            'modeloANotes' => [
                'iva_tax_name' => $ivaTaxName,
                'iva_tax_matched' => $ivaTax !== null,
                'icms_tax_name' => $icmsTaxName,
                'icms_tax_matched' => $icmsTax !== null,
                'rates' => $rates,
                'stats' => $stats,
            ],
            'manualInputs' => [
                'manual_06' => $request->input('manual_06'),
                'manual_11' => $request->input('manual_11'),
                'manual_12' => $request->input('manual_12'),
                'manual_13' => $request->input('manual_13'),
                'credits_communicated' => $request->input('credits_communicated'),
                't9_additional_pay' => $request->input('t9_additional_pay'),
                't9_additional_recover' => $request->input('t9_additional_recover'),
            ],
        ]);
    }

    private function manualAmount(Request $request, string $key): float
    {
        $v = $request->input($key);
        if ($v === null || $v === '') {
            return 0.0;
        }

        return (float) str_replace([' ', ','], ['', '.'], (string) $v);
    }

    /**
     * @return list<object>
     */
    private function resolveTaxesForLine(?string $taxField, int $productId, int $creatorId): array
    {
        $taxStr = $this->normalizeTaxField($taxField);
        if ($taxStr === null || $taxStr === '') {
            $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
            if ($product && !empty($product->tax_id)) {
                $taxStr = $this->normalizeTaxField((string) $product->tax_id);
            }
        }
        if ($taxStr === null || $taxStr === '') {
            return [];
        }

        $taxes = Utility::tax($taxStr);
        $out = [];
        foreach ($taxes as $tax) {
            if ($tax && (int) $tax->created_by === (int) $creatorId) {
                $out[] = $tax;
            }
        }

        return $out;
    }

    private function normalizeTaxField(?string $taxField): ?string
    {
        if ($taxField === null) {
            return null;
        }
        $s = trim((string) $taxField);
        if ($s === '') {
            return null;
        }
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                return implode(',', array_map('strval', $decoded));
            }
        }

        return $s;
    }

    private function findTaxByNameCaseInsensitive(int $creatorId, string $name): ?Tax
    {
        $n = mb_strtolower(trim($name));

        return Tax::where('created_by', $creatorId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$n])
            ->first();
    }

    /**
     * @param array<int,float> $c
     * @param list<object>     $taxes
     * @param list<string>     $exportKeywords
     * @param list<string>     $art9Keywords
     */
    private function allocateSalesLine(array &$c, float $lineBase, array $taxes, array $rates, array $exportKeywords, array $art9Keywords, ?Tax $ivaTax, ?Tax $icmsTax): void
    {
        $sumRate = 0.0;
        foreach ($taxes as $tax) {
            $sumRate += (float) ($tax->rate ?? 0);
        }

        if ($sumRate <= 0.001) {
            $name = mb_strtolower(implode(' ', array_map(fn ($t) => (string) ($t->name ?? ''), $taxes)));
            if ($this->nameMatchesKeyword($name, $exportKeywords)) {
                $c[5] += $lineBase;
            } elseif ($this->nameMatchesKeyword($name, $art9Keywords)) {
                $c[7] += $lineBase;
            } else {
                $c[7] += $lineBase;
            }

            return;
        }

        foreach ($taxes as $tax) {
            $rate = (float) ($tax->rate ?? 0);
            $portion = $lineBase * ($rate / $sumRate);
            $taxAmount = $portion * ($rate / 100.0);

            $isIva = $ivaTax && (int) $tax->id === (int) $ivaTax->id;
            $isIcms = $icmsTax && (int) $tax->id === (int) $icmsTax->id;

            if ($isIva || (!$isIcms && $this->approxRate($rate, $rates['iva']))) {
                $c[1] += $portion;
                $c[2] += $taxAmount;
            } elseif ($isIcms || $this->approxRate($rate, $rates['icms'])) {
                $c[3] += $portion;
                $c[4] += $taxAmount;
            } elseif ($rate > 0.001) {
                $c[1] += $portion;
                $c[2] += $taxAmount;
            }
        }
    }

    /**
     * @param array<int,float> $c
     * @param list<object>     $taxes
     */
    private function allocatePurchaseLine(array &$c, float $lineBase, array $taxes, array $rates, ?ProductService $product, array $fixedAssetCategoryIds, ?Tax $ivaTax, ?Tax $icmsTax): void
    {
        $sumRate = 0.0;
        foreach ($taxes as $tax) {
            $sumRate += (float) ($tax->rate ?? 0);
        }
        if ($sumRate <= 0) {
            $sumRate = 1.0;
        }

        $slot = $this->purchaseDeductionSlot($product, $fixedAssetCategoryIds);

        foreach ($taxes as $tax) {
            $rate = (float) ($tax->rate ?? 0);
            $portion = $lineBase * ($rate / $sumRate);
            $taxAmount = $portion * ($rate / 100.0);

            $isIva = $ivaTax && (int) $tax->id === (int) $ivaTax->id;
            $isIcms = $icmsTax && (int) $tax->id === (int) $icmsTax->id;

            if ($isIva || (!$isIcms && $this->approxRate($rate, $rates['iva']))) {
                $c[$slot] += $taxAmount;
            } elseif ($isIcms || $this->approxRate($rate, $rates['icms'])) {
                $c[$slot] += $taxAmount;
            } elseif ($rate > 0.001) {
                $c[$slot] += $taxAmount;
            }
        }
    }

    private function purchaseDeductionSlot(?ProductService $product, array $fixedAssetCategoryIds): int
    {
        if ($product && in_array((int) $product->category_id, $fixedAssetCategoryIds, true)) {
            return 8;
        }
        if ($product && $product->type === 'product') {
            return 9;
        }

        return 10;
    }

    private function approxRate(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.02;
    }

    private function nameMatchesKeyword(string $name, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($name, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function keywordList(string $csv): array
    {
        $parts = array_map('trim', explode(',', $csv));

        return array_values(array_filter(array_map(fn ($p) => mb_strtolower($p), $parts)));
    }
}
