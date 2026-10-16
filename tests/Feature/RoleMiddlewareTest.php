<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:organizer'])
        ->get('/_test/organizer-only', fn () => 'ok');
});

test('users with the required role can pass', function () {
    $this->actingAs(User::factory()->organizer()->create());

    $this->get('/_test/organizer-only')->assertOk();
});

test('users without the required role are forbidden', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/_test/organizer-only')->assertForbidden();
});
