<?php

use App\Models\Event;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

test('the event form shows Japanese attribute names in error messages', function () {
    $event = Event::factory()->create();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('title', str_repeat('あ', 101))
        ->set('venue', '')
        ->call('save')
        ->assertHasErrors(['title', 'venue']);

    $messages = collect($component->errors()->all())->implode(' ');

    expect($messages)
        ->toContain('タイトル')
        ->toContain('会場')
        ->not->toContain('title')
        ->not->toContain('venue');
});

test('the ticket type form shows Japanese attribute names in error messages', function () {
    $event = Event::factory()->create();

    $component = Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event])
        ->set('ticket_name', '')
        ->set('ticket_capacity', '0')
        ->call('saveTicketType')
        ->assertHasErrors(['ticket_name', 'ticket_capacity']);

    $messages = collect($component->errors()->all())->implode(' ');

    expect($messages)->toContain('券種名')->toContain('定員')->not->toContain('ticket_');
});

test('lang/ja attributes alone translate the app field names', function (string $field, string $label) {
    $message = Validator::make([$field => ''], [$field => 'required'])->errors()->first($field);

    expect($message)->toContain($label)->not->toContain($field);
})->with([
    ['title', 'タイトル'],
    ['venue', '会場'],
    ['starts_at', '開始日時'],
    ['ends_at', '終了日時'],
    ['capacity', '定員'],
    ['sales_starts_at', '販売開始日時'],
    ['sales_ends_at', '販売終了日時'],
    ['ticket_name', '券種名'],
    ['ticket_price', '価格'],
    ['ticket_capacity', '定員'],
    ['ticket_sales_starts_at', '販売開始日時'],
    ['ticket_sales_ends_at', '販売終了日時'],
]);
