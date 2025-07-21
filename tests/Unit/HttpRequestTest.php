<?php

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\HttpRequest;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;

test('http request manager can add and process requests', function () {
    $manager = new HttpRequestManager;
    $responseReceived = false;
    $responseData = null;

    $manager->addHttpRequest('https://httpbin.org/json', [], function ($error, $response, $httpCode) use (&$responseReceived, &$responseData) {
        $responseReceived = true;
        $responseData = ['error' => $error, 'response' => $response, 'code' => $httpCode];
    });

    $hasRequests = $manager->hasRequests();
    expect($hasRequests)->toBeTrue();
});

test('http request object creates proper curl handle', function () {
    $options = [
        CURLOPT_URL => 'https://example.com',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
    ];

    $request = new HttpRequest('https://example.com', $options, function () {});

    expect($request->getUrl())->toBe('https://example.com');
    expect($request->getHandle())->toBeInstanceOf(CurlHandle::class);
    expect($request->getCallback())->toBeCallable();
});
