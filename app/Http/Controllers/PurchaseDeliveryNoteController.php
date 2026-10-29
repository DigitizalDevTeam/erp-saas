<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Purchase;
use App\Models\PurchaseDeliveryNote;
use App\Models\warehouse;
use App\Services\PurchaseDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class PurchaseDeliveryNoteController extends Controller
{
    public function createForPurchase($encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Purchase Not Found.'));
        }
        $purchase = Purchase::where('id', $id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $purchase) {
            return redirect()->back()->with('error', __('Purchase Not Found.'));
        }
        if (! PurchaseDeliveryService::isDeliveryDrivenPurchase($purchase)) {
            return redirect()->back()->with('error', __('This purchase order already added its stock when it was created. Delivery notes are only for new purchase orders.'));
        }

        $lines = PurchaseDeliveryService::sourceLinesForPurchase($purchase);
        $sourceType = 'purchase';
        $source = $purchase;
        $warehouses = warehouse::where('created_by', Auth::user()->creatorId())->orderBy('name')->get();

        return view('purchase_delivery_notes.create', compact('purchase', 'lines', 'sourceType', 'source', 'warehouses'));
    }

    public function storeForPurchase(Request $request, $encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Purchase Not Found.'));
        }
        $purchase = Purchase::where('id', $id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $purchase) {
            return redirect()->back()->with('error', __('Purchase Not Found.'));
        }

        try {
            $note = PurchaseDeliveryService::createNote((int) Auth::user()->creatorId(), [
                'vender_id' => $purchase->vender_id,
                'warehouse_id' => $purchase->warehouse_id,
                'purchase_id' => $purchase->id,
                'delivery_date' => $request->input('delivery_date') ?: now()->toDateString(),
                'items' => $request->input('items', []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('purchase.show', Crypt::encrypt($purchase->id))
            ->with('success', __('Delivery note DN-') . $note->note_number . __(' created. Stock added for the delivered quantities only.'));
    }

    public function createForBill($encryptedId)
    {
        if (! Auth::user()->can('create bill')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Bill Not Found.'));
        }
        $bill = Bill::where('id', $id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $bill) {
            return redirect()->back()->with('error', __('Bill Not Found.'));
        }
        if (! empty($bill->purchase_id)) {
            return redirect()->back()->with('error', __('This bill was converted from a purchase order. Create the delivery note against that purchase order instead.'));
        }
        if (! PurchaseDeliveryService::isDeliveryDrivenBill($bill)) {
            return redirect()->back()->with('error', __('This bill already added its stock when it was created. Delivery notes are only for new bills.'));
        }

        $lines = PurchaseDeliveryService::sourceLinesForBill($bill);
        $sourceType = 'bill';
        $source = $bill;
        $warehouses = warehouse::where('created_by', Auth::user()->creatorId())->orderBy('name')->get();

        return view('purchase_delivery_notes.create', compact('bill', 'lines', 'sourceType', 'source', 'warehouses'));
    }

    public function storeForBill(Request $request, $encryptedId)
    {
        if (! Auth::user()->can('create bill')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Bill Not Found.'));
        }
        $bill = Bill::where('id', $id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $bill) {
            return redirect()->back()->with('error', __('Bill Not Found.'));
        }

        try {
            $note = PurchaseDeliveryService::createNote((int) Auth::user()->creatorId(), [
                'vender_id' => $bill->vender_id,
                'warehouse_id' => $request->input('warehouse_id'),
                'bill_id' => $bill->id,
                'delivery_date' => $request->input('delivery_date') ?: now()->toDateString(),
                'items' => $request->input('items', []),
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('bill.show', Crypt::encrypt($bill->id))
            ->with('success', __('Delivery note DN-') . $note->note_number . __(' created. Stock added for the delivered quantities only.'));
    }

    public function show($encryptedId)
    {
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Delivery note not found.'));
        }
        $note = PurchaseDeliveryNote::with('items.product')->where('id', $id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $note) {
            return redirect()->back()->with('error', __('Delivery note not found.'));
        }

        return view('purchase_delivery_notes.show', compact('note'));
    }
}
