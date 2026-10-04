<?php

namespace Modules\HotelManagement\Services;

use App\Mail\HotelReservationCreatedMail;
use App\Support\LoggedMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelPublicBookingService
{
    public function __construct(
        private readonly HotelPublicAvailabilityService $availabilityService,
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelAddonService $addonService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{payment_ref?: string|null}  $options
     * @return array{reservation: HotelReservation, token: string}
     */
    public function createOnlineBooking(HotelBookingSite $site, array $data, array $options = []): array
    {
        $creatorId = (int) $site->created_by;
        $checkIn = Carbon::parse($data['check_in_at']);
        $checkOut = Carbon::parse($data['check_out_at']);
        $roomTypeId = (int) $data['hotel_room_type_id'];
        $adults = max(1, (int) ($data['adults'] ?? 1));
        $children = max(0, (int) ($data['children'] ?? 0));

        $type = HotelRoomType::where('created_by', $creatorId)->findOrFail($roomTypeId);

        if ($this->availabilityService->availableRoomCount($creatorId, $roomTypeId, $checkIn, $checkOut) < 1) {
            throw new \InvalidArgumentException(__('No rooms available for the selected dates.'));
        }

        $addonRows = $this->addonService->normalizeAddonInput($data['addons'] ?? []);
        $occupancyError = $this->addonService->validateOccupancyForBooking(
            $roomTypeId,
            $adults,
            $children,
            $addonRows,
            $creatorId
        );
        if ($occupancyError) {
            throw new \InvalidArgumentException($occupancyError);
        }

        $room = $this->availabilityService->pickRoomForType($creatorId, $roomTypeId, $checkIn, $checkOut);
        if (! $room) {
            throw new \InvalidArgumentException(__('No rooms available for the selected dates.'));
        }

        $quote = $this->pricingService->quote(
            $creatorId,
            $roomTypeId,
            (int) $room->id,
            $checkIn,
            $checkOut,
            'nightly'
        );

        $token = Str::random(48);

        $reservation = DB::transaction(function () use (
            $site,
            $creatorId,
            $data,
            $checkIn,
            $checkOut,
            $room,
            $type,
            $quote,
            $adults,
            $children,
            $addonRows,
            $token
        ) {
            $guest = HotelGuest::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'nationality' => $data['nationality'] ?? null,
                'preferred_room_type_id' => $type->id,
                'notes' => $data['special_requests'] ?? null,
                'created_by' => $creatorId,
            ]);

            $reservation = HotelReservation::create([
                'reservation_no' => 'WEB-' . strtoupper(substr(uniqid(), -8)),
                'hotel_guest_id' => $guest->id,
                'hotel_room_type_id' => $type->id,
                'hotel_room_id' => $room->id,
                'hotel_rate_plan_id' => $quote['rate_plan_id'] ?? null,
                'check_in_at' => $checkIn->format('Y-m-d H:i:s'),
                'check_out_at' => $checkOut->format('Y-m-d H:i:s'),
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'adults' => $adults,
                'children' => $children,
                'source' => 'online',
                'status' => HotelReservation::STATUS_CONFIRMED,
                'billing_mode' => 'nightly',
                'rate_per_night' => $quote['rate_amount'],
                'discount_amount' => 0,
                'notes' => trim(($data['special_requests'] ?? '') . ' | ' . __('Online booking')),
                'hotel_booking_site_id' => $site->id,
                'online_payment_ref' => $options['payment_ref']
                    ?? ($site->payment_mode === HotelBookingSite::PAY_AT_HOTEL
                        ? 'pay_at_hotel'
                        : ($data['payment_reference'] ?? null)),
                'confirmation_token' => hash('sha256', $token),
                'created_by' => $creatorId,
            ]);

            $this->addonService->syncReservationAddons($reservation, $addonRows, $creatorId);

            return $reservation->fresh(['guest', 'roomType', 'room', 'reservationAddons.addon']);
        });

        $this->sendConfirmationEmail($reservation, $site);

        return ['reservation' => $reservation, 'token' => $token];
    }

    public function findByConfirmationToken(string $slug, string $token): ?HotelReservation
    {
        $site = HotelBookingSite::where('slug', $slug)->first();
        if (! $site) {
            return null;
        }

        return HotelReservation::where('created_by', $site->created_by)
            ->where('hotel_booking_site_id', $site->id)
            ->where('confirmation_token', hash('sha256', $token))
            ->with(['guest', 'roomType', 'room', 'reservationAddons.addon'])
            ->first();
    }

    private function sendConfirmationEmail(HotelReservation $reservation, HotelBookingSite $site): void
    {
        $email = $reservation->guest?->email;
        if (! $email) {
            return;
        }

        try {
            LoggedMail::send($email, new HotelReservationCreatedMail($reservation, [
                'hotel_name' => $site->display_name,
                'check_in_time' => $site->check_in_time,
                'check_out_time' => $site->check_out_time,
            ]), [
                'source' => 'hotel_reservation',
                'subject' => __('Hotel reservation confirmation'),
                'created_by' => (int) ($reservation->created_by ?: $site->created_by),
            ]);
            $reservation->update(['guest_email_sent_at' => now()]);
        } catch (\Throwable) {
            // Non-blocking for guest booking
        }
    }
}
