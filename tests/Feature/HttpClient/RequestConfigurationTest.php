<?php

use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Request Configuration', function () {

    test('custom headers are sent correctly', function () {
        $response = run(fn() => await(http()
            ->header('X-Custom-Header', 'MyValue')
            ->accept('application/xml')
            ->get('https://httpbin.org/headers')
        ));

        $headers = $response->json()['headers'];
        expect($headers['X-Custom-Header'])->toBe('MyValue');
        expect($headers['Accept'])->toBe('application/xml');
    });

    test('bearer token authentication is sent', function () {
        $token = 'my-secret-token';
        $response = run(fn() => await(http()->bearerToken($token)->get('https://httpbin.org/headers')));

        expect($response->json()['headers']['Authorization'])->toBe("Bearer {$token}");
    });

    test('basic authentication is sent', function () {
        $user = 'testuser';
        $pass = 'testpass';
        $response = run(fn() => await(http()->basicAuth($user, $pass)->get('https://httpbin.org/basic-auth/testuser/testpass')));

        expect($response->ok())->toBeTrue();
        expect($response->json())->toEqual(['authenticated' => true, 'user' => $user]);
    });

    test('custom user agent is sent', function () {
        $agent = 'My-Test-App/1.0';
        $response = run(fn() => await(http()->userAgent($agent)->get('https://httpbin.org/user-agent')));

        expect($response->json()['user-agent'])->toBe($agent);
    });

    test('request timeout throws an exception', function () {
        run(fn() => await(http()->timeout(1)->get('https://httpbin.org/delay/2')));
    })->throws(HttpException::class, 'timed out');

    test('redirects are followed by default', function () {
        $response = run(fn() => await(http()->get('https://httpbin.org/redirect/1')));
        expect($response->ok())->toBeTrue();
        expect($response->json()['url'])->toContain('/get');
    });

    test('redirects can be disabled', function () {
        $response = run(fn() => await(http()->redirects(false)->get('https://httpbin.org/redirect/1')));
        expect($response->status())->toBe(302);
        expect($response->header('Location'))->toBe('/get');
    });
});