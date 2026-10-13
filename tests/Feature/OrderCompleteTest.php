<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;

beforeEach(function () {
    $this->order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_1']);
    OrderItem::factory()->for($this->order)->create(['quantity' => 2]);
});

/**
 * Open the completion page as the buyer.
 */
function openCompletePage(Order $order, ?string $sessionId = 'cs_test_1'): Testable
{
    return Livewire::actingAs($order->user)
        ->withQueryParams(array_filter(['session_id' => $sessionId]))
        ->test('pages::orders.complete', ['order' => $order]);
}

function stripeSession(string $paymentStatus): Session
{
    return Session::constructFrom(['id' => 'cs_test_1', 'payment_status' => $paymentStatus, 'payment_intent' => 'pi_test_1']);
}

test('the page confirms the payment when Stripe says paid', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->once()->with('cs_test_1')->andReturn(stripeSession('paid'));

    openCompletePage($this->order)
        ->assertSee('購入が完了しました')
        ->assertSee('チケットを2枚発行しました')
        ->assertDontSee('wire:poll');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::count())->toBe(2);
});

test('the page keeps checking while Stripe says unpaid', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andReturn(stripeSession('unpaid'));

    openCompletePage($this->order)
        ->assertSee('お支払いを確認中です')
        ->assertSeeHtml('wire:poll.3s="confirm"')
        ->call('confirm')
        ->assertSee('お支払いを確認中です');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a later poll confirms the payment', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andReturn(stripeSession('unpaid'), stripeSession('paid'));

    openCompletePage($this->order)
        ->assertSee('お支払いを確認中です')
        ->call('confirm')
        ->assertSee('購入が完了しました');

    expect(Ticket::count())->toBe(2);
});

test('Stripe is not asked without a matching session id', function (?string $sessionId) {
    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    openCompletePage($this->order, $sessionId)->assertSee('お支払いを確認中です');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'missing' => [null],
    'different' => ['cs_test_other'],
]);

test('an order without a checkout session id is never confirmed from the page', function () {
    $this->order->update(['stripe_checkout_session_id' => null]);
    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    openCompletePage($this->order, null)->assertSee('お支払いを確認中です');
});

test('a paid order does not call Stripe again', function () {
    $this->order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    openCompletePage($this->order)->assertSee('購入が完了しました');
});

test('a Stripe error leaves the order pending without breaking the page', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andThrow(new ApiConnectionException('boom'));

    openCompletePage($this->order)->assertSee('お支払いを確認中です');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('other users cannot open the page', function () {
    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_1')
        ->assertForbidden();
});
