<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioPayment;

class HotelFolioController extends Controller
{
    public function show(HotelFolio $folio)
    {
        if ((!Auth::user()->can('show hotel folio') && !Auth::user()->can('manage hotel')) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $folio->load(['stay.reservation.guest', 'lines', 'payments']);
        $paymentMethods = [
            'cash' => __('Cash'),
            'mpesa' => __('M-Pesa'),
            'card' => __('Card'),
            'bank_transfer' => __('Bank Transfer'),
            'e_mola' => __('e-Mola'),
            'mkesh' => __('Mkesh'),
        ];

        return view('hotelmanagement::folios.show', compact('folio', 'paymentMethods'));
    }

    public function storePayment(Request $request, HotelFolio $folio)
    {
        if ((!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (!Schema::hasTable('hotel_folio_payments')) {
            return redirect()->back()->with('error', __('Run migrations to enable folio payments.'));
        }
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,mpesa,card,bank_transfer,e_mola,mkesh',
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($request, $folio) {
            HotelFolioPayment::create([
                'hotel_folio_id' => $folio->id,
                'amount' => $request->amount,
                'method' => $request->method,
                'reference' => $request->reference,
                'notes' => $request->notes,
                'created_by' => Auth::user()->creatorId(),
            ]);
            $folio->refresh();
            $paid = (float) $folio->payments()->sum('amount');
            $folio->update(['paid_total' => $paid]);
            if ($paid >= (float) $folio->grand_total - 0.01 && $folio->status === 'open') {
                $folio->update(['status' => 'settled']);
            }
        });

        return redirect()->route('hotel.folios.show', $folio)->with('success', __('Payment recorded.'));
    }
}
