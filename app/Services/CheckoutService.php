<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class CheckoutService
{
    /**
     * Minutes until a Checkout Session expires.
     *
     * Stripe requires at least 30 minutes after creation; the extra minute absorbs the request time.
     * This must stay shorter than OrderService::PENDING_MINUTES, so a payable session never outlives its reservation.
     */
    public const SESSION_MINUTES = 31;

    public function __construct(private StripeClient $stripe) {}

    /**
     * Create a Stripe Checkout Session for the order and return the URL of the payment page.
     *
     * The unit prices come from the order items, never from the ticket types.
     *
     * @throws ApiErrorException
     */
    public function createSession(Order $order): string
    {
        $order->loadMissing('event', 'items.ticketType');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $order->items->map(fn (OrderItem $item): array => [
                'quantity' => $item->quantity,
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $item->unit_price,
                    'product_data' => ['name' => "{$order->event->title} {$item->ticketType->name}"],
                ],
            ])->all(),
            'metadata' => ['order_id' => (string) $order->id],
            'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->getTimestamp(),
            // The placeholder must stay unescaped, so the query string is appended by hand.
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
        ]);

        $order->update(['stripe_checkout_session_id' => $session->id]);

        return $session->url;
    }

    /**
     * Expire a Checkout Session so that its payment page can no longer be paid.
     *
     * This is best effort: a session that is already completed or expired is rejected by Stripe, and that must not
     * break the cancellation, so the failure is only reported.
     */
    public function expireSession(string $id): void
    {
        try {
            $this->stripe->checkout->sessions->expire($id);
        } catch (ApiErrorException $e) {
            report($e);
        }
    }

    /**
     * Retrieve a Checkout Session from Stripe.
     *
     * @throws ApiErrorException
     */
    public function retrieveSession(string $id): Session
    {
        return $this->stripe->checkout->sessions->retrieve($id);
    }
}
