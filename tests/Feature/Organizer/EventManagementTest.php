<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

test('organizers see only their own events on the index page', function () {
    $organizer = User::factory()->organizer()->create();
    $ownEvent = Event::factory()->for($organizer, 'organizer')->create(['title' => '自分のイベント']);
    Event::factory()->create(['title' => '他人のイベント']);

    $this->actingAs($organizer)
        ->get(route('organizer.events.index'))
        ->assertOk()
        ->assertSee($ownEvent->title)
        ->assertDontSee('他人のイベント');
});

test('customers cannot open the organizer pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('organizer.events.index'))
        ->assertForbidden();
});

test('guests are redirected to the login page', function () {
    $this->get(route('organizer.events.index'))->assertRedirect(route('login'));
});

test('organizers can create an event as a draft', function () {
    $organizer = User::factory()->organizer()->create();

    Livewire::actingAs($organizer)
        ->test('pages::organizer.events.create')
        ->set('title', '春の音楽祭')
        ->set('description', '地域の音楽祭です。')
        ->set('venue', '市民ホール')
        ->set('starts_at', '2026-12-01T18:00')
        ->set('ends_at', '2026-12-01T20:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::sole();

    expect($event->organizer_id)->toBe($organizer->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->title)->toBe('春の音楽祭');
});

test('event fields are validated', function (array $input, array $errors) {
    Livewire::actingAs(User::factory()->organizer()->create())
        ->test('pages::organizer.events.create')
        ->set([
            'title' => 'タイトル',
            'description' => '説明',
            'venue' => '会場',
            'starts_at' => '2026-12-01T18:00',
            'ends_at' => '2026-12-01T20:00',
            ...$input,
        ])
        ->call('save')
        ->assertHasErrors($errors);

    expect(Event::count())->toBe(0);
})->with([
    'required fields' => [
        ['title' => '', 'description' => '', 'venue' => '', 'starts_at' => '', 'ends_at' => ''],
        ['title' => 'required', 'description' => 'required', 'venue' => 'required', 'starts_at' => 'required', 'ends_at' => 'required'],
    ],
    'too long title and venue' => [
        ['title' => str_repeat('あ', 101), 'venue' => str_repeat('あ', 101)],
        ['title' => 'max', 'venue' => 'max'],
    ],
    'end not after start' => [
        ['ends_at' => '2026-12-01T18:00'],
        ['ends_at' => 'after'],
    ],
    'end before start' => [
        ['ends_at' => '2026-12-01T17:00'],
        ['ends_at' => 'after'],
    ],
]);

test('customers cannot open the create page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('organizer.events.create'))
        ->assertForbidden();
});

test('organizers cannot open the edit page of another organizer\'s event', function () {
    $this->actingAs(User::factory()->organizer()->create())
        ->get(route('organizer.events.edit', Event::factory()->create()))
        ->assertForbidden();
});

test('organizers can update their event', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('title', '新しいタイトル')
        ->call('save')
        ->assertHasNoErrors();

    expect($event->refresh()->title)->toBe('新しいタイトル');
});

test('organizers can publish and unpublish their event', function () {
    $event = Event::factory()->has(TicketType::factory())->create();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish');

    expect($event->refresh()->status)->toBe(EventStatus::Published);

    $component->call('togglePublish');

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});

test('events without ticket types cannot be published', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish')
        ->assertOk();

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});

test('organizers cannot publish an event unpublished by an admin', function () {
    $event = Event::factory()->unpublished()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish')
        ->assertForbidden();

    expect($event->refresh()->status)->toBe(EventStatus::Unpublished);
});

test('organizers can delete their event', function () {
    $event = Event::factory()->create();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertRedirect(route('organizer.events.index'));

    expect($event->fresh())->toBeNull();
});

test('events with orders cannot be deleted whatever the order status', function (OrderStatus $status) {
    $item = OrderItem::factory()->for(Order::factory()->state(['status' => $status]))->create();
    $event = $item->order->event;

    expect(Gate::forUser($event->organizer)->allows('delete', $event))->toBeFalse();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->assertSee('注文があるため、このイベントは削除できません。')
        ->assertDontSee('イベントを削除')
        ->call('deleteEvent')
        ->assertForbidden();

    expect($event->fresh())->not->toBeNull();
})->with([OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Cancelled, OrderStatus::Refunded]);

test('an order created just before deleting keeps the event and shows a message', function () {
    $event = Event::factory()->create();

    Event::deleting(fn (Event $deleting) => Order::factory()->for($deleting)->create());

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertOk()
        ->assertNoRedirect();

    expect($event->fresh())->not->toBeNull();
});

test('deleting an event already deleted in another tab does not redirect', function () {
    $event = Event::factory()->create();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event]);

    $event->delete();

    $component->call('deleteEvent')->assertNoRedirect();
});

test('actions are authorized again after the page was opened', function (string $method) {
    $event = Event::factory()->create(['title' => '元のタイトル']);

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('title', '書き換え');

    $this->actingAs(User::factory()->organizer()->create());

    $component->call($method)->assertForbidden();

    $event->refresh();

    expect($event->title)->toBe('元のタイトル')
        ->and($event->status)->toBe(EventStatus::Draft);
})->with(['save', 'togglePublish', 'deleteEvent']);
