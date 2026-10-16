<?php

use App\Models\Event;
use App\Models\TicketType;

test('only published events that have not ended are listed', function () {
    Event::factory()->published()->create(['title' => '夏祭り']);
    Event::factory()->published()->create([
        'title' => '開催中イベント',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
    Event::factory()->published()->create([
        'title' => '終了済みイベント',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDay(),
    ]);
    Event::factory()->create(['title' => '下書きイベント']);
    Event::factory()->unpublished()->create(['title' => '非公開イベント']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('夏祭り')
        ->assertSee('開催中イベント')
        ->assertDontSee('終了済みイベント')
        ->assertDontSee('下書きイベント')
        ->assertDontSee('非公開イベント');
});

test('events are ordered by the nearest start time', function () {
    Event::factory()->published()->create(['title' => '来月のイベント', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(2)]);
    Event::factory()->published()->create(['title' => '明日のイベント', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);

    $this->get(route('home'))->assertSeeInOrder(['明日のイベント', '来月のイベント']);
});

test('events starting at the same time are ordered by id', function () {
    $startsAt = now()->addWeek();

    $events = Event::factory()->published()->count(3)->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHours(2),
    ]);

    expect(Event::query()->published()->upcoming()->pluck('id')->all())
        ->toBe($events->pluck('id')->sort()->values()->all());
});

test('the lowest ticket price is shown', function () {
    $event = Event::factory()->published()->create();
    TicketType::factory()->for($event)->create(['price' => 3000]);
    TicketType::factory()->for($event)->create(['price' => 1500]);

    $freeEvent = Event::factory()->published()->create();
    TicketType::factory()->for($freeEvent)->create(['price' => 0]);

    $this->get(route('home'))
        ->assertSee('1,500円〜')
        ->assertDontSee('3,000円〜')
        ->assertSee('無料');
});

test('events are paginated by 12', function () {
    foreach (range(1, 13) as $day) {
        Event::factory()->published()->create([
            'title' => sprintf('イベント%02d', $day),
            'starts_at' => now()->addDays($day),
            'ends_at' => now()->addDays($day)->addHours(2),
        ]);
    }

    $this->get(route('home'))
        ->assertSee('イベント12')
        ->assertDontSee('イベント13');

    $this->get(route('home', ['page' => 2]))
        ->assertSee('イベント13')
        ->assertDontSee('イベント12');
});

test('an event whose end time is exactly now is no longer listed', function () {
    $this->freezeTime();

    Event::factory()->published()->create([
        'title' => 'ちょうど終了イベント',
        'starts_at' => now()->subHour(),
        'ends_at' => now(),
    ]);
    Event::factory()->published()->create([
        'title' => 'あと少しで終了イベント',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addSecond(),
    ]);

    $this->get(route('home'))
        ->assertSee('あと少しで終了イベント')
        ->assertDontSee('ちょうど終了イベント');
});
