<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;

/**
 * Create an order item of the given status and quantity for the ticket type.
 */
function orderTickets(TicketType $ticketType, OrderStatus $status, int $quantity): OrderItem
{
    return OrderItem::factory()
        ->for(Order::factory()->for($ticketType->event)->state(['status' => $status]))
        ->for($ticketType)
        ->create(['quantity' => $quantity]);
}

test('paid and recent pending orders reduce the remaining count', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 50]);

    orderTickets($ticketType, OrderStatus::Paid, 10);
    orderTickets($ticketType, OrderStatus::Pending, 3);

    expect($ticketType->remainingCount())->toBe(37);
});

test('pending orders older than the pending minutes are not counted', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 50]);

    orderTickets($ticketType, OrderStatus::Pending, 5);

    $this->travel(TicketType::PENDING_MINUTES - 1)->minutes();
    expect($ticketType->remainingCount())->toBe(45);

    $this->travel(2)->minutes();
    expect($ticketType->remainingCount())->toBe(50);
});

test('cancelled and refunded orders are not counted', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 50]);

    orderTickets($ticketType, OrderStatus::Cancelled, 5);
    orderTickets($ticketType, OrderStatus::Refunded, 5);

    expect($ticketType->remainingCount())->toBe(50);
});

test('the remaining count never goes below zero', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 5]);

    orderTickets($ticketType, OrderStatus::Paid, 8);

    expect($ticketType->remainingCount())->toBe(0);
});

test('a pending order is still counted exactly at the pending minutes and released right after', function () {
    $this->freezeTime();
    $ticketType = TicketType::factory()->create(['capacity' => 50]);

    orderTickets($ticketType, OrderStatus::Pending, 5);

    $this->travel(TicketType::PENDING_MINUTES)->minutes();
    expect($ticketType->remainingCount())->toBe(45);

    $this->travel(1)->seconds();
    expect($ticketType->remainingCount())->toBe(50);
});

test('orders of other ticket types are not counted', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 50]);
    $otherTicketType = TicketType::factory()->for($ticketType->event)->create(['capacity' => 50]);

    orderTickets($otherTicketType, OrderStatus::Paid, 20);

    expect($ticketType->remainingCount())->toBe(50);
});

test('quantities across several orders are summed and can sell out exactly', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);

    orderTickets($ticketType, OrderStatus::Paid, 4);
    orderTickets($ticketType, OrderStatus::Paid, 3);
    orderTickets($ticketType, OrderStatus::Pending, 3);

    expect($ticketType->remainingCount())->toBe(0);
});
