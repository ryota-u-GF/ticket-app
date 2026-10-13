<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a published event with a ticket type and one paid order of 2 tickets.
 *
 * @return array{0: Event, 1: TicketType, 2: Order}
 */
function eventWithPaidOrder(string $name = '山田太郎', string $email = 'taro@example.com'): array
{
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'capacity' => 50]);
    $buyer = User::factory()->create(['name' => $name, 'email' => $email]);
    $order = Order::factory()->paid()->for($event)->for($buyer)->create(['total_amount' => 6000]);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 2, 'unit_price' => 3000]);

    return [$event, $ticketType, $order];
}

/**
 * Create an order of one ticket type with its item.
 */
function salesOrder(Event $event, TicketType $ticketType, int $quantity, int $unitPrice, ?OrderStatus $status = null, ?string $buyerName = null, ?CarbonInterface $paidAt = null): Order
{
    $buyer = User::factory()->create($buyerName ? ['name' => $buyerName] : []);
    $order = Order::factory()->for($event)->for($buyer)->create([
        'status' => $status ?? OrderStatus::Paid,
        'paid_at' => ($status ?? OrderStatus::Paid) === OrderStatus::Paid ? ($paidAt ?? now()) : null,
        'total_amount' => $quantity * $unitPrice,
    ]);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity, 'unit_price' => $unitPrice]);

    return $order;
}
