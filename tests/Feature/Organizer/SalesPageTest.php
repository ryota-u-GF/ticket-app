<?php

use App\Models\User;
use Livewire\Livewire;

test('the owner sees totals, ticket type sales and paid orders', function () {
    [$event] = eventWithPaidOrder();

    $this->actingAs($event->organizer)
        ->get(route('organizer.events.orders', $event))
        ->assertOk()
        ->assertSee('山田太郎')
        ->assertSee('taro@example.com')
        ->assertSee('6,000円')
        ->assertSee('2枚 / 50枚');
});

test('another organizer cannot see the sales', function () {
    [$event] = eventWithPaidOrder();

    $this->actingAs(User::factory()->organizer()->create())
        ->get(route('organizer.events.orders', $event))
        ->assertForbidden();
});

test('customers and guests cannot see the sales', function () {
    [$event] = eventWithPaidOrder();

    $this->actingAs(User::factory()->create())->get(route('organizer.events.orders', $event))->assertForbidden();
    auth()->logout();
    $this->get(route('organizer.events.orders', $event))->assertRedirect(route('login'));
});

test('admins can see the sales', function () {
    [$event] = eventWithPaidOrder();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('organizer.events.orders', $event))
        ->assertOk();
});

test('sales of other events are not counted', function () {
    [$event] = eventWithPaidOrder();
    eventWithPaidOrder('別の人', 'other@example.com');

    expect($event->soldQuantity())->toBe(2)->and($event->salesAmount())->toBe(6000);
});

test('the detail modal shows the order but only of this event', function () {
    [$event, , $order] = eventWithPaidOrder();
    [, , $otherOrder] = eventWithPaidOrder('別の人', 'other@example.com');

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->call('showDetail', $order->id)
        ->assertSet('detailOrderId', $order->id)
        ->assertSee('注文番号: '.$order->id)
        ->call('showDetail', $otherOrder->id)
        ->assertNotFound();
});

test('the event list shows sold quantity and sales per event', function () {
    [$event] = eventWithPaidOrder();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.index')
        ->assertSet('events', fn ($events) => $events->first()->sold_quantity == 2 && $events->first()->sales_amount == 6000);
});

test('sales page actions are authorized again after the page was opened', function (string $method) {
    [$event, , $order] = eventWithPaidOrder();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    $this->actingAs(User::factory()->organizer()->create());

    $component->call($method, $method === 'showDetail' ? $order->id : 2)->assertForbidden();
})->with(['showDetail', 'gotoPage']);
