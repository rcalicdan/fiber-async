<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Promise\Promise;

beforeAll(function () {
    resetEventLoop();
    Http::enableTesting();
    Http::mock('GET')
        ->url('https://api.github.com/users/octocat')
        ->respondWithStatus(200)
        ->delay(0.4)
        ->persistent()
        ->register()
    ;
});

afterEach(function () {
    Http::reset();
});

test('http_get performs a GET request', function () {
    $response = run(function () {
        $links = Promise::all([
            http_get('https://api.github.com/users/octocat'),
            http_get('https://api.github.com/users/octocat'),
            http_get('https://api.github.com/users/octocat'),
            http_get('https://api.github.com/users/octocat'),
        ]);

        return await($links);
    });

    expect($response[0]->ok())->toBeTrue();
});
