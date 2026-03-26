<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Utility;
use App\Services\InvoiceSaleDeductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class DeliveryNoteController extends Controller
{
    protected function nextNumber(int $creatorId): string
    {
        $next = (int) (DeliveryNote::where('created_by', $creatorId)->max('id') ?? 0) + 1;
        return 'DN-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function createFromInvoice(string $invoiceId)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $id = Crypt::decrypt($invoiceId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invoice Not Found.'));
        }

        $invoice = Invoice::with(['items.product', 'customer', 'billing_address', 'shipping_address', 'deliveryNote'])->find($id);
        if (!$invoice || (int) $invoice->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($invoice->deliveryNote) {
            return redirect()->route('delivery-note.show', Crypt::encrypt($invoice->deliveryNote->id));
        }

        $dn = new DeliveryNote();
        $dn->invoice_id = $invoice->id;
        $dn->delivery_note_no = $this->nextNumber((int) \Auth::user()->creatorId());
        $dn->issue_date = $invoice->issue_date ?? date('Y-m-d');
        $dn->status = 1; // issued immediately
        $dn->created_by = \Auth::user()->creatorId();
        $dn->save();

        // Deduct stock here if company setting says delivery_note and invoice not deducted yet
        $settings = Utility::settings();
        $timing = $settings['stock_deduction_timing'] ?? 'invoice';
        if ($timing === 'delivery_note' && empty($invoice->stock_deducted_at)) {
            foreach ($invoice->items as $line) {
                try {
                    InvoiceSaleDeductionService::deductLine(
                        (int) $line->product_id,
                        (float) $line->quantity,
                        (int) \Auth::user()->creatorId(),
                        (int) $invoice->id,
                        (int) $line->id,
                        (int) $invoice->customer_id,
                        \Auth::user()->invoiceNumberFormat($invoice->invoice_id),
                        $line->identifiers
                    );
                } catch (\Throwable $e) {
                    return redirect()->back()->with('error', $e->getMessage());
                }
            }

            $invoice->stock_deducted_at = now();
            $invoice->stock_deducted_via = 'delivery_note';
            $invoice->save();

            $dn->stock_deducted_at = now();
            $dn->save();
        }

        return redirect()->route('delivery-note.show', Crypt::encrypt($dn->id))->with('success', __('Delivery note created successfully.'));
    }

    public function show(string $id)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $dnId = Crypt::decrypt($id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Delivery Note Not Found.'));
        }

        $deliveryNote = DeliveryNote::with(['invoice.items.product.unit', 'invoice.customer', 'invoice.billing_address', 'invoice.shipping_address', 'roadManifest'])->find($dnId);
        if (!$deliveryNote || (int) $deliveryNote->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('delivery_note.show', compact('deliveryNote'));
    }

    public function print(string $id)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $dnId = Crypt::decrypt($id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Delivery Note Not Found.'));
        }

        $deliveryNote = DeliveryNote::with(['invoice.items.product.unit', 'invoice.customer', 'invoice.billing_address', 'invoice.shipping_address'])->find($dnId);
        if (!$deliveryNote || (int) $deliveryNote->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $settings = Utility::settings();
        return view('delivery_note.print', compact('deliveryNote', 'settings'));
    }
}

