<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * php artisan app:tunnel opens a public address to this app, so it refuses while anyone could sign in with the seeded
 * password, before it starts anything.
 */

it('refuses to open a tunnel while an account has the seeded password', function () {
    User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('password')]);
    User::factory()->create(['email' => 'safe@example.com', 'password' => Hash::make('a-long-unguessable-one')]);

    $this->artisan('app:tunnel')
        ->expectsOutputToContain('owner@example.com')
        ->doesntExpectOutputToContain('safe@example.com')
        ->assertFailed();
});
