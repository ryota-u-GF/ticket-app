<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;

beforeEach(function () {
    $this->event = Event::factory()->published()->create();
    $this->ticketType = TicketType::factory()->for($this->event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 50]);
});

/**
 * Get the option values of the quantity select for the ticket type.
 *
 * @return list<int>
 */
function selectableQuantities(string $html, TicketType $ticketType): array
{
    preg_match('/<select[^>]*name="quantities\.'.$ticketType->id.'".*?<\/select>/s', $html, $select);
    preg_match_all('/<option[^>]*value="(\d+)"/', $select[0] ?? '', $options);

    return array_map('intval', $options[1]);
}

test('the selectable quantity is limited by the maximum and the remaining count', function () {
    $scarce = TicketType::factory()->for($this->event)->create(['name' => '学生', 'capacity' => 3]);

    $html = Livewire::test('pages::events.show', ['event' => $this->event])->html();

    expect(selectableQuantities($html, $this->ticketType))->toBe(range(0, 10))
        ->and(selectableQuantities($html, $scarce))->toBe(range(0, 3));
});

test('ticket types that are sold out or out of the sales period cannot be selected', function () {
    $soldOut = TicketType::factory()->for($this->event)->create(['name' => '完売券', 'capacity' => 1]);
    OrderItem::factory()->for(Order::factory()->paid()->for($this->event))->for($soldOut)->create(['quantity' => 1]);
    $expired = TicketType::factory()->for($this->event)->create([
        'name' => '期間外券',
        'sales_starts_at' => now()->subWeek(),
        'sales_ends_at' => now()->subDay(),
    ]);

    $component = Livewire::test('pages::events.show', ['event' => $this->event]);

    expect(selectableQuantities($component->html(), $soldOut))->toBe([])
        ->and(selectableQuantities($component->html(), $expired))->toBe([]);
    $component->assertSee('売り切れ')->assertSee('販売期間外');
});

test('proceeding saves the selection in the session and goes to the purchase screen', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->ticketType->id}", '2')
        ->call('proceed')
        ->assertRedirect(route('events.purchase', $this->event));

    expect(session("purchase.{$this->event->id}"))->toBe([$this->ticketType->id => 2]);
});

test('guests are sent to login and come back to the purchase screen', function () {
    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->ticketType->id}", '1')
        ->call('proceed')
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('events.purchase', $this->event))
        ->and(session("purchase.{$this->event->id}"))->toBe([$this->ticketType->id => 1]);
});

test('proceeding without tickets or with an invalid quantity saves nothing', function (array $quantities, string $errorKey, string $message) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set('quantities', array_combine(
            array_map(fn ($key) => $key === 'ticket' ? $this->ticketType->id : $key, array_keys($quantities)),
            $quantities,
        ))
        ->call('proceed')
        ->assertHasErrors([$errorKey])
        ->assertSee($message)
        ->assertNoRedirect();

    expect(session()->has("purchase.{$this->event->id}"))->toBeFalse()
        ->and(Order::count())->toBe(0);
})->with([
    'nothing selected' => [['ticket' => '0'], 'quantities', 'チケットを選択してください。'],
    'over the maximum' => [['ticket' => '11'], 'quantities.*', '枚'],
]);

test('the purchase screen requires login', function () {
    $this->get(route('events.purchase', $this->event))->assertRedirect(route('login'));
});

test('the purchase screen goes back to the event when nothing is selected', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->assertRedirect(route('events.show', $this->event));
});

test('draft events cannot be purchased', function () {
    $draft = Event::factory()->create();

    $this->actingAs($draft->organizer)
        ->withSession(["purchase.{$draft->id}" => [1 => 1]])
        ->get(route('events.purchase', $draft))
        ->assertNotFound();
});

test('the purchase screen shows quantities, subtotals and the total', function () {
    $student = TicketType::factory()->for($this->event)->create(['name' => '学生', 'price' => 1500]);

    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 2, $student->id => 1]]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->assertSee('一般')
        ->assertSee('2枚')
        ->assertSee('6,000円')
        ->assertSee('1,500円')
        ->assertSee('合計 7,500円');
});

test('paying creates the order and redirects to the Stripe payment page', function () {
    $user = User::factory()->create();
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 2]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->once()
        ->with(Mockery::on(fn (Order $order) => $order->total_amount === 6000))
        ->andReturn('https://checkout.stripe.com/c/pay/test');

    Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect('https://checkout.stripe.com/c/pay/test');

    $order = Order::first();
    expect($order->user_id)->toBe($user->id)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->total_amount)->toBe(6000)
        ->and($order->items)->toHaveCount(1)
        ->and(session()->has("purchase.{$this->event->id}"))->toBeFalse();
});

test('a free order skips Stripe and goes to the completion screen as pending', function () {
    $free = TicketType::factory()->for($this->event)->create(['name' => '無料', 'price' => 0]);
    session(["purchase.{$this->event->id}" => [$free->id => 1]]);

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertRedirect(route('orders.complete', Order::first()));

    expect(Order::first()->status)->toBe(OrderStatus::Pending);
});

test('when Stripe fails the order is cancelled and an error is shown', function () {
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 1]]);

    $this->mock(CheckoutService::class)
        ->shouldReceive('createSession')
        ->andThrow(new ApiConnectionException('boom'));

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertNoRedirect();

    expect(Order::first()->status)->toBe(OrderStatus::Cancelled);
});

test('cancelling frees the tickets and marks the order as cancelled', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->for($this->ticketType)->create(['quantity' => 5]);

    expect($this->ticketType->remainingCount())->toBe(45);

    Livewire::actingAs($order->user)
        ->test('pages::orders.cancel', ['order' => $order])
        ->assertSee('お支払いを中断しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->ticketType->remainingCount())->toBe(50);
});

test('a paid order is not cancelled from the cancel screen', function () {
    $order = Order::factory()->paid()->for($this->event)->create();

    Livewire::actingAs($order->user)
        ->test('pages::orders.cancel', ['order' => $order])
        ->assertSee('この注文はキャンセルできません');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

test('only the buyer can open the cancel screen', function () {
    $order = Order::factory()->for($this->event)->create();

    $this->actingAs(User::factory()->create())->get(route('orders.cancel', $order))->assertForbidden();
    $this->actingAs($this->event->organizer)->get(route('orders.cancel', $order))->assertForbidden();
    $this->actingAs($order->user)->get(route('orders.cancel', $order))->assertOk();

    auth()->logout();
    $this->get(route('orders.cancel', $order))->assertRedirect(route('login'));
});

test('paying is refused once the event is no longer published', function () {
    $user = User::factory()->create();
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 1]]);

    $component = Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $this->event]);

    $this->event->update(['status' => EventStatus::Unpublished]);

    $component->call('pay')->assertNotFound();

    expect(Order::count())->toBe(0);
});

test('purchase errors are shown and no order is created', function () {
    $scarce = TicketType::factory()->for($this->event)->create(['name' => '学生', 'capacity' => 1]);

    session(["purchase.{$this->event->id}" => [$scarce->id => 2]]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertSee('「学生」の残りは1枚です。')
        ->assertNoRedirect();

    expect(Order::count())->toBe(0)
        ->and(session()->has("purchase.{$this->event->id}"))->toBeTrue();
});

test('the completion screen can be opened by the buyer, the organizer and admins only', function () {
    $order = Order::factory()->for($this->event)->create();

    foreach ([$order->user, $this->event->organizer, User::factory()->admin()->create()] as $user) {
        $this->actingAs($user)->get(route('orders.complete', $order))
            ->assertOk()
            ->assertSee('注文を受け付けました');
    }

    $this->actingAs(User::factory()->create())->get(route('orders.complete', $order))->assertForbidden();
    $this->actingAs(User::factory()->organizer()->create())->get(route('orders.complete', $order))->assertForbidden();
});

test('paying with a tampered quantity in the session shows an error and creates no order', function () {
    session(["purchase.{$this->event->id}" => [$this->ticketType->id => 11]]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::events.purchase', ['event' => $this->event])
        ->call('pay')
        ->assertHasErrors('purchase')
        ->assertSee('枚数は1枚以上10枚以下で選んでください。')
        ->assertNoRedirect();

    expect(Order::count())->toBe(0);
});

test('the completion screen of another user is forbidden through Livewire as well', function () {
    $order = Order::factory()->for($this->event)->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::orders.complete', ['order' => $order])
        ->assertForbidden();
});

test('guests are sent to login from the completion screen', function () {
    $order = Order::factory()->for($this->event)->create();

    $this->get(route('orders.complete', $order))->assertRedirect(route('login'));
});

test('unpublished and draft events cannot be proceeded with or purchased', function (Closure $makeEvent) {
    $event = $makeEvent();
    $ticketType = TicketType::factory()->for($event)->create();
    session(["purchase.{$event->id}" => [$ticketType->id => 1]]);

    Livewire::actingAs($event->organizer)
        ->test('pages::events.show', ['event' => $event])
        ->set("quantities.{$ticketType->id}", '1')
        ->call('proceed')
        ->assertNotFound();

    $this->actingAs($event->organizer)->get(route('events.purchase', $event))->assertNotFound();
    expect(Order::count())->toBe(0);
})->with([
    'unpublished' => [fn () => Event::factory()->unpublished()->create()],
    'draft' => [fn () => Event::factory()->create()],
]);
