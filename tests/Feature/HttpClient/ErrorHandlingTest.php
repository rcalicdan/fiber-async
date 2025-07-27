<?php

use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Response;

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Error Handling', function () {

    test('handles invalid domain gracefully', function () {
        // This should throw an exception because the domain does not resolve
        run(fn () => await(fetch('https://invalid-domain-that-does-not-exist.test')));
    })->throws(HttpException::class, 'Could not resolve host');

    test('handles 404 Not Found without throwing exception', function () {
        $response = run(fn () => await(fetch('https://httpbin.org/status/404')));

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->status())->toBe(404);
        expect($response->ok())->toBeFalse();
        expect($response->clientError())->toBeTrue();
    });

    test('handles 500 Internal Server Error without throwing exception', function () {
        $response = run(fn () => await(fetch('https://httpbin.org/status/500')));

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->status())->toBe(500);
        expect($response->ok())->toBeFalse();
        expect($response->serverError())->toBeTrue();
    });
});
