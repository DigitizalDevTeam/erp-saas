<?php

namespace App\Http\Controllers;

use App\Models\BillProduct;
use App\Models\InvoiceProduct;
use App\Models\Tax;
use App\Models\Utility;
use Illuminate\Http\Request;

class MonthlyVatReportController extends Controller
{
    public function modeloA(Request $request)
    {
        if (!\Auth::user()->can('tax report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $start = !empty($request->start_date) ? $request->start_date : date('Y-m-01');
        $end = !empty($request->end_date) ? $request->end_date : date('Y-m-t');

        $settings = Utility::settings();
        $vatTaxName = $settings['vat_tax_name'] ?? 'IVA';

        $tax = Tax::where('created_by', \Auth::user()->creatorId())
            ->where('name', $vatTaxName)
            ->first();

        $vatTaxId = $tax?->id;
        $vatRate = $tax?->rate ?? 0;

        $sales = [
            'taxable_base' => 0.0,
            'vat_output' => 0.0,
        ];
        $purchases = [
            'taxable_base' => 0.0,
            'vat_input' => 0.0,
        ];

        $invoiceProducts = InvoiceProduct::query()
            ->select('invoice_products.*')
            ->leftJoin('invoices', 'invoice_products.invoice_id', '=', 'invoices.id')
            ->leftJoin('product_services', 'invoice_products.product_id', '=', 'product_services.id')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('invoices.status', '!=', 0)
            ->whereBetween('invoices.send_date', [$start, $end])
            ->get();

        foreach ($invoiceProducts as $row) {
            $qty = (float) $row->quantity;
            $price = (float) $row->price;
            $discount = (float) ($row->discount ?? 0);

            $lineBase = max(0, ($price - $discount) * $qty);

            if ($vatTaxId && $row->tax) {
                $taxIds = array_filter(array_map('trim', explode(',', (string) $row->tax)));
                if (in_array((string) $vatTaxId, $taxIds, true)) {
                    $sales['taxable_base'] += $lineBase;
                    $sales['vat_output'] += ($vatRate / 100.0) * $lineBase;
                }
            }
        }

        $billProducts = BillProduct::query()
            ->select('bill_products.*')
            ->leftJoin('bills', 'bill_products.bill_id', '=', 'bills.id')
            ->leftJoin('product_services', 'bill_products.product_id', '=', 'product_services.id')
            ->where('bills.created_by', \Auth::user()->creatorId())
            ->whereBetween('bills.bill_date', [$start, $end])
            ->get();

        foreach ($billProducts as $row) {
            $qty = (float) $row->quantity;
            $price = (float) $row->price;

            $lineBase = max(0, $price * $qty);

            if ($vatTaxId && $row->tax) {
                $taxIds = array_filter(array_map('trim', explode(',', (string) $row->tax)));
                if (in_array((string) $vatTaxId, $taxIds, true)) {
                    $purchases['taxable_base'] += $lineBase;
                    $purchases['vat_input'] += ($vatRate / 100.0) * $lineBase;
                }
            }
        }

        $summary = [
            'vat_payable' => round(max(0, $sales['vat_output'] - $purchases['vat_input']), 2),
            'vat_credit' => round(max(0, $purchases['vat_input'] - $sales['vat_output']), 2),
        ];

        $filter = [
            'startDateRange' => $start,
            'endDateRange' => $end,
            'vatTaxName' => $vatTaxName,
        ];

        return view('reports.monthly.vat_modelo_a', compact('filter', 'sales', 'purchases', 'summary', 'vatRate'));
    }
}

