<?php

use function Pest\Laravel\getJson;

it('exposes a health check endpoint', function () {
    test()->get('/up')->assertOk();
});

it('stamps every response with a correlation id', function () {
    $response = getJson('/api/me'); // 401, but the middleware still runs

    expect($response->headers->get('X-Request-Id'))->not->toBeNull();
});

it('honors an inbound request id', function () {
    $response = test()->withHeader('X-Request-Id', 'abc-123')->getJson('/api/me');

    expect($response->headers->get('X-Request-Id'))->toBe('abc-123');
});
