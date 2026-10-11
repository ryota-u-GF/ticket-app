<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The ticket type is created for the same event as the order.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'ticket_type_id' => fn (array $attributes) => TicketType::factory()->create([
                'event_id' => Order::query()->whereKey($attributes['order_id'])->value('event_id'),
            ]),
            'quantity' => fake()->numberBetween(1, 4),
            'unit_price' => fake()->numberBetween(5, 50) * 100,
        ];
    }
}
