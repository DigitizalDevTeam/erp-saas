<?php

namespace Modules\HotelManagement\Services;

use App\Models\Utility;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelBookingCheckout;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Entities\HotelRoomType;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;

class HotelStripePaymentService
{
    public function __construct(
        private readonly HotelPublicAvailabilityService $availabilityService,
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelAddonService $addonService,
        private readonly HotelPublicBookingService $bookingService,
    ) {
    }

    public function isConfigured(HotelBookingSite $site): bool
    {
        $status = $this->stripeStatus($site);

        return $status['is_ready'];
    }

    /**
     * @return array{has_keys: bool, is_prepay_mode: bool, is_ready: bool, publishable: string, keys_source: string}
     */
    public function stripeStatus(HotelBookingSite $site): array
    {
        $keys = $this->resolveStripeKeys($site);
        $hasKeys = $keys['publishable'] !== '' && $keys['secret'] !== '';

        return [
            'has_keys' => $hasKeys,
            'is_prepay_mode' => $site->payment_mode === HotelBookingSite::PAY_PREPAY,
            'is_ready' => $hasKeys && $site->payment_mode === HotelBookingSite::PAY_PREPAY,
            'publishable' => $keys['publishable'],
            'keys_source' => $keys['source'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{room_subtotal: float, addon_subtotal: float, grand_total: float, payable: float, units: int}
     */
    public function calculateAmounts(HotelBookingSite $site, array $data): array
    {
        $creatorId = (int) $site->created_by;
        $checkIn = Carbon::parse($data['check_in_at']);
        $checkOut = Carbon::parse($data['check_out_at']);
        $roomTypeId = (int) $data['hotel_room_type_id'];

        $quote = $this->pricingService->quote(
            $creatorId,
            $roomTypeId,
            null,
            $checkIn,
            $checkOut,
            'nightly'
        );

        $addonRows = $this->addonService->normalizeAddonInput($data['addons'] ?? []);
        $addonSubtotal = $this->estimateAddonsTotal(
            $addonRows,
            $creatorId,
            (int) $quote['units'],
        );

        $roomSubtotal = (float) $quote['subtotal'];
        $grandTotal = round($roomSubtotal + $addonSubtotal, 2);
        $percent = max(0, min(100, (int) ($site->prepayment_percent ?: 100)));
        $payable = round($grandTotal * ($percent / 100), 2);

        return [
            'room_subtotal' => $roomSubtotal,
            'addon_subtotal' => $addonSubtotal,
            'grand_total' => $grandTotal,
            'payable' => max(0.5, $payable),
            'units' => (int) $quote['units'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{checkout: HotelBookingCheckout, url: string}
     */
    public function createCheckoutSession(HotelBookingSite $site, array $data): array
    {
        if (! $this->isConfigured($site)) {
            throw new \InvalidArgumentException(__('Online payment is not configured for this hotel.'));
        }

        $creatorId = (int) $site->created_by;
        $checkIn = Carbon::parse($data['check_in_at']);
        $checkOut = Carbon::parse($data['check_out_at']);
        $roomTypeId = (int) $data['hotel_room_type_id'];

        if ($this->availabilityService->availableRoomCount($creatorId, $roomTypeId, $checkIn, $checkOut) < 1) {
            throw new \InvalidArgumentException(__('No rooms available for the selected dates.'));
        }

        $addonRows = $this->addonService->normalizeAddonInput($data['addons'] ?? []);
        $occupancyError = $this->addonService->validateOccupancyForBooking(
            $roomTypeId,
            (int) ($data['adults'] ?? 1),
            (int) ($data['children'] ?? 0),
            $addonRows,
            $creatorId
        );
        if ($occupancyError) {
            throw new \InvalidArgumentException($occupancyError);
        }

        $amounts = $this->calculateAmounts($site, $data);
        $currency = strtolower($this->resolveCurrency($creatorId));
        $keys = $this->resolveStripeKeys($site);
        $type = HotelRoomType::where('created_by', $creatorId)->findOrFail($roomTypeId);
        $guestToken = Str::random(48);

        Stripe::setApiKey($keys['secret']);

        $amountCents = (int) round($amounts['payable'] * 100);

        $checkout = HotelBookingCheckout::create([
            'hotel_booking_site_id' => $site->id,
            'created_by' => $creatorId,
            'stripe_session_id' => 'pending_' . Str::uuid(),
            'status' => HotelBookingCheckout::STATUS_PENDING,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'booking_payload' => $data,
            'guest_token' => $guestToken,
            'expires_at' => now()->addMinutes(30),
        ]);

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => $site->display_name . ' — ' . __('Room booking'),
                        'description' => $type->name . ' (' . $checkIn->toDateString() . ' → ' . $checkOut->toDateString() . ')',
                    ],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('hotel.public.stripe.success', ['hotelSlug' => $site->slug]) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('hotel.public.stripe.cancel', ['hotelSlug' => $site->slug]),
            'customer_email' => $data['email'] ?? null,
            'metadata' => [
                'hotel_booking_checkout_id' => (string) $checkout->id,
                'site_slug' => $site->slug,
            ],
        ]);

        $checkout->update(['stripe_session_id' => $session->id]);

        return ['checkout' => $checkout, 'url' => $session->url];
    }

    /**
     * @return array{reservation: \Modules\HotelManagement\Entities\HotelReservation, token: string}
     */
    public function completeCheckoutSession(string $sessionId): array
    {
        $checkout = HotelBookingCheckout::where('stripe_session_id', $sessionId)->first();
        if (! $checkout) {
            throw new \InvalidArgumentException(__('Payment session not found.'));
        }

        if ($checkout->status === HotelBookingCheckout::STATUS_COMPLETED && $checkout->guest_token) {
            $site = $checkout->bookingSite;
            $reservation = $checkout->reservation;
            if ($reservation && $site) {
                return ['reservation' => $reservation, 'token' => $checkout->guest_token];
            }
        }

        $site = $checkout->bookingSite;
        if (! $site) {
            throw new \InvalidArgumentException(__('Booking site not found.'));
        }

        $keys = $this->resolveStripeKeys($site);
        Stripe::setApiKey($keys['secret']);

        $session = Session::retrieve($sessionId);
        if ($session->payment_status !== 'paid') {
            throw new \InvalidArgumentException(__('Payment was not completed.'));
        }

        return DB::transaction(function () use ($checkout, $site, $session) {
            $checkout->refresh();
            if ($checkout->status === HotelBookingCheckout::STATUS_COMPLETED && $checkout->guest_token) {
                return [
                    'reservation' => $checkout->reservation,
                    'token' => $checkout->guest_token,
                ];
            }

            $paymentRef = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? $session->id);

            $result = $this->bookingService->createOnlineBooking($site, $checkout->booking_payload, [
                'payment_ref' => 'stripe:' . $paymentRef,
            ]);

            $checkout->update([
                'status' => HotelBookingCheckout::STATUS_COMPLETED,
                'hotel_reservation_id' => $result['reservation']->id,
                'stripe_payment_intent' => $paymentRef,
                'guest_token' => $result['token'],
            ]);

            return $result;
        });
    }

    public function handleWebhook(string $payload, ?string $signatureHeader): void
    {
        $event = null;

        $checkoutForSecret = null;
        $data = json_decode($payload, true);
        if (is_array($data) && isset($data['data']['object']['metadata']['hotel_booking_checkout_id'])) {
            $checkoutForSecret = HotelBookingCheckout::find((int) $data['data']['object']['metadata']['hotel_booking_checkout_id']);
        }

        $webhookSecret = null;
        if ($checkoutForSecret?->bookingSite) {
            $keys = $this->resolveStripeKeys($checkoutForSecret->bookingSite);
            $webhookSecret = $keys['webhook_secret'] ?? null;
        }

        if ($webhookSecret && $signatureHeader) {
            try {
                $event = Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);
            } catch (UnexpectedValueException|SignatureVerificationException $e) {
                throw new \InvalidArgumentException($e->getMessage());
            }
        } else {
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('Invalid payload');
            }
            $event = \Stripe\Event::constructFrom($decoded);
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var Session $session */
            $session = $event->data->object;
            if ($session->payment_status === 'paid' && $session->id) {
                $this->completeCheckoutSession($session->id);
            }
        }
    }

    /**
     * @param  array<int, array{addon_id:int,qty:int}>  $addonRows
     */
    private function estimateAddonsTotal(array $addonRows, int $creatorId, int $units): float
    {
        if ($addonRows === []) {
            return 0.0;
        }

        $addons = HotelAddon::where('created_by', $creatorId)
            ->whereIn('id', collect($addonRows)->pluck('addon_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $total = 0.0;
        foreach ($addonRows as $row) {
            $addon = $addons->get($row['addon_id']);
            if (! $addon) {
                continue;
            }
            $qty = max(1, (int) $row['qty']);
            $multiplier = $addon->billing_mode === HotelAddon::BILLING_PER_NIGHT ? max(1, $units) : 1;
            $total += $qty * (float) $addon->price * $multiplier;
        }

        return round($total, 2);
    }

    /**
     * @return array{enabled: bool, publishable: string, secret: string, webhook_secret: ?string, source: string}
     */
    public function resolveStripeKeys(HotelBookingSite $site): array
    {
        $config = $site->payment_config ?? [];
        $company = Utility::getCompanyPaymentSetting((int) $site->created_by);

        $publishable = trim((string) ($config['stripe_key'] ?? $company['stripe_key'] ?? ''));
        $secret = trim((string) ($config['stripe_secret'] ?? $company['stripe_secret'] ?? ''));

        $source = 'none';
        if (! empty($config['stripe_key']) && ! empty($config['stripe_secret'])) {
            $source = 'booking_site';
        } elseif (! empty($config['stripe_key']) || ! empty($config['stripe_secret'])) {
            $source = 'mixed';
        } elseif (! empty($company['stripe_key']) && ! empty($company['stripe_secret'])) {
            $source = 'company';
        }

        $hasKeys = $publishable !== '' && $secret !== '';

        return [
            'enabled' => $hasKeys || ($company['is_stripe_enabled'] ?? 'off') === 'on',
            'publishable' => $publishable,
            'secret' => $secret,
            'webhook_secret' => $config['stripe_webhook_secret'] ?? config('services.stripe.webhook_secret'),
            'source' => $source,
        ];
    }

    private function resolveCurrency(int $creatorId): string
    {
        $settings = Utility::settingsById($creatorId);

        return strtoupper((string) ($settings['site_currency'] ?? 'USD'));
    }
}
