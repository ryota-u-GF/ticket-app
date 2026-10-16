<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('an order can be viewed by the buyer, the event organizer and admins', function () {
    $order = Order::factory()->create();

    expect(Gate::forUser($order->user)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($order->event->organizer)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $order))->toBeTrue();
});

test('an order cannot be viewed by other users or other organizers', function () {
    $order = Order::factory()->create();

    expect(Gate::forUser(User::factory()->create())->allows('view', $order))->toBeFalse()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows('view', $order))->toBeFalse();
});
