<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

test('guests can view a published event with its ticket types', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 50]);
    OrderItem::factory()
        ->for(Order::factory()->paid()->for($event))
        ->for($ticketType)
        ->create(['quantity' => 10]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee($event->title)
        ->assertSee($event->venue)
        ->assertSee($event->organizer->name)
        ->assertSee('一般')
        ->assertSee('3,000円')
        ->assertSee('40枚');
});

test('sold out ticket types are marked', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 2]);
    OrderItem::factory()
        ->for(Order::factory()->paid()->for($event))
        ->for($ticketType)
        ->create(['quantity' => 2]);

    $this->get(route('events.show', $event))->assertSee('売り切れ');
});

test('draft events are hidden from guests and other users', function (?Closure $makeUser) {
    $event = Event::factory()->create();

    if ($makeUser !== null) {
        $this->actingAs($makeUser());
    }

    $this->get(route('events.show', $event))->assertNotFound();
})->with([
    'guest' => [null],
    'other organizer' => [fn () => User::factory()->organizer()->create()],
    'customer' => [fn () => User::factory()->create()],
]);

test('draft events are visible to the owner and admins', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer)
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('下書き');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('events.show', $event))
        ->assertOk();
});

test('unpublished events are hidden from guests, customers and other organizers', function (?Closure $makeUser) {
    $event = Event::factory()->unpublished()->create();

    if ($makeUser !== null) {
        $this->actingAs($makeUser());
    }

    $this->get(route('events.show', $event))->assertNotFound();
})->with([
    'guest' => [null],
    'customer' => [fn () => User::factory()->create()],
    'other organizer' => [fn () => User::factory()->organizer()->create()],
]);

test('unpublished events are visible to the owner and admins', function () {
    $event = Event::factory()->unpublished()->create();

    $this->actingAs($event->organizer)
        ->get(route('events.show', $event))
        ->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('events.show', $event))
        ->assertOk();
});
