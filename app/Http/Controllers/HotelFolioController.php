<?php

namespace App\Http\Controllers;

use App\Models\HotelFolio;
use Illuminate\Support\Facades\Auth;

class HotelFolioController extends Controller
{
    public function show(HotelFolio $folio)
    {
        if ((!Auth::user()->can('show hotel folio') && !Auth::user()->can('manage hotel')) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $folio->load(['stay.reservation.guest', 'lines']);
        return view('hotel.folios.show', compact('folio'));
    }
}
