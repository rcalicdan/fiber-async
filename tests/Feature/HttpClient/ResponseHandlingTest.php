<?php

use Rcalicdan\FiberAsync\Http\Response;

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Response Handling', function () {

    test('response status helpers work correctly', function () {
        $okResponse = run(fn() => await(fetch('https://httpbin.org/status/201')));
        expect($okResponse->status())->toBe(201);
        expect($okResponse->ok())->toBeTrue();
        expect($okResponse->successful())->toBeTrue();
        expect($okResponse->failed())->toBeFalse();
        expect($okResponse->clientError())->toBeFalse();
        expect($okResponse->serverError())->toBeFalse();

        $clientErrorResponse = run(fn() => await(fetch('https://httpbin.org/status/404')));
        expect($clientErrorResponse->status())->toBe(404);
        expect($clientErrorResponse->ok())->toBeFalse();
        expect($clientErrorResponse->clientError())->toBeTrue();

        $serverErrorResponse = run(fn() => await(fetch('https://httpbin.org/status/503')));
        expect($serverErrorResponse->status())->toBe(503);
        expect($serverErrorResponse->ok())->toBeFalse();
        expect($serverErrorResponse->serverError())->toBeTrue();
    });

    test('response headers can be accessed', function () {
        $response = run(fn() => await(fetch('https://httpbin.org/response-headers?Content-Type=application/json&X-Test=Success')));

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->headers())->toBeArray();
        expect($response->header('Content-Type'))->toBe('application/json');
        expect($response->header('X-Test'))->toBe('Success');
        expect($response->header('Non-Existent-Header'))->toBeNull();
    });

    test('response JSON parsing works', function () {
        $response = run(fn() => await(fetch('https://httpbin.org/json')));
        $data = $response->json();

        expect($data)->toBeArray();
        expect($data['slideshow']['title'])->toBe('Sample Slide Show');
    });

    test('response body returns raw string', function () {
        $response = run(fn() => await(fetch('https://httpbin.org/robots.txt')));
        $body = $response->body();

        expect($body)->toBeString();
        expect($body)->toContain('User-agent: *');
    });
});