<?php

use Rcalicdan\FiberAsync\Http\Response;

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Request Methods', function () {

    test('http_get performs a GET request', function () {
        $response = run(fn() => await(http_get('https://httpbin.org/get?name=fiber')));

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->json()['args']['name'])->toBe('fiber');
    });

    test('http_post sends JSON data', function () {
        $data = ['user' => 'test', 'id' => 123];
        $response = run(fn() => await(http_post('https://httpbin.org/post', $data)));

        expect($response->ok())->toBeTrue();
        $json = $response->json();
        expect($json['json'])->toEqual($data);
        expect($json['headers']['Content-Type'])->toBe('application/json');
    });

    test('http_put updates data', function () {
        $data = ['status' => 'updated'];
        $response = run(fn() => await(http_put('https://httpbin.org/put', $data)));

        expect($response->ok())->toBeTrue();
        expect($response->json()['json'])->toBe($data);
    });

    test('http_delete performs a DELETE request', function () {
        $response = run(fn() => await(http_delete('https://httpbin.org/delete')));

        expect($response->ok())->toBeTrue();
    });

    test('request builder syntax works for all methods', function () {
        $response = run(fn() => await(http()->get('https://httpbin.org/get')));
        expect($response->ok())->toBeTrue();

        $response = run(fn() => await(http()->post('https://httpbin.org/post', ['test' => 1])));
        expect($response->ok())->toBeTrue();
    });
});
