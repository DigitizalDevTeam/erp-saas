<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioPayment;
use Modules\HotelManagement\Services\HotelFolioAccountingService;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelPosChargeService;

class HotelFolioController extends Controller
{
    public function show(HotelFolio $folio)
    {
        if (! HotelAuthorization::viewFolio(Auth::user()) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
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
        if (! HotelAuthorization::manageFolio(Auth::user()) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
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

        $newPaymentId = null;
        DB::transaction(function () use ($request, $folio, &$newPaymentId) {
            $payment = HotelFolioPayment::create([
                'hotel_folio_id' => $folio->id,
                'amount' => $request->amount,
                'method' => $request->method,
                'reference' => $request->reference,
                'notes' => $request->notes,
                'created_by' => Auth::user()->creatorId(),
            ]);
            $newPaymentId = $payment->id;
            $folio->refresh();
            $paid = (float) $folio->payments()->sum('amount');
            $folio->update(['paid_total' => $paid]);
            if ($paid >= (float) $folio->grand_total - 0.01 && $folio->status === 'open') {
                $folio->update(['status' => 'settled']);
            }
        });

        if ($newPaymentId) {
            $payment = HotelFolioPayment::find($newPaymentId);
            if ($payment) {
                app(HotelFolioAccountingService::class)->postFolioPaymentJournalIfConfigured($payment);
            }
        }

        return redirect()->route('hotel.folios.show', $folio)->with('success', __('Payment recorded.'));
    }

    public function storeLine(Request $request, HotelFolio $folio, HotelPosChargeService $chargeService)
    {
        if (! HotelAuthorization::manageFolio(Auth::user()) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'description' => 'required|string|max:191',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'revenue_channel' => 'nullable|in:room,restaurant,laundry,misc',
            'charge_type' => 'nullable|in:minibar,laundry,misc',
        ]);

        DB::transaction(function () use ($request, $folio, $chargeService) {
            $payload = $request->only([
                'description', 'quantity', 'unit_price', 'tax_amount', 'discount_amount', 'revenue_channel',
            ]);
            if (empty($payload['revenue_channel'])) {
                unset($payload['revenue_channel']);
            }
            $creatorId = (int) Auth::user()->creatorId();
            $chargeType = (string) $request->get('charge_type', 'misc');
            match ($chargeType) {
                'minibar' => $chargeService->addMinibarChargeToFolio($folio, $payload, $creatorId),
                'laundry' => $chargeService->addLaundryChargeToFolio($folio, $payload, $creatorId),
                default => $chargeService->addMiscChargeToFolio($folio, $payload, $creatorId),
            };
        });

        return redirect()->route('hotel.folios.show', $folio)->with('success', __('Charge added to room bill.'));
    }

    public function finalBillPrint(HotelFolio $folio)
    {
        if (! HotelAuthorization::viewFolio(Auth::user()) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $folio->load(['stay.reservation.guest', 'stay.reservation.room', 'lines', 'payments']);

        return view('hotelmanagement::folios.final_bill_print', compact('folio'));
    }
}
