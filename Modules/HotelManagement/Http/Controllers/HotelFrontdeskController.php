<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;
use Carbon\Carbon;
use Modules\HotelManagement\Services\HotelAddonService;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Modules\HotelManagement\Services\HotelFolioAccountingService;
use Modules\HotelManagement\Services\HotelFolioPaymentService;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelRoomConsumptionService;
use Modules\HotelManagement\Services\HotelFolioInvoiceService;
use Modules\HotelManagement\Services\HotelHousekeepingService;
use Modules\HotelManagement\Services\HotelMinibarRestockService;
use Modules\HotelManagement\Services\HotelRoomStatusService;
use Modules\HotelManagement\Services\HotelStayOperationsService;

class HotelFrontdeskController extends Controller
{
    use BuildsRoomMap;

    public function __construct(
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelRoomConsumptionService $consumptionService,
        private readonly HotelFolioPaymentService $paymentService,
        private readonly HotelAddonService $addonService,
        private readonly HotelStayOperationsService $stayOperations,
        private readonly HotelFolioInvoiceService $folioInvoiceService,
        private readonly HotelMinibarRestockService $minibarRestockService,
        private readonly HotelRoomStatusService $roomStatusService,
        private readonly HotelHousekeepingService $housekeepingService,
    ) {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewFrontdesk(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageFrontdesk(Auth::user());
    }

    public function inHouse()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $stays = HotelStay::where('created_by', Auth::user()->creatorId())
            ->with(['reservation.guest', 'reservation.room', 'folio.payments'])
            ->orderByDesc('id')
            ->get();
        $mapRooms = $this->roomsWithCurrentReservation((int) Auth::user()->creatorId());
        $canManage = $this->canManage();
        $canForceCheckout = HotelAuthorization::canForceCheckout(Auth::user());

        return view('hotelmanagement::frontdesk.in_house', compact('stays', 'mapRooms', 'canManage', 'canForceCheckout'));
    }

    public function showCheckout(HotelReservation $reservation)
    {
        if (! $this->canView() || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $reservation->load(['guest', 'room.roomType', 'stay.folio.lines', 'stay.folio.payments', 'stay.folio.erpInvoice']);

        $folio = $reservation->stay?->folio;
        $consumptionLines = $folio
            ? $this->consumptionService->folioLinesForCheckout($folio)
            : [];
        $stay = $reservation->stay;
        $consumptionAuditLogs = $stay
            ? $this->consumptionService->consumptionLogsForStay((int) $stay->id, (int) Auth::user()->creatorId())
            : collect();
        $creatorId = (int) Auth::user()->creatorId();
        $requireMinibarScan = $this->minibarRestockService->requiresMinibarScanAtCheckout($creatorId);
        $minibarVerified = (bool) $stay?->minibar_verified_at;
        $paymentMethods = HotelFolioPaymentService::paymentMethodOptions();

        $availableRooms = $reservation->status === HotelReservation::STATUS_CHECKED_IN
            ? $this->stayOperations->availableRoomsForChange($reservation, (int) Auth::user()->creatorId())
            : collect();

        $taxInvoiceUrl = $folio?->invoice_id
            ? route('hotel.folios.tax-invoice', $folio->id)
            : null;

        return view('hotelmanagement::frontdesk.checkout', [
            'reservation' => $reservation,
            'folio' => $folio,
            'stay' => $stay,
            'consumptionLines' => $consumptionLines,
            'canManage' => $this->canManage(),
            'canForceCheckout' => HotelAuthorization::canForceCheckout(Auth::user()),
            'checkOutUrl' => $stay ? route('hotel.frontdesk.check-out', $stay->id) : null,
            'paymentMethods' => $paymentMethods,
            'availableRooms' => $availableRooms,
            'extendStayUrl' => route('hotel.frontdesk.extend-stay', $reservation->id),
            'changeRoomUrl' => route('hotel.frontdesk.change-room', $reservation->id),
            'taxInvoiceUrl' => $taxInvoiceUrl,
            'consumptionAuditLogs' => $consumptionAuditLogs,
            'requireMinibarScan' => $requireMinibarScan,
            'minibarVerified' => $minibarVerified,
        ]);
    }

    public function verifyMinibar(HotelStay $stay)
    {
        if (! $this->canManage() || (int) $stay->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($stay->status !== 'in_house') {
            return redirect()->back()->with('error', __('Guest is not in-house.'));
        }

        $stay->update(['minibar_verified_at' => now()]);

        $reservationId = $stay->reservation?->id;

        return redirect()
            ->route('hotel.frontdesk.checkout', $reservationId ?? $stay->hotel_reservation_id)
            ->with('success', __('Minibar scan recorded.'));
    }

    public function extendStay(Request $request, HotelReservation $reservation)
    {
        if (! $this->canManage() || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'check_out_at' => 'required|date',
        ]);

        try {
            $this->stayOperations->extendStay(
                $reservation,
                Carbon::parse($request->check_out_at),
                (int) Auth::user()->creatorId()
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('hotel.frontdesk.checkout', $reservation->id)
            ->with('success', __('Stay extended successfully.'));
    }

    public function changeRoom(Request $request, HotelReservation $reservation)
    {
        if (! $this->canManage() || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'hotel_room_id' => 'required|exists:hotel_rooms,id',
        ]);

        try {
            $this->stayOperations->changeRoom(
                $reservation,
                (int) $request->hotel_room_id,
                (int) Auth::user()->creatorId()
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('hotel.frontdesk.checkout', $reservation->id)
            ->with('success', __('Room changed successfully.'));
    }

    public function showReceipt(HotelReservation $reservation)
    {
        if (! $this->canView() || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $reservation->load(['guest', 'room.roomType', 'stay.folio.lines', 'stay.folio.payments' => fn ($q) => $q->with('creator'), 'stay.folio.erpInvoice']);

        $type = request('type', 'receipt');
        $pageTitle = $type === 'invoice_receipt'
            ? __('Invoice-receipt')
            : __('Receive payment');

        $folio = $reservation->stay?->folio;
        $paymentMethods = HotelFolioPaymentService::paymentMethodOptions();
        $taxInvoiceUrl = $folio?->invoice_id
            ? route('hotel.folios.tax-invoice', $folio->id)
            : null;

        return view('hotelmanagement::frontdesk.receipt', [
            'reservation' => $reservation,
            'folio' => $folio,
            'documentType' => $type,
            'pageTitle' => $pageTitle,
            'canManage' => $this->canManage(),
            'paymentMethods' => $paymentMethods,
            'taxInvoiceUrl' => $taxInvoiceUrl,
        ]);
    }

    public function storeReceiptPayment(Request $request, HotelReservation $reservation)
    {
        if (! $this->canManage() || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $folio = $reservation->stay?->folio;
        if (! $folio) {
            return redirect()->back()->with('error', __('No folio charges yet. Room bill is created at check-in.'));
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|max:32',
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $payment = $this->paymentService->recordPayment(
                $folio,
                (float) $request->amount,
                (string) $request->method,
                (int) Auth::user()->creatorId(),
                $request->reference,
                $request->notes,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($request->boolean('print_receipt')) {
            return redirect()
                ->route('hotel.folios.payments.receipt', [$folio->id, $payment->id])
                ->with('success', __('Payment recorded.'));
        }

        return redirect()
            ->route('hotel.frontdesk.receipt', ['reservation' => $reservation->id, 'type' => $request->get('document_type', 'receipt')])
            ->with('success', __('Payment recorded.'));
    }

    public function checkIn(HotelReservation $reservation)
    {
        if ((! $this->canManage()) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (!$reservation->hotel_room_id) {
            return redirect()->back()->with('error', __('Assign room before check-in.'));
        }
        if ($reservation->status === HotelReservation::STATUS_CHECKED_IN) {
            return redirect()->back()->with('error', __('Reservation is already checked in.'));
        }

        $reservation->load('reservationAddons');

        $room = HotelRoom::where('created_by', Auth::user()->creatorId())->find($reservation->hotel_room_id);
        if (! $room) {
            return redirect()->back()->with('error', __('Room not found.'));
        }

        $block = $this->roomStatusService->assignmentBlockReason($room);
        if ($block) {
            return redirect()->back()->with('error', $block);
        }

        $occupancyError = $this->addonService->validateOccupancy(
            $reservation,
            $this->addonService->normalizeAddonInput(
                $reservation->reservationAddons->map(fn ($ra) => [
                    'addon_id' => $ra->hotel_addon_id,
                    'qty' => $ra->quantity,
                ])->all()
            ),
            (int) Auth::user()->creatorId()
        );
        if ($occupancyError) {
            return redirect()->back()->with('error', $occupancyError);
        }

        DB::transaction(function () use ($reservation, $room) {
            $stay = HotelStay::create([
                'hotel_reservation_id' => $reservation->id,
                'hotel_guest_id' => $reservation->hotel_guest_id,
                'hotel_room_id' => $reservation->hotel_room_id,
                'check_in_at' => now(),
                'expected_check_out_at' => $reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'),
                'status' => 'in_house',
                'created_by' => Auth::user()->creatorId(),
            ]);

            $totals = $this->pricingService->reservationTotals($reservation);
            $unitLabel = strtolower($totals['unit_label']);
            $folio = HotelFolio::create([
                'hotel_stay_id' => $stay->id,
                'folio_no' => 'FOL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                'sub_total' => $totals['sub_total'],
                'grand_total' => $totals['grand_total'],
                'created_by' => Auth::user()->creatorId(),
            ]);

            HotelFolioLine::create([
                'hotel_folio_id' => $folio->id,
                'line_type' => 'room_charge',
                'description' => ucfirst($unitLabel) . ' charge (' . $totals['units'] . ' ' . $unitLabel . ')',
                'quantity' => $totals['units'],
                'unit_price' => $reservation->rate_per_night,
                'discount_amount' => $reservation->discount_amount,
                'line_total' => $totals['grand_total'],
                'created_by' => Auth::user()->creatorId(),
            ]);

            $reservation->update(['status' => HotelReservation::STATUS_CHECKED_IN]);
            $room->refresh();
            $this->roomStatusService->markOccupied($room, (int) Auth::user()->creatorId());

            $reservation->refresh();
            $reservation->load('reservationAddons');
            $this->addonService->postReservationAddonsToFolio($reservation, $folio, (int) Auth::user()->creatorId());
        });

        return redirect()->route('hotel.frontdesk.in-house')->with('success', __('Guest checked in successfully.'));
    }

    public function checkOut(Request $request, HotelStay $stay)
    {
        if ((! $this->canManage()) || (int) $stay->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($stay->status === 'checked_out') {
            return redirect()->back()->with('error', __('Stay already checked out.'));
        }

        $request->validate([
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:32',
            'payment_reference' => 'nullable|string|max:191',
            'checkout_notes' => 'nullable|string|max:2000',
            'force_checkout' => 'nullable|boolean',
        ]);

        $folio = $stay->folio;

        if ($request->filled('checkout_notes')) {
            $stay->update(['notes' => $request->checkout_notes]);
        }

        $paymentAmount = (float) $request->input('payment_amount', 0);
        if ($folio && $paymentAmount >= 0.01) {
            try {
                $this->paymentService->recordPayment(
                    $folio,
                    $paymentAmount,
                    (string) $request->input('payment_method', 'cash'),
                    (int) Auth::user()->creatorId(),
                    $request->payment_reference,
                    __('Hotel folio payment'),
                );
                $folio->refresh();
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return redirect()->back()->withInput()->with('error', $e->getMessage());
            }
        }

        if ($folio) {
            $folio->refresh();
            $paid = $folio->paymentStatusLabel();
            if ($paid !== 'paid') {
                $canForce = HotelAuthorization::canForceCheckout(Auth::user())
                    || $request->boolean('force_checkout');
                if (! $canForce) {
                    return redirect()->back()->withInput()->with(
                        'error',
                        __('Settle room bill before checkout')
                    )->with('warning_folio_id', $folio->id);
                }
            }
        }

        $creatorId = (int) Auth::user()->creatorId();
        if ($this->minibarRestockService->requiresMinibarScanAtCheckout($creatorId) && ! $stay->minibar_verified_at) {
            return redirect()->back()->withInput()->with(
                'error',
                __('Record minibar scan before completing checkout.')
            );
        }

        $forcedCheckout = $folio && $folio->paymentStatusLabel() !== 'paid';

        DB::transaction(function () use ($stay, $folio, $forcedCheckout, $creatorId) {
            if ($folio) {
                $folio->refresh();
                if (Schema::hasColumn('hotel_folios', 'finalize_journal_id')) {
                    app(HotelFolioAccountingService::class)->finalizeGuestInvoiceIfConfigured(
                        $folio,
                        now()->format('Y-m-d')
                    );
                    $folio->refresh();
                }
                $this->folioInvoiceService->createInvoiceFromFolio($folio, (int) Auth::user()->creatorId());
                $folio->refresh();
            }

            $stay->update([
                'checked_out_at' => now(),
                'status' => 'checked_out',
            ]);

            $reservation = $stay->reservation;
            if ($reservation) {
                $reservation->update(['status' => HotelReservation::STATUS_CHECKED_OUT]);
            }

            if ($stay->hotel_room_id) {
                $checkoutRoom = HotelRoom::find($stay->hotel_room_id);
                if ($checkoutRoom) {
                    $this->housekeepingService->queueRoomAfterCheckout($checkoutRoom, $creatorId);
                }
            }

            $this->minibarRestockService->queueRestockAfterCheckout($stay, $creatorId);

            if ($folio) {
                $folio->refresh();
                if ($folio->paymentStatusLabel() === 'paid') {
                    $folio->update(['status' => 'settled']);
                }
            }
        });

        $msg = __('Guest checked out successfully.');
        if ($forcedCheckout) {
            $msg .= ' ' . __('Guest checked out balance note');
        }

        return redirect()->route('hotel.frontdesk.in-house')->with('success', $msg);
    }
}
