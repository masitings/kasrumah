<?php

use App\Models\User;

test('guests are redirected to the login page from home', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the home (Catat) page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))->assertOk();
});
