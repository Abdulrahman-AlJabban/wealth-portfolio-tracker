<?php

use App\Models\User;

it('sends guests from the home page to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends the post-login dashboard to the portfolios', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('portfolios.index'));
});

it('sends signed-in users from the home page to their portfolios', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('portfolios.index'));
});
