<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
    $this->get(route('orders.show', Order::factory()->create()))->assertRedirect(route('login'));
});

test('only my own orders are listed, newest first', function () {
    $user = User::factory()->create();
    $older = Order::factory()->for($user)->for(Event::factory()->published()->create(['title' => '古い注文のイベント']))->create(['created_at' => now()->subDay()]);
    $newer = Order::factory()->for($user)->for(Event::factory()->published()->create(['title' => '新しい注文のイベント']))->create();
    Order::factory()->for(Event::factory()->published()->create(['title' => '他人のイベント']))->create();
    OrderItem::factory()->for($newer)->create(['quantity' => 3]);

    Livewire::actingAs($user)
        ->test('pages::orders.index')
        ->assertSeeInOrder(['新しい注文のイベント', '古い注文のイベント'])
        ->assertDontSee('他人のイベント')
        ->assertSee('3枚')
        ->assertSee('支払い待ち');
});

test('orders are paginated by 10', function () {
    $user = User::factory()->create();
    Order::factory()->count(11)->for($user)->create();

    $component = Livewire::actingAs($user)->test('pages::orders.index');

    expect($component->get('orders')->count())->toBe(10)
        ->and($component->get('orders')->total())->toBe(11);
});

test('the owner sees a paid order with its tickets', function () {
    $order = Order::factory()->paid()->create();
    $ticketType = TicketType::factory()->for($order->event)->create(['name' => '一般']);
    $item = OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 1, 'unit_price' => 3000]);
    $ticket = Ticket::factory()->for($item)->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('一般')
        ->assertSee('3,000円')
        ->assertSee($ticket->code)
        ->assertDontSee('支払いが完了していません');
});

test('a pending order shows the unpaid notice and no tickets', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);
    $ticket = Ticket::factory()->for(OrderItem::factory()->for($order))->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('支払いが完了していません')
        ->assertDontSee($ticket->code);
});

test('another user cannot view the order', function () {
    $order = Order::factory()->paid()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order))
        ->assertForbidden();
});

test('an empty list shows the empty message', function () {
    Order::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::orders.index')
        ->assertSee('購入した注文はまだありません。');
});

test('each row links to the order detail', function () {
    $order = Order::factory()->create();

    Livewire::actingAs($order->user)
        ->test('pages::orders.index')
        ->assertSeeHtml('href="'.route('orders.show', $order).'"');
});

test('the second page shows the oldest order', function () {
    $user = User::factory()->create();
    Order::factory()->for($user)->for(Event::factory()->published()->create(['title' => '一番古いイベント']))->create(['created_at' => now()->subDays(5)]);
    Order::factory()->count(10)->for($user)->create();

    $component = Livewire::actingAs($user)->test('pages::orders.index');
    $component->assertDontSee('一番古いイベント');

    $component->call('gotoPage', 2)->assertSee('一番古いイベント');
});

test('cancelled and refunded orders show their status without tickets', function (OrderStatus $status) {
    $order = Order::factory()->create(['status' => $status]);
    $ticket = Ticket::factory()->for(OrderItem::factory()->for($order))->create();

    $this->actingAs($order->user)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee($status->label())
        ->assertDontSee($ticket->code);
})->with([OrderStatus::Cancelled, OrderStatus::Refunded]);

test('the organizer of the event and admins cannot see the buyer tickets', function () {
    $order = Order::factory()->paid()->create();
    Ticket::factory()->for(OrderItem::factory()->for($order))->create();

    $this->actingAs($order->event->organizer)->get(route('orders.show', $order))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('orders.show', $order))->assertForbidden();
    $this->actingAs(User::factory()->organizer()->create())->get(route('orders.show', $order))->assertForbidden();
});
