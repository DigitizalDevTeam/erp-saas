<?php

namespace App\Http\Controllers;

use App\Models\RoadManifest;
use App\Models\RoadTransportRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class RoadTransportRecordController extends Controller
{
    protected function nextNumber(int $creatorId): string
    {
        $next = (int) (RoadTransportRecord::where('created_by', $creatorId)->max('id') ?? 0) + 1;
        return 'RTR-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function create(?string $manifestId = null)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $roadManifest = null;
        if (!empty($manifestId)) {
            try {
                $id = Crypt::decrypt($manifestId);
            } catch (\Throwable $e) {
                return redirect()->back()->with('error', __('Road Manifest Not Found.'));
            }

            $roadManifest = RoadManifest::where('id', $id)->where('created_by', \Auth::user()->creatorId())->first();
        }

        return view('road_transport_record.create', compact('roadManifest'));
    }

    public function store(Request $request)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'transport_date' => 'required|date',
        ]);

        $record = new RoadTransportRecord();
        $record->record_no = $this->nextNumber((int) \Auth::user()->creatorId());
        $record->transport_date = $request->transport_date;
        $record->vehicle_no = $request->vehicle_no;
        $record->driver_name = $request->driver_name;
        $record->from_location = $request->from_location;
        $record->to_location = $request->to_location;
        $record->departure_at = $request->departure_at ?: null;
        $record->arrival_at = $request->arrival_at ?: null;
        $record->notes = $request->notes;
        $record->created_by = \Auth::user()->creatorId();

        if ($request->filled('road_manifest_id')) {
            $manifestId = (int) $request->road_manifest_id;
            $manifest = RoadManifest::where('id', $manifestId)->where('created_by', \Auth::user()->creatorId())->first();
            if ($manifest) {
                $record->road_manifest_id = $manifest->id;
            }
        }

        $record->save();

        return redirect()->route('road-transport-record.show', Crypt::encrypt($record->id))->with('success', __('Road transport record created successfully.'));
    }

    public function show(string $id)
    {
        if (!\Auth::user()->can('show invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $rid = Crypt::decrypt($id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Record Not Found.'));
        }

        $record = RoadTransportRecord::with(['roadManifest.deliveryNote.invoice'])->find($rid);
        if (!$record || (int) $record->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('road_transport_record.show', compact('record'));
    }
}

