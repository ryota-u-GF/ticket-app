<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;

test('new orders are pending and cast the status to the enum', function () {
    $order = Order::factory()->create();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->isPaid())->toBeFalse()
        ->and(Order::factory()->paid()->create()->isPaid())->toBeTrue();
});

test('ticket types with orders cannot be deleted', function () {
    $item = OrderItem::factory()->create();

    expect(fn () => $item->ticketType->delete())->toThrow(QueryException::class);
});

test('deleting an order also deletes its items', function () {
    $item = OrderItem::factory()->create();

    $item->order->delete();

    expect(OrderItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

test('the same ticket type cannot appear twice in one order', function () {
    $item = OrderItem::factory()->create();

    expect(fn () => OrderItem::factory()->create([
        'order_id' => $item->order_id,
        'ticket_type_id' => $item->ticket_type_id,
    ]))->toThrow(QueryException::class);
});
