<?php

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;

beforeEach(function () {
    $this->event = Event::factory()->published()->create();
    $this->ticketType = TicketType::factory()->for($this->event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 50]);
});

test('the order passed to Stripe keeps the unit price at the time of purchase', function () {
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 2]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->once()
        ->with(Mockery::on(function (Order $order) {
            // 注文作成後に券種の価格が変わっても、明細は注文時点の単価のまま
            $this->ticketType->update(['price' => 9999]);

            $item = $order->items->first();

            return $order->items->count() === 1
                && $item->unit_price === 3000
                && $item->quantity === 2
                && $item->ticket_type_id === $this->ticketType->id;
        }))
        ->andReturn('https://checkout.stripe.com/c/pay/test');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect('https://checkout.stripe.com/c/pay/test');

    expect(OrderItem::first()->unit_price)->toBe(3000)
        ->and(Order::first()->total_amount)->toBe(6000);
});

test('the total of several ticket types is reflected in the order passed to Stripe', function () {
    $student = TicketType::factory()->for($this->event)->create(['name' => '学生', 'price' => 1500]);
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 2, $student->id => 3]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->once()
        ->with(Mockery::on(fn (Order $order) => $order->total_amount === 10500
            && $order->items->count() === 2
            && $order->items->sum(fn (OrderItem $item) => $item->unit_price * $item->quantity) === 10500))
        ->andReturn('https://checkout.stripe.com/c/pay/test');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect('https://checkout.stripe.com/c/pay/test');
});

test('a free order never reaches Stripe and has no checkout session id', function () {
    $free = TicketType::factory()->for($this->event)->create(['name' => '無料', 'price' => 0]);
    session(["purchase.{$this->event->id}" => [$free->id => 2]]);

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect(route('orders.complete', Order::first()));

    expect(Order::first()->stripe_checkout_session_id)->toBeNull()
        ->and(Order::first()->total_amount)->toBe(0)
        ->and(Order::first()->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::count())->toBe(2)
        ->and(session()->has("purchase.{$this->event->id}"))->toBeFalse();
});

test('the remaining count is restored when Stripe fails', function () {
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 4]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->once()
        ->andThrow(new ApiConnectionException('boom'));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertSee('決済ページを開けませんでした');

    expect(Order::first()->status)->toBe(OrderStatus::Cancelled)
        ->and(Order::first()->stripe_checkout_session_id)->toBeNull()
        ->and($this->ticketType->remainingCount())->toBe(50);
});

test('the selection is kept when Stripe fails, so the user can try again', function () {
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 1]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->once()->andThrow(new ApiConnectionException('boom'))
        ->shouldReceive('createSession')
        ->once()->andReturn('https://checkout.stripe.com/c/pay/retry');

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase');

    expect(session()->has("purchase.{$this->event->id}"))->toBeTrue();

    $component->call('pay')->assertRedirect('https://checkout.stripe.com/c/pay/retry');

    expect(session()->has("purchase.{$this->event->id}"))->toBeFalse()
        ->and(Order::where('status', OrderStatus::Pending)->count())->toBe(1);
});

test('an unexpected error while creating the session is reported and cancels the order', function () {
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 1]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->andThrow(new TypeError('url is null'));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertNoRedirect();

    expect(Order::first()->status)->toBe(OrderStatus::Cancelled)
        ->and(session()->has("purchase.{$this->event->id}"))->toBeTrue();
});

test('a free order can be bought even when no Stripe secret is configured', function () {
    config(['services.stripe.secret' => '']);
    app()->forgetInstance(StripeClient::class);
    app()->forgetInstance(CheckoutService::class);

    $free = TicketType::factory()->for($this->event)->create(['name' => '無料', 'price' => 0]);
    session(["purchase.{$this->event->id}" => [$free->id => 1]]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect(route('orders.complete', Order::first()));
});

test('without a Stripe secret, paying shows the error instead of crashing', function () {
    config(['services.stripe.secret' => '']);
    app()->forgetInstance(StripeClient::class);
    app()->forgetInstance(CheckoutService::class);

    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 1]]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase');

    expect(Order::first()->status)->toBe(OrderStatus::Cancelled);
});

test('cancelling an order expires its Stripe payment page', function () {
    $order = Order::factory()->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_open']);

    $this->mock(CheckoutService::class)->shouldReceive('expireSession')->once()->with('cs_test_open');

    expect(app(OrderService::class)->cancelOrder($order))->toBeTrue();
});

test('an order that is not cancelled does not expire its Stripe payment page', function () {
    $paid = Order::factory()->paid()->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_paid']);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    expect(app(OrderService::class)->cancelOrder($paid))->toBeFalse();
});

test('buying again expires the payment pages of the earlier pending orders', function () {
    $user = User::factory()->create();
    Order::factory()->for($user)->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_old']);
    Order::factory()->for($user)->for($this->event)->create(['stripe_checkout_session_id' => null]);

    $this->mock(CheckoutService::class)->shouldReceive('expireSession')->once()->with('cs_test_old');

    app(OrderService::class)->createOrder($user, $this->event, [$this->ticketType->id => 1]);
});

test('earlier payment pages are kept when the new order is rejected', function () {
    $user = User::factory()->create();
    $old = Order::factory()->for($user)->for($this->event)->create(['stripe_checkout_session_id' => 'cs_test_old']);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    expect(fn () => app(OrderService::class)->createOrder($user, $this->event, [$this->ticketType->id => 99]))
        ->toThrow(PurchaseException::class);

    expect($old->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a payment page never outlives the reservation of its tickets', function () {
    expect(CheckoutService::SESSION_MINUTES)->toBeGreaterThanOrEqual(30)
        ->and(OrderService::PENDING_MINUTES)->toBeGreaterThan(CheckoutService::SESSION_MINUTES);
});

test('cancelOrder cancels a pending order and returns true', function () {
    $order = Order::factory()->for($this->event)->create();

    expect(app(OrderService::class)->cancelOrder($order))->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Cancelled);
});

test('cancelOrder does not touch paid or already cancelled orders and returns false', function () {
    $paid = Order::factory()->paid()->for($this->event)->create();
    $cancelled = Order::factory()->for($this->event)->create(['status' => OrderStatus::Cancelled]);

    expect(app(OrderService::class)->cancelOrder($paid))->toBeFalse()
        ->and($paid->status)->toBe(OrderStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull()
        ->and(app(OrderService::class)->cancelOrder($cancelled))->toBeFalse()
        ->and($cancelled->status)->toBe(OrderStatus::Cancelled);
});

test('cancelOrder only affects the given order', function () {
    $order = Order::factory()->for($this->event)->create();
    $other = Order::factory()->for($this->event)->create();

    app(OrderService::class)->cancelOrder($order);

    expect($other->refresh()->status)->toBe(OrderStatus::Pending);
});

test('another user cannot cancel an order through Livewire and the order stays pending', function () {
    $order = Order::factory()->for($this->event)->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::orders.cancel', ['order' => $order])
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('opening the cancel screen twice does not fail and reports it as not cancellable', function () {
    $order = Order::factory()->for($this->event)->create();

    Livewire::actingAs($order->user)->test('pages::orders.cancel', ['order' => $order])
        ->assertSee('お支払いを中断しました');

    Livewire::actingAs($order->user)->test('pages::orders.cancel', ['order' => $order])
        ->assertSee('この注文はキャンセルできません');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});
