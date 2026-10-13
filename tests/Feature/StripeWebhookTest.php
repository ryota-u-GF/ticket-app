<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\CheckoutService;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Stripe\Checkout\Session;

beforeEach(function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);

    $this->order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_1']);
    $this->general = OrderItem::factory()->for($this->order)->create(['quantity' => 2]);
    $this->student = OrderItem::factory()->for($this->order)->create(['quantity' => 3]);
});

/**
 * Build the JSON body of a Stripe event.
 *
 * @param  array<string, mixed>  $object
 */
function webhookPayload(string $type, array $object): string
{
    return json_encode([
        'id' => 'evt_test',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ]);
}

/**
 * Build a Stripe-Signature header value with HMAC-SHA256 (no Stripe SDK).
 */
function webhookSignature(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
}

/**
 * Post a raw body to the webhook endpoint, with an optional signature header.
 */
function postWebhook(string $payload, ?string $signature): TestResponse
{
    $headers = $signature === null ? [] : ['Stripe-Signature' => $signature];

    return test()->call('POST', route('stripe.webhook'), [], [], [], test()->transformHeadersToServerVars($headers), $payload);
}

/**
 * Send a webhook request signed the way Stripe does.
 *
 * @param  array<string, mixed>  $object
 */
function sendWebhook(string $type, array $object, ?string $secret = 'whsec_test', ?int $timestamp = null): TestResponse
{
    $payload = webhookPayload($type, $object);

    return postWebhook($payload, $secret === null ? null : webhookSignature($payload, $secret, $timestamp));
}

/**
 * @return array<string, mixed>
 */
function completedSession(Order $order, string $paymentStatus = 'paid'): array
{
    return [
        'id' => 'cs_test_1',
        'object' => 'checkout.session',
        'payment_status' => $paymentStatus,
        'payment_intent' => 'pi_test_1',
        'metadata' => ['order_id' => (string) $order->id],
    ];
}

test('a signed completed session pays the order and issues the tickets', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order))->assertOk();

    $order = $this->order->fresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and(Ticket::count())->toBe(5)
        ->and($this->general->tickets()->count())->toBe(2)
        ->and($this->student->tickets()->count())->toBe(3)
        ->and(Ticket::pluck('code')->unique())->toHaveCount(5);
});

test('an invalid signature is rejected and nothing changes', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order), 'whsec_wrong')->assertStatus(400);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a request without a signature is rejected', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order), null)->assertStatus(400);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('every request is rejected when the webhook secret is not configured', function () {
    config(['services.stripe.webhook_secret' => null]);

    sendWebhook('checkout.session.completed', completedSession($this->order), '')->assertStatus(400);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('the same webhook sent twice issues the tickets once', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order))->assertOk();
    sendWebhook('checkout.session.completed', completedSession($this->order))->assertOk();

    expect(Ticket::count())->toBe(5)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('an unpaid session does not pay the order', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order, 'unpaid'))->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('other event types and unknown orders are acknowledged and ignored', function () {
    sendWebhook('charge.succeeded', completedSession($this->order))->assertOk();
    sendWebhook('checkout.session.completed', ['metadata' => ['order_id' => '999999'], 'payment_status' => 'paid'])->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a cancelled order is not paid by the webhook', function () {
    $this->order->update(['status' => OrderStatus::Cancelled]);

    sendWebhook('checkout.session.completed', completedSession($this->order))->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Ticket::count())->toBe(0);
});

test('a correctly signed but stale timestamp is rejected', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order), 'whsec_test', time() - 301)->assertStatus(400);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a timestamp within the tolerance is accepted', function () {
    sendWebhook('checkout.session.completed', completedSession($this->order), 'whsec_test', time() - 200)->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('a payload changed after signing is rejected', function () {
    $payload = webhookPayload('checkout.session.completed', completedSession($this->order, 'unpaid'));
    $signature = webhookSignature($payload, 'whsec_test');
    $tampered = str_replace('unpaid', 'paid', $payload);

    postWebhook($tampered, $signature)->assertStatus(400);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a webhook followed by the complete page does not issue more tickets', function () {
    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    sendWebhook('checkout.session.completed', completedSession($this->order))->assertOk();

    Livewire::actingAs($this->order->user)
        ->withQueryParams(['session_id' => 'cs_test_1'])
        ->test('pages::orders.complete', ['order' => $this->order])
        ->assertSee('購入が完了しました');

    expect(Ticket::count())->toBe(5)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('the complete page followed by a webhook does not issue more tickets', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->once()->with('cs_test_1')
        ->andReturn(Session::constructFrom(['id' => 'cs_test_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1']));

    Livewire::actingAs($this->order->user)
        ->withQueryParams(['session_id' => 'cs_test_1'])
        ->test('pages::orders.complete', ['order' => $this->order])
        ->assertSee('購入が完了しました');

    expect(Ticket::count())->toBe(5);

    sendWebhook('checkout.session.completed', completedSession($this->order))->assertOk();

    expect(Ticket::count())->toBe(5)
        ->and(Ticket::pluck('code')->unique())->toHaveCount(5)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});
