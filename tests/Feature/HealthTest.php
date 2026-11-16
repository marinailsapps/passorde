<?php

use function Pest\Laravel\get;

it('returns a successful response from the health check', function () {
    get('/up')->assertOk();
});

it('redirects guests from the home page to the login page', function () {
    get('/')->assertRedirect('/login');
});
