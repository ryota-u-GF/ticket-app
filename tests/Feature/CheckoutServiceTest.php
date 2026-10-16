<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

test('createSession sends the order to Stripe and stores the session id', function () {
    $event = Event::factory()->published()->create(['title' => '味噌づくり']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 9999]);
    $order = Order::factory()->for($event)->create();
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 2, 'unit_price' => 3000]);

    $stripe = new class extends StripeClient
    {
        /** @var array<string, mixed>|null */
        public ?array $params = null;

        public function __construct() {}

        public function __get($name)
        {
            $client = $this;

            return (object) ['sessions' => new class($client)
            {
                public function __construct(private object $client) {}

                public function create(array $params): Session
                {
                    $this->client->params = $params;

                    return Session::constructFrom(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123']);
                }
            }];
        }
    };

    $this->freezeTime();

    $url = (new CheckoutService($stripe))->createSession($order);

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_123')
        ->and($order->refresh()->stripe_checkout_session_id)->toBe('cs_test_123')
        ->and($stripe->params)->toBe([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 2,
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => 3000,
                    'product_data' => ['name' => '味噌づくり 一般'],
                ],
            ]],
            'metadata' => ['order_id' => (string) $order->id],
            'expires_at' => now()->addMinutes(31)->timestamp,
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
        ]);
});

test('expireSession asks Stripe to expire the session and does not throw when Stripe refuses', function () {
    $stripe = new class extends StripeClient
    {
        /** @var list<string> */
        public array $expired = [];

        public function __construct() {}

        public function __get($name)
        {
            $client = $this;

            return (object) ['sessions' => new class($client)
            {
                public function __construct(private object $client) {}

                public function expire(string $id): void
                {
                    $this->client->expired[] = $id;

                    throw new InvalidRequestException('already completed');
                }
            }];
        }
    };

    (new CheckoutService($stripe))->expireSession('cs_test_done');

    expect($stripe->expired)->toBe(['cs_test_done']);
});

test('the Stripe client is a singleton built from the dummy test key', function () {
    expect(app(StripeClient::class))->toBeInstanceOf(StripeClient::class)
        ->and(app(StripeClient::class))->toBe(app(StripeClient::class));
});
