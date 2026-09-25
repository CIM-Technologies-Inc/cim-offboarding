<?php

test('guests are redirected to the sign-in page', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

test('the sign-in page loads', function () {
    $response = $this->get(route('login'));

    $response->assertStatus(200);
});
