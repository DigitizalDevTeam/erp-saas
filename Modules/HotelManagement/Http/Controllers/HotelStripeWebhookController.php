<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\HotelManagement\Services\HotelStripePaymentService;

class HotelStripeWebhookController extends Controller
{
    public function __construct(
        private readonly HotelStripePaymentService $stripeService,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $this->stripeService->handleWebhook(
                $request->getContent(),
                $request->header('Stripe-Signature')
            );
        } catch (\InvalidArgumentException $e) {
            return response($e->getMessage(), 400);
        } catch (\Throwable $e) {
            report($e);

            return response('Webhook error', 500);
        }

        return response('OK', 200);
    }
}
