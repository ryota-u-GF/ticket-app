<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('published events can be viewed by anyone', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $event))->toBeTrue();
});

test('draft events can be viewed only by the owner and admins', function () {
    $event = Event::factory()->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser($event->organizer)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $event))->toBeTrue();
});

test('only published events can be purchased', function () {
    $published = Event::factory()->published()->create();
    $draft = Event::factory()->create();
    $unpublished = Event::factory()->unpublished()->create();
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser(null)->allows('purchase', $published))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('purchase', $published))->toBeTrue()
        ->and(Gate::forUser($draft->organizer)->allows('purchase', $draft))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('purchase', $unpublished))->toBeFalse();
});

test('only organizers can create events', function () {
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('create', Event::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('create', Event::class))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('create', Event::class))->toBeFalse();
});

test('only admins can unpublish events', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(User::factory()->admin()->create())->allows('unpublish', $event))->toBeTrue()
        ->and(Gate::forUser($event->organizer)->allows('unpublish', $event))->toBeFalse();
});
