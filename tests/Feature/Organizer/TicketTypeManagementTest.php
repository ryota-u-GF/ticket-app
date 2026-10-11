<?php

use App\Models\Event;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('organizers can add a ticket type to their event', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticket_name', '一般')
        ->set('ticket_price', '3000')
        ->set('ticket_capacity', '50')
        ->set('ticket_sales_starts_at', '2026-11-01T10:00')
        ->set('ticket_sales_ends_at', '2026-11-30T23:59')
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('ticket_name', '');

    $ticketType = $event->ticketTypes()->sole();

    expect($ticketType->name)->toBe('一般')
        ->and($ticketType->price)->toBe(3000)
        ->and($ticketType->capacity)->toBe(50)
        ->and($ticketType->sales_ends_at->format('Y-m-d H:i'))->toBe('2026-11-30 23:59');
});

test('organizers can edit a ticket type using the same form', function () {
    $ticketType = TicketType::factory()->create(['name' => '一般', 'price' => 3000]);

    Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->call('editTicketType', $ticketType->id)
        ->assertSet('editingTicketTypeId', $ticketType->id)
        ->assertSet('ticket_name', '一般')
        ->assertSet('ticket_price', 3000)
        ->set('ticket_name', '学生')
        ->set('ticket_price', '1500')
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('editingTicketTypeId', null);

    $ticketType->refresh();

    expect($ticketType->name)->toBe('学生')
        ->and($ticketType->price)->toBe(1500)
        ->and(TicketType::count())->toBe(1);
});

test('ticket type input is validated', function (string $field, mixed $value) {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticket_name', '一般')
        ->set('ticket_price', '3000')
        ->set('ticket_capacity', '50')
        ->set('ticket_sales_starts_at', '2026-11-01T10:00')
        ->set('ticket_sales_ends_at', '2026-11-30T23:59')
        ->set($field, $value)
        ->call('saveTicketType')
        ->assertHasErrors($field);

    expect($event->ticketTypes()->exists())->toBeFalse();
})->with([
    'name is empty' => ['ticket_name', ''],
    'name too long' => ['ticket_name', str_repeat('あ', 51)],
    'price is zero' => ['ticket_price', '0'],
    'price is negative' => ['ticket_price', '-1'],
    'price is empty' => ['ticket_price', ''],
    'price is not an integer' => ['ticket_price', '100.5'],
    'price is over the limit' => ['ticket_price', '1000001'],
    'capacity is zero' => ['ticket_capacity', '0'],
    'capacity is negative' => ['ticket_capacity', '-1'],
    'capacity is not an integer' => ['ticket_capacity', '1.5'],
    'capacity is over the limit' => ['ticket_capacity', '100001'],
    'sales start is empty' => ['ticket_sales_starts_at', ''],
    'sales end is empty' => ['ticket_sales_ends_at', ''],
    'sales end before start' => ['ticket_sales_ends_at', '2026-11-01T10:00'],
]);

test('price and capacity accept their upper limits', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticket_name', '一般')
        ->set('ticket_price', '1000000')
        ->set('ticket_capacity', '100000')
        ->set('ticket_sales_starts_at', '2026-11-01T10:00')
        ->set('ticket_sales_ends_at', '2026-11-30T23:59')
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($event->ticketTypes()->sole()->price)->toBe(1000000);
});

test('the ticket type being edited cannot be switched from the browser', function () {
    $event = Event::factory()->create();
    $otherTicketType = TicketType::factory()->create(['name' => '他人の券種']);

    expect(fn () => Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('editingTicketTypeId', $otherTicketType->id)
    )->toThrow(CannotUpdateLockedPropertyException::class);

    expect($otherTicketType->fresh()->name)->toBe('他人の券種');
});

test('saving a ticket type deleted in another tab clears the form', function () {
    $ticketType = TicketType::factory()->create();

    $component = Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->call('editTicketType', $ticketType->id);

    $ticketType->delete();

    $component->call('saveTicketType')
        ->assertOk()
        ->assertSet('editingTicketTypeId', null)
        ->assertSet('ticket_name', '');

    expect(TicketType::count())->toBe(0);
});

test('organizers can delete a ticket type', function () {
    $ticketType = TicketType::factory()->create();

    Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->call('deleteTicketType', $ticketType->id)
        ->assertOk();

    expect($ticketType->fresh())->toBeNull();
});

test('ticket types with orders cannot be deleted', function () {
    $ticketType = OrderItem::factory()->create()->ticketType;
    TicketType::factory()->for($ticketType->event)->create();

    Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->call('deleteTicketType', $ticketType->id)
        ->assertOk();

    expect($ticketType->fresh())->not->toBeNull();
});

test('the last ticket type of a published event cannot be deleted', function () {
    $event = Event::factory()->published()->has(TicketType::factory())->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteTicketType', $event->ticketTypes()->sole()->id)
        ->assertOk();

    expect($event->ticketTypes()->count())->toBe(1);
});

test('a published event can delete a ticket type while another remains', function () {
    $event = Event::factory()->published()->has(TicketType::factory()->count(2))->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteTicketType', $event->ticketTypes()->first()->id)
        ->assertOk();

    expect($event->ticketTypes()->count())->toBe(1);
});

test('ticket types of other events cannot be edited or deleted', function (string $method) {
    $event = Event::factory()->create();
    $otherTicketType = TicketType::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call($method, $otherTicketType->id)
        ->assertNotFound();

    expect($otherTicketType->fresh())->not->toBeNull();
})->with(['editTicketType', 'deleteTicketType']);

test('ticket type actions are authorized again after the page was opened', function (string $method, bool $passesId) {
    $ticketType = TicketType::factory()->create(['name' => '一般']);

    $component = Livewire::actingAs($ticketType->event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->set('ticket_name', '書き換え')
        ->set('ticket_price', '1000')
        ->set('ticket_capacity', '10')
        ->set('ticket_sales_starts_at', '2026-11-01T10:00')
        ->set('ticket_sales_ends_at', '2026-11-30T23:59');

    $this->actingAs(User::factory()->organizer()->create());

    $component->call($method, ...($passesId ? [$ticketType->id] : []))->assertForbidden();

    expect(TicketType::count())->toBe(1)
        ->and($ticketType->fresh()->name)->toBe('一般');
})->with([
    'save' => ['saveTicketType', false],
    'edit' => ['editTicketType', true],
    'delete' => ['deleteTicketType', true],
]);

test('other organizers cannot open the edit page of the event', function () {
    $event = Event::factory()->create();
    $otherOrganizer = User::factory()->organizer()->create();

    $this->actingAs($otherOrganizer)
        ->get(route('organizer.events.edit', $event))
        ->assertForbidden();

    Livewire::actingAs($otherOrganizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->assertForbidden();
});

test('ticket types know whether they are on sale', function () {
    $this->travelTo('2026-11-15 12:00');

    $ticketType = TicketType::factory()->make([
        'sales_starts_at' => '2026-11-01 10:00',
        'sales_ends_at' => '2026-11-30 23:59',
    ]);

    expect($ticketType->isOnSale())->toBeTrue();

    $this->travelTo('2026-11-01 10:00');
    expect($ticketType->isOnSale())->toBeTrue();

    $this->travelTo('2026-11-30 23:59');
    expect($ticketType->isOnSale())->toBeTrue();

    $this->travelTo('2026-10-31 12:00');
    expect($ticketType->isOnSale())->toBeFalse();

    $this->travelTo('2026-12-01 00:00');
    expect($ticketType->isOnSale())->toBeFalse();
});
