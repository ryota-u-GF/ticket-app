<?php

use App\Enums\UserRole;
use App\Models\User;

/**
 * Render the shared app layout with the given content.
 */
function renderAppLayout(): string
{
    return (string) test()->blade('<x-layouts::app>本文</x-layouts::app>');
}

test('guests can view the layout with login and register links', function () {
    $html = renderAppLayout();

    expect($html)
        ->toContain('本文')
        ->toContain('イベント一覧')
        ->toContain(route('login'))
        ->toContain(route('register'))
        ->not->toContain('マイチケット')
        ->not->toContain('logout-button');
});

test('the navigation changes by role', function (UserRole $role, array $visible, array $hidden) {
    $this->actingAs(User::factory()->create(['role' => $role]));

    $html = renderAppLayout();

    expect($html)
        ->toContain('イベント一覧')
        ->toContain(route('orders.index'))
        ->toContain(route('profile.edit'))
        ->toContain('logout-button')
        ->not->toContain('会員登録');

    foreach ($visible as $text) {
        expect($html)->toContain($text);
    }

    foreach ($hidden as $text) {
        expect($html)->not->toContain($text);
    }
})->with([
    'customer' => [UserRole::Customer, [], ['主催者メニュー', '/admin/events', '/admin/users']],
    'organizer' => [UserRole::Organizer, ['主催者メニュー'], ['/admin/events', '/admin/users']],
    'admin' => [UserRole::Admin, ['/admin/events', '/admin/users'], ['主催者メニュー']],
]);
