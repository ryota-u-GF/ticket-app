<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Verify the signature of the Stripe event and confirm the payment of the order.
     *
     * Events that are not handled, and orders that no longer exist, still return 200 so that Stripe stops retrying.
     */
    public function __invoke(Request $request, OrderService $orderService): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            return response('Webhook secret is not configured.', 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature.', 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = Session::constructFrom($event->data->object->toArray());
            $orderId = $session->metadata['order_id'] ?? null;
            $order = is_numeric($orderId) ? Order::query()->find((int) $orderId) : null;

            if ($order !== null && $session->payment_status === 'paid') {
                $orderService->markAsPaid($order, $session->payment_intent);
            }
        }

        return response('', 200);
    }
}
