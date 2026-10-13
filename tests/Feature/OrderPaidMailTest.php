<?php

use App\Enums\OrderStatus;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Stripe\Checkout\Session;

beforeEach(function () {
    Mail::fake();
    config(['services.stripe.webhook_secret' => 'whsec_test']);

    $event = Event::factory()->published()->create(['title' => '秋の音楽祭', 'venue' => '町民ホール']);
    $this->order = Order::factory()->for($event)->create(['total_amount' => 7000, 'stripe_checkout_session_id' => 'cs_test_1']);
    $general = TicketType::factory()->for($event)->create(['name' => '一般']);
    $student = TicketType::factory()->for($event)->create(['name' => '学生']);
    OrderItem::factory()->for($this->order)->for($general)->create(['quantity' => 2, 'unit_price' => 3000]);
    OrderItem::factory()->for($this->order)->for($student)->create(['quantity' => 1, 'unit_price' => 1000]);
});

/**
 * Send a signed checkout.session.completed webhook for the order.
 */
function sendPaidWebhookFor(Order $order): TestResponse
{
    $payload = json_encode([
        'id' => 'evt_test',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_1',
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ]);
    $timestamp = time();
    $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

    return test()->call('POST', route('stripe.webhook'), [], [], [], test()->transformHeadersToServerVars(['Stripe-Signature' => $signature]), $payload);
}

test('one mail is sent to the buyer when the webhook pays the order', function () {
    sendPaidWebhookFor($this->order)->assertOk();

    Mail::assertSentCount(1);
    Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->hasTo($this->order->user->email)
        && $mail->hasSubject('【まちチケ】チケットの購入が完了しました（秋の音楽祭）'));
});

test('a repeated webhook does not send a second mail', function () {
    sendPaidWebhookFor($this->order)->assertOk();
    sendPaidWebhookFor($this->order)->assertOk();

    Mail::assertSentCount(1);
});

test('no mail is sent when the order was already paid', function () {
    $paidOrder = Order::factory()->paid()->create();

    expect(app(OrderService::class)->markAsPaid($paidOrder))->toBeFalse();

    Mail::assertNothingSent();
});

test('a mail failure does not undo the payment', function () {
    Exceptions::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(app(OrderService::class)->markAsPaid($this->order))->toBeTrue()
        ->and($this->order->fresh()->isPaid())->toBeTrue();

    Exceptions::assertReported(RuntimeException::class);
});

test('the mail shows the event, the items, the total and the order link', function () {
    $mail = new OrderPaidMail($this->order);

    $mail->assertSeeInHtml('秋の音楽祭');
    $mail->assertSeeInHtml('町民ホール');
    $mail->assertSeeInHtml($this->order->event->starts_at->isoFormat('M月D日(ddd) HH:mm'));
    $mail->assertSeeInHtml('一般');
    $mail->assertSeeInHtml('2枚');
    $mail->assertSeeInHtml('6,000円');
    $mail->assertSeeInHtml('学生');
    $mail->assertSeeInHtml('1,000円');
    $mail->assertSeeInHtml('合計: 7,000円');
    $mail->assertSeeInHtml(route('orders.show', $this->order));
});

test('confirming on the completion page sends exactly one mail, even when confirmed again', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andReturn(Session::constructFrom([
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
        ]));

    $component = Livewire::actingAs($this->order->user)
        ->withQueryParams(['session_id' => 'cs_test_1'])
        ->test('pages::orders.complete', ['order' => $this->order]);
    $component->call('confirm');

    expect($this->order->fresh()->isPaid())->toBeTrue();
    Mail::assertSentCount(1);
});

test('no mail is sent while Stripe says the order is still unpaid', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')->andReturn(Session::constructFrom([
            'id' => 'cs_test_1', 'payment_status' => 'unpaid',
        ]));

    Livewire::actingAs($this->order->user)
        ->withQueryParams(['session_id' => 'cs_test_1'])
        ->test('pages::orders.complete', ['order' => $this->order]);

    expect($this->order->fresh()->isPaid())->toBeFalse();
    Mail::assertNothingSent();
});

test('no mail is sent for a cancelled order', function () {
    $this->order->update(['status' => OrderStatus::Cancelled]);

    expect(app(OrderService::class)->markAsPaid($this->order))->toBeFalse();

    Mail::assertNothingSent();
});

test('a pipe in a ticket type name does not break the mail table', function () {
    $this->order->items()->first()->ticketType->update(['name' => 'S|席']);

    (new OrderPaidMail($this->order->fresh()))->assertSeeInHtml('S｜席');
});
