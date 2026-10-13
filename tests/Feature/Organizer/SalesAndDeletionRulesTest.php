<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

test('paid orders are listed newest payment first and the 11th goes to page 2', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 100]);

    foreach (range(1, 11) as $i) {
        salesOrder($event, $ticketType, 1, 1000, buyerName: sprintf('購入者%02d', $i), paidAt: now()->subHours(12 - $i));
    }

    // 購入者11 が最新、購入者01 が最古（11件目）
    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSeeInOrder(['購入者11', '購入者10', '購入者02'])
        ->assertDontSee('購入者01')
        ->call('gotoPage', 2)
        ->assertSee('購入者01')
        ->assertDontSee('購入者11')
        ->assertDontSee('購入者02');
});

test('pending, cancelled and refunded orders are not listed', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    salesOrder($event, $ticketType, 1, 1000, buyerName: '支払い済みさん');
    salesOrder($event, $ticketType, 1, 1000, OrderStatus::Pending, '未払いさん');
    salesOrder($event, $ticketType, 1, 1000, OrderStatus::Cancelled, 'キャンセルさん');
    salesOrder($event, $ticketType, 1, 1000, OrderStatus::Refunded, '返金さん');

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSee('支払い済みさん')
        ->assertDontSee('未払いさん')
        ->assertDontSee('キャンセルさん')
        ->assertDontSee('返金さん')
        ->assertSet('totals', ['amount' => 1000, 'quantity' => 1, 'orders' => 1]);
});

test('ticket type table shows zero sales and sums unit_price times quantity', function () {
    $event = Event::factory()->published()->create();
    $general = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 5000, 'capacity' => 50]);
    $student = TicketType::factory()->for($event)->create(['name' => '学生', 'price' => 1000, 'capacity' => 30]);
    TicketType::factory()->for($event)->create(['name' => '未販売券', 'price' => 800, 'capacity' => 20]);

    // 注文後に価格が変わっても、注文時の unit_price で集計される
    salesOrder($event, $general, 2, 3000);
    salesOrder($event, $general, 1, 3500);
    salesOrder($event, $student, 4, 700);
    salesOrder($event, $general, 5, 3000, OrderStatus::Pending);

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event]);

    expect($component->get('ticketTypeSales')->all())->toBe([
        ['name' => '一般', 'capacity' => 50, 'sold' => 3, 'amount' => 9500],
        ['name' => '学生', 'capacity' => 30, 'sold' => 4, 'amount' => 2800],
        ['name' => '未販売券', 'capacity' => 20, 'sold' => 0, 'amount' => 0],
    ]);

    $component
        ->assertSet('totals', ['amount' => 12300, 'quantity' => 7, 'orders' => 3])
        ->assertSee('3枚 / 50枚')
        ->assertSee('9,500円')
        ->assertSee('4枚 / 30枚')
        ->assertSee('2,800円')
        ->assertSee('0枚 / 20枚');
});

test('an event without orders shows zeros and the empty message', function () {
    $event = Event::factory()->published()->create();
    TicketType::factory()->for($event)->create(['name' => '一般', 'capacity' => 40]);

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSet('totals', ['amount' => 0, 'quantity' => 0, 'orders' => 0])
        ->assertSeeInOrder(['売上合計', '0円', '販売枚数', '0枚', '注文数', '0件'])
        ->assertSee('0枚 / 40枚')
        ->assertSee('支払い済みの注文はまだありません。');
});

test('the detail modal does not reveal ticket codes', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    $order = salesOrder($event, $ticketType, 2, 3000);
    $item = $order->items()->firstOrFail();
    $codes = collect([
        Ticket::factory()->create(['order_item_id' => $item->id]),
        Ticket::factory()->create(['order_item_id' => $item->id]),
    ])->pluck('code');

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->call('showDetail', $order->id)
        ->assertSee('注文番号: '.$order->id)
        ->assertSee('6,000円');

    foreach ($codes as $code) {
        $component->assertDontSee($code);
    }
});

test('delete policy allows only the owner and only without any orders', function (string $actor, ?OrderStatus $orderStatus, bool $expected) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    if ($orderStatus) {
        salesOrder($event, $ticketType, 1, 1000, $orderStatus);
    }

    $user = match ($actor) {
        'owner' => $event->organizer,
        'other organizer' => User::factory()->organizer()->create(),
        'admin' => User::factory()->admin()->create(),
        'customer' => User::factory()->create(),
    };

    expect(Gate::forUser($user)->allows('delete', $event))->toBe($expected);
})->with([
    'owner, no orders' => ['owner', null, true],
    'owner, pending only' => ['owner', OrderStatus::Pending, false],
    'owner, cancelled only' => ['owner', OrderStatus::Cancelled, false],
    'owner, paid' => ['owner', OrderStatus::Paid, false],
    'other organizer, no orders' => ['other organizer', null, false],
    'other organizer, paid' => ['other organizer', OrderStatus::Paid, false],
    'admin, no orders' => ['admin', null, false],
    'admin, paid' => ['admin', OrderStatus::Paid, false],
    'customer, no orders' => ['customer', null, false],
    'customer, paid' => ['customer', OrderStatus::Paid, false],
]);

test('viewOrders policy allows the owner and admins regardless of orders', function (string $actor, bool $withPaidOrder, bool $expected) {
    $event = Event::factory()->published()->create();
    if ($withPaidOrder) {
        salesOrder($event, TicketType::factory()->for($event)->create(), 1, 1000);
    }

    $user = match ($actor) {
        'owner' => $event->organizer,
        'other organizer' => User::factory()->organizer()->create(),
        'admin' => User::factory()->admin()->create(),
        'customer' => User::factory()->create(),
    };

    expect(Gate::forUser($user)->allows('viewOrders', $event))->toBe($expected);
})->with([
    'owner, no orders' => ['owner', false, true],
    'owner, paid' => ['owner', true, true],
    'admin, no orders' => ['admin', false, true],
    'admin, paid' => ['admin', true, true],
    'other organizer' => ['other organizer', true, false],
    'customer' => ['customer', true, false],
]);

test('an invalid order id in the detail modal is rejected', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create();
    $pending = salesOrder($event, $ticketType, 1, 1000, OrderStatus::Pending);

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->call('showDetail', $pending->id)
        ->assertNotFound();

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->call('showDetail', 999999)
        ->assertNotFound();
});

test('the event list sums sales per event with a fixed number of queries', function () {
    $organizer = User::factory()->organizer()->create();
    foreach ([1, 2, 3] as $count) {
        $event = Event::factory()->published()->for($organizer, 'organizer')->create();
        $ticketType = TicketType::factory()->for($event)->create();
        salesOrder($event, $ticketType, $count, 1000);
    }

    DB::enableQueryLog();
    $component = Livewire::actingAs($organizer)->test('pages::organizer.events.index');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $events = $component->get('events');

    expect($events->pluck('sold_quantity')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([1, 2, 3])
        ->and($events->pluck('sales_amount')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([1000, 2000, 3000])
        ->and($queryCount)->toBeLessThan(10);
});

test('sales are the sum of unit_price times quantity, not the order total_amount', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
    salesOrder($event, $ticketType, 2, 3000)->update(['total_amount' => 999]);

    expect($event->salesAmount())->toBe(6000);

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSet('totals.amount', 6000)
        ->assertSet('ticketTypeSales.0.amount', 6000);
});

test('admins see a back link to the event instead of the organizer menu', function () {
    $event = Event::factory()->published()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSee('イベントへ戻る')
        ->assertDontSee('イベント管理へ戻る');

    Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.orders', ['event' => $event])
        ->assertSee('イベント管理へ戻る');
});
