<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioPayment;
use Modules\HotelManagement\Services\HotelFolioAccountingService;
use Modules\HotelManagement\Services\HotelFolioInvoiceService;
use Modules\HotelManagement\Services\HotelFolioPaymentService;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelPosChargeService;

class HotelFolioController extends Controller
{
    public function __construct(
        private readonly HotelFolioPaymentService $paymentService,
        private readonly HotelFolioInvoiceService $folioInvoiceService,
    ) {
    }

    public function show(HotelFolio $folio)
    {
        if (! HotelAuthorization::viewFolio(Auth::user()) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $folio->load(['stay.reservation.guest', 'lines', 'payments', 'erpInvoice']);
        $paymentMethods = HotelFolioPaymentService::paymentMethodOptions();
        $taxInvoiceUrl = $folio->invoice_id
            ? route('hotel.folios.tax-invoice', $folio->id)
            : null;

        return view('hotelmanagement::folios.show', compact('folio', 'paymentMethods', 'taxInvoiceUrl'));
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
            'method' => 'required|in:cash,mpesa,card,bank_transfer,e_mola,mkesh,transfer,account,other',
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->paymentService->recordPayment(
                $folio,
                (float) $request->amount,
                (string) $request->method,
                (int) Auth::user()->creatorId(),
                $request->reference,
                $request->notes,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
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
        $folio->load(['stay.reservation.guest', 'stay.reservation.room', 'lines', 'payments', 'erpInvoice']);

        if (! $folio->invoice_id && $folio->stay?->status === 'checked_out') {
            $this->folioInvoiceService->createInvoiceFromFolio($folio, (int) Auth::user()->creatorId());
            $folio->refresh()->load('erpInvoice');
        }

        $settings = app(HotelFolioAccountingService::class)->settingsFor((int) Auth::user()->creatorId());
        $vatRate = $settings ? (float) $settings->default_vat_rate_percent : 0;
        $taxInvoiceUrl = $folio->invoice_id
            ? route('hotel.folios.tax-invoice', $folio->id)
            : null;

        return view('hotelmanagement::folios.final_bill_print', compact('folio', 'vatRate', 'taxInvoiceUrl'));
    }

    public function taxInvoicePdf(HotelFolio $folio)
    {
        if (! HotelAuthorization::viewFolio(Auth::user()) || (int) $folio->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $folio->invoice_id) {
            $invoice = $this->folioInvoiceService->createInvoiceFromFolio($folio, (int) Auth::user()->creatorId());
            if (! $invoice) {
                return redirect()->back()->with('error', __('Could not generate tax invoice for this folio.'));
            }
            $folio->refresh();
        }

        return redirect()->route('invoice.pdf', Crypt::encrypt($folio->invoice_id));
    }

    public function paymentReceiptPrint(HotelFolio $folio, HotelFolioPayment $payment)
    {
        if (! HotelAuthorization::viewFolio(Auth::user())
            || (int) $folio->created_by !== (int) Auth::user()->creatorId()
            || (int) $payment->hotel_folio_id !== (int) $folio->id) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $folio->load(['stay.reservation.guest', 'stay.reservation.room']);

        return view('hotelmanagement::folios.payment_receipt_print', compact('folio', 'payment'));
    }
}
