<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'role' => 'customer',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('new users can register as an organizer', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'role' => 'organizer',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect(User::where('email', 'test@example.com')->first()->role)->toBe(UserRole::Organizer);
});

test('new users cannot register as an admin', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'role' => 'admin',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('role');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
});

test('an invalid role shows an error on the registration screen', function () {
    $response = $this->from(route('register'))
        ->followingRedirects()
        ->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'role' => 'invalid-role',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

    $response->assertOk()
        ->assertSee('選択した アカウントの種類は 無効です。');

    $this->assertGuest();
});
