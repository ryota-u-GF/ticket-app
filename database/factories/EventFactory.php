<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 week', '+3 months');

        return [
            'organizer_id' => User::factory()->organizer(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue' => fake()->city().'公民館',
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+2 hours'),
            'status' => EventStatus::Draft,
        ];
    }

    /**
     * Indicate that the event is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Published,
        ]);
    }

    /**
     * Indicate that the event was unpublished by an admin.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Unpublished,
        ]);
    }
}
