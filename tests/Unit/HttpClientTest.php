<?php

use Rcalicdan\FiberAsync\Http\Response;

beforeEach(function () {
    resetEventLoop();
});

afterEach(function () {
    resetEventLoop();
});

describe('HTTP Request Methods', function () {

    test('GET request works with query parameters', function () {
        $response = run(function () {
            return await(http_get('https://httpbin.org/get', [
                'param1' => 'value1',
                'param2' => 'value2',
            ]));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);

        $data = $response->json();
        expect($data['args'])->toHaveKey('param1', 'value1');
        expect($data['args'])->toHaveKey('param2', 'value2');
    });

    test('POST request works with JSON data', function () {
        $testData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30,
        ];

        $response = run(function () use ($testData) {
            return await(http_post('https://httpbin.org/post', $testData));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);

        $data = $response->json();
        expect($data['json'])->toEqual($testData);

        expect($data['headers'])->toHaveKey('Content-Type');
    });

    test('PUT request works with data', function () {
        $testData = [
            'id' => 123,
            'name' => 'Updated Name',
            'status' => 'active',
        ];

        $response = run(function () use ($testData) {
            return await(http_put('https://httpbin.org/put', $testData));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);

        $data = $response->json();
        expect($data['json'])->toBe($testData);
    });

    test('DELETE request works', function () {
        $response = run(function () {
            return await(http_delete('https://httpbin.org/delete'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });

    test('PATCH request works using request builder', function () {
        $testData = ['status' => 'updated'];

        $response = run(function () use ($testData) {
            return await(http()
                ->json($testData)
                ->send('PATCH', 'https://httpbin.org/patch'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);

        $data = $response->json();
        expect($data['json'])->toBe($testData);
    });

    test('HEAD request works', function () {
        $response = run(function () {
            return await(http()->send('HEAD', 'https://httpbin.org/'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });

    test('OPTIONS request works', function () {
        $response = run(function () {
            return await(http()->send('OPTIONS', 'https://httpbin.org/'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });
});

describe('Fetch API', function () {

    test('fetch with default options works', function () {
        $response = run(function () {
            return await(fetch('https://httpbin.org/get'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });

    test('fetch with POST method and body', function () {
        $response = run(function () {
            return await(fetch('https://httpbin.org/post', [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['test' => 'data']),
            ]));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);

        $data = $response->json();
        expect($data['json'])->toBe(['test' => 'data']);
    });

    test('fetch with custom headers', function () {
        $response = run(function () {
            return await(fetch('https://httpbin.org/headers', [
                'headers' => [
                    'X-Custom-Header' => 'custom-value',
                    'User-Agent' => 'FiberAsync-Test/1.0',
                ],
            ]));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();

        $data = $response->json();
        expect($data['headers'])->toHaveKey('X-Custom-Header', 'custom-value');
        expect($data['headers'])->toHaveKey('User-Agent', 'FiberAsync-Test/1.0');
    });

    test('fetch with timeout option', function () {
        $response = run(function () {
            return await(fetch('https://httpbin.org/delay/1', [
                'timeout' => 5,
            ]));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });
});

describe('Request Builder', function () {

    test('request builder with headers works', function () {
        $response = run(function () {
            return await(http()
                ->header('Authorization', 'Bearer test-token')
                ->header('X-API-Key', 'test-key')
                ->get('https://httpbin.org/headers'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();

        $data = $response->json();
        expect($data['headers'])->toHaveKey('Authorization', 'Bearer test-token');
        expect($data['headers'])->toHaveKey('X-Api-Key', 'test-key');
    });

    test('request builder with JSON body', function () {
        $testData = ['message' => 'Hello World'];

        $response = run(function () use ($testData) {
            return await(http()
                ->json($testData)
                ->post('https://httpbin.org/post'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();

        $data = $response->json();
        expect($data['json'])->toBe($testData);
    });

    test('request builder with form data', function () {
        $formData = [
            'field1' => 'value1',
            'field2' => 'value2',
        ];

        $response = run(function () use ($formData) {
            return await(http()
                ->form($formData)
                ->post('https://httpbin.org/post'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();

        $data = $response->json();
        expect($data['form'])->toBe($formData);
    });

    test('request builder with basic auth', function () {
        $response = run(function () {
            return await(http()
                ->basicAuth('testuser', 'testpass')
                ->get('https://httpbin.org/basic-auth/testuser/testpass'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });

    test('request builder with bearer token', function () {
        $response = run(function () {
            return await(http()
                ->bearerToken('test-token-123')
                ->get('https://httpbin.org/bearer'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();

        $data = $response->json();
        expect($data['authenticated'])->toBeTrue();
        expect($data['token'])->toBe('test-token-123');
    });

    test('request builder with timeout', function () {
        $response = run(function () {
            return await(http()
                ->timeout(10)
                ->get('https://httpbin.org/delay/2'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });
});

describe('Response Handling', function () {

    test('response status methods work correctly', function () {
        // Test successful response
        $response = run(function () {
            return await(http_get('https://httpbin.org/status/200'));
        });

        expect($response->ok())->toBeTrue();
        expect($response->successful())->toBeTrue();
        expect($response->failed())->toBeFalse();
        expect($response->clientError())->toBeFalse();
        expect($response->serverError())->toBeFalse();

        // Test client error
        $response = run(function () {
            return await(http_get('https://httpbin.org/status/404'));
        });

        expect($response->ok())->toBeFalse();
        expect($response->successful())->toBeFalse();
        expect($response->failed())->toBeTrue();
        expect($response->clientError())->toBeTrue();
        expect($response->serverError())->toBeFalse();
        expect($response->status())->toBe(404);

        // Test server error
        $response = run(function () {
            return await(http_get('https://httpbin.org/status/500'));
        });

        expect($response->ok())->toBeFalse();
        expect($response->successful())->toBeFalse();
        expect($response->failed())->toBeTrue();
        expect($response->clientError())->toBeFalse();
        expect($response->serverError())->toBeTrue();
        expect($response->status())->toBe(500);
    });

    test('response headers can be accessed', function () {
        $response = run(function () {
            return await(http_get('https://httpbin.org/response-headers', [
                'X-Test-Header' => 'test-value',
            ]));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->headers())->toBeArray();
        expect($response->header('content-type'))->toContain('application/json');
    });

    test('response JSON parsing works', function () {
        $response = run(function () {
            return await(http_get('https://httpbin.org/json'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->json())->toBeArray();
        expect($response->json())->toHaveKey('slideshow');
    });
});

describe('Error Handling', function () {

    test('handles invalid URL gracefully', function () {
        expect(function () {
            run(function () {
                return await(http_get('invalid-url'));
            });
        })->toThrow(Exception::class);
    });

    test('handles connection timeout', function () {
        expect(function () {
            run(function () {
                return await(http()
                    ->timeout(1)
                    ->get('https://httpbin.org/delay/5'));
            });
        })->toThrow(Exception::class);
    });
});

describe('Concurrent Requests', function () {

    test('multiple concurrent requests work', function () {
        $responses = run(function () {
            $promises = [
                http_get('https://httpbin.org/delay/1'),
                http_get('https://httpbin.org/delay/1'),
                http_get('https://httpbin.org/delay/1'),
            ];

            return await(all($promises));
        });

        expect($responses)->toBeArray();
        expect($responses)->toHaveCount(3);

        foreach ($responses as $response) {
            expect($response)->toBeInstanceOf(Response::class);
            expect($response->ok())->toBeTrue();
        }
    });

    test('concurrent requests with different methods', function () {
        $responses = run(function () {
            $promises = [
                http_get('https://httpbin.org/get'),
                http_post('https://httpbin.org/post', ['test' => 'data']),
                http_put('https://httpbin.org/put', ['update' => 'data']),
                http_delete('https://httpbin.org/delete'),
            ];

            return await(all($promises));
        });

        expect($responses)->toBeArray();
        expect($responses)->toHaveCount(4);

        foreach ($responses as $response) {
            expect($response)->toBeInstanceOf(Response::class);
            expect($response->ok())->toBeTrue();
        }
    });
});

describe('Retry Functionality', function () {

    test('fetch with retry works on success', function () {
        $response = run(function () {
            return await(fetch_with_retry('https://httpbin.org/get', [], 3, 0.1));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });

    test('request builder with retry configuration', function () {
        $response = run(function () {
            return await(http()
                ->retry(3, 0.1)
                ->get('https://httpbin.org/get'));
        });

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeTrue();
        expect($response->status())->toBe(200);
    });
});
