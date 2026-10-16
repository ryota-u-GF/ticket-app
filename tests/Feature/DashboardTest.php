<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users are redirected by role', function (UserRole $role, string $expectedPath) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect($expectedPath);
})->with([
    'admin' => [UserRole::Admin, '/'],
    'organizer' => [UserRole::Organizer, '/organizer/events'],
    'customer' => [UserRole::Customer, '/orders'],
]);
