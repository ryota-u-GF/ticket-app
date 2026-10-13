<?php

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create();
    $this->service = app(OrderService::class);
});

/**
 * Assert that creating the order fails with the message and leaves no data behind.
 */
function expectPurchaseFailure(Closure $callback, string $message): void
{
    expect($callback)->toThrow(PurchaseException::class, $message);
    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
}

test('an order and its items are created with the correct total', function () {
    $general = TicketType::factory()->for($this->event)->create(['price' => 3000, 'capacity' => 50]);
    $student = TicketType::factory()->for($this->event)->create(['price' => 1500, 'capacity' => 50]);

    $order = $this->service->createOrder($this->user, $this->event, [
        $general->id => 2,
        $student->id => 3,
        99999 => 0,
    ]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($this->user->id)
        ->and($order->event_id)->toBe($this->event->id)
        ->and($order->total_amount)->toBe(2 * 3000 + 3 * 1500)
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->firstWhere('ticket_type_id', $general->id)->only(['quantity', 'unit_price']))
        ->toBe(['quantity' => 2, 'unit_price' => 3000]);
});

test('the unit price is not affected by later price changes', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['price' => 3000]);

    $order = $this->service->createOrder($this->user, $this->event, [$ticketType->id => 1]);
    $ticketType->update(['price' => 5000]);

    expect($order->items()->first()->unit_price)->toBe(3000);
});

test('ordering more than the remaining count fails and creates nothing', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 5]);
    OrderItem::factory()
        ->for(Order::factory()->for($this->event)->paid())
        ->for($ticketType)
        ->create(['quantity' => 3]);
    $orderCount = Order::count();

    expect(fn () => $this->service->createOrder($this->user, $this->event, [$ticketType->id => 3]))
        ->toThrow(PurchaseException::class, '残りは2枚です');
    expect(Order::count())->toBe($orderCount)
        ->and(OrderItem::count())->toBe(1);

    expect($this->service->createOrder($this->user, $this->event, [$ticketType->id => 2])->items)->toHaveCount(1);
});

test('one invalid ticket type prevents the whole order', function () {
    $available = TicketType::factory()->for($this->event)->create(['capacity' => 50]);
    $soldOut = TicketType::factory()->for($this->event)->create(['capacity' => 1]);

    expectPurchaseFailure(
        fn () => $this->service->createOrder($this->user, $this->event, [$available->id => 1, $soldOut->id => 2]),
        '残りは1枚です',
    );
});

test('ticket types outside the sales period cannot be ordered', function (string $start, string $end) {
    $ticketType = TicketType::factory()->for($this->event)->create([
        'sales_starts_at' => now()->modify($start),
        'sales_ends_at' => now()->modify($end),
    ]);

    expectPurchaseFailure(
        fn () => $this->service->createOrder($this->user, $this->event, [$ticketType->id => 1]),
        '販売期間外です',
    );
})->with([
    'before sales start' => ['+1 day', '+1 week'],
    'after sales end' => ['-1 week', '-1 day'],
]);

test('the quantity must be between 1 and the maximum', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 100]);

    expectPurchaseFailure(
        fn () => $this->service->createOrder($this->user, $this->event, [$ticketType->id => OrderService::MAX_QUANTITY + 1]),
        '1枚以上10枚以下',
    );

    $order = $this->service->createOrder($this->user, $this->event, [$ticketType->id => OrderService::MAX_QUANTITY]);
    expect($order->items->first()->quantity)->toBe(OrderService::MAX_QUANTITY);
});

test('invalid quantities are rejected', function (mixed $quantity) {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 100]);

    expect(fn () => $this->service->createOrder($this->user, $this->event, [$ticketType->id => $quantity]))
        ->toThrow(PurchaseException::class);
    expect(Order::count())->toBe(0);
})->with([-1, 1.5, 'abc', null]);

test('at least one ticket must be selected', function () {
    $ticketType = TicketType::factory()->for($this->event)->create();

    expectPurchaseFailure(
        fn () => $this->service->createOrder($this->user, $this->event, [$ticketType->id => 0]),
        'チケットを選択してください',
    );
});

test('ticket types of other events cannot be ordered', function () {
    $otherTicketType = TicketType::factory()->create();

    expectPurchaseFailure(
        fn () => $this->service->createOrder($this->user, $this->event, [$otherTicketType->id => 1]),
        '券種が見つかりません',
    );
});

test('events that are not published cannot be ordered', function () {
    $draftEvent = Event::factory()->create();
    $ticketType = TicketType::factory()->for($draftEvent)->create();

    expectPurchaseFailure(
        fn () => $this->service->createOrder($this->user, $draftEvent, [$ticketType->id => 1]),
        '購入できません',
    );
});

test('creating a new order cancels the earlier pending order of the same user and event', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 5]);

    $first = $this->service->createOrder($this->user, $this->event, [$ticketType->id => 5]);
    $second = $this->service->createOrder($this->user, $this->event, [$ticketType->id => 5]);

    expect($first->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Order::where('status', OrderStatus::Pending)->count())->toBe(1);
});

test('a failed order leaves the earlier pending order untouched', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 5]);
    $first = $this->service->createOrder($this->user, $this->event, [$ticketType->id => 2]);

    expect(fn () => $this->service->createOrder($this->user, $this->event, [$ticketType->id => 6]))
        ->toThrow(PurchaseException::class);

    expect($first->fresh()->status)->toBe(OrderStatus::Pending);
});

test('orders of other users, other events and paid orders are not cancelled', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 50]);
    $otherEventTicketType = TicketType::factory()->for(Event::factory()->published())->create(['capacity' => 50]);
    $otherUsersOrder = $this->service->createOrder(User::factory()->create(), $this->event, [$ticketType->id => 1]);
    $otherEventOrder = $this->service->createOrder($this->user, $otherEventTicketType->event, [$otherEventTicketType->id => 1]);
    $paidOrder = Order::factory()->paid()->for($this->event)->for($this->user)->create();

    $this->service->createOrder($this->user, $this->event, [$ticketType->id => 1]);

    expect($otherUsersOrder->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($otherEventOrder->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($paidOrder->fresh()->status)->toBe(OrderStatus::Paid);
});

test('markAsPaid pays only a pending order and issues the tickets once', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['quantity' => 3]);

    expect($this->service->markAsPaid($order, 'pi_test_1'))->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and(Ticket::count())->toBe(3)
        ->and($this->service->markAsPaid($order))->toBeFalse()
        ->and(Ticket::count())->toBe(3);
});

test('markAsPaid does not pay a cancelled order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    OrderItem::factory()->for($order)->create(['quantity' => 1]);

    expect($this->service->markAsPaid($order))->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        ->and(Ticket::count())->toBe(0);
});
