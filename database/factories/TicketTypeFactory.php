<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => '一般',
            'price' => fake()->numberBetween(5, 50) * 100,
            'capacity' => fake()->numberBetween(10, 200),
            'sales_starts_at' => now()->subDay(),
            'sales_ends_at' => now()->addWeek(),
        ];
    }
}
