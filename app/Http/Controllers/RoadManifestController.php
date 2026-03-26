<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Models\RoadManifest;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class RoadManifestController extends Controller
{
    protected function nextNumber(int $creatorId): string
    {
        $next = (int) (RoadManifest::where('created_by', $creatorId)->max('id') ?? 0) + 1;
        return 'RM-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function convertFromDeliveryNote(Request $request, string $deliveryNoteId)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $dnId = Crypt::decrypt($deliveryNoteId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Delivery Note Not Found.'));
        }

        $deliveryNote = DeliveryNote::with(['invoice', 'roadManifest'])->find($dnId);
        if (!$deliveryNote || (int) $deliveryNote->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($deliveryNote->roadManifest) {
            return redirect()->route('road-manifest.show', Crypt::encrypt($deliveryNote->roadManifest->id));
        }

        $manifest = new RoadManifest();
        $manifest->delivery_note_id = $deliveryNote->id;
        $manifest->manifest_no = $this->nextNumber((int) \Auth::user()->creatorId());
        $manifest->vehicle_no = $request->vehicle_no;
        $manifest->driver_name = $request->driver_name;
        $manifest->notes = $request->notes;
        $manifest->status = 0;
        $manifest->created_by = \Auth::user()->creatorId();
        $manifest->save();

        return redirect()->route('road-manifest.show', Crypt::encrypt($manifest->id))->with('success', __('Road manifest created successfully.'));
    }

    public function show(string $id)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $manifestId = Crypt::decrypt($id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Road Manifest Not Found.'));
        }

        $roadManifest = RoadManifest::with(['deliveryNote.invoice.items.product.unit', 'deliveryNote.invoice.customer', 'deliveryNote.invoice.billing_address', 'deliveryNote.invoice.shipping_address'])->find($manifestId);
        if (!$roadManifest || (int) $roadManifest->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('road_manifest.show', compact('roadManifest'));
    }

    public function print(string $id)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $manifestId = Crypt::decrypt($id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Road Manifest Not Found.'));
        }

        $roadManifest = RoadManifest::with(['deliveryNote.invoice.items.product.unit', 'deliveryNote.invoice.customer', 'deliveryNote.invoice.billing_address', 'deliveryNote.invoice.shipping_address'])->find($manifestId);
        if (!$roadManifest || (int) $roadManifest->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $settings = Utility::settings();
        return view('road_manifest.print', compact('roadManifest', 'settings'));
    }
}

