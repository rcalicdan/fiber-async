<?php

use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

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
    $request = new HttpRequest('https://example.com', ['timeout' => 10], function () {});

    expect($request->getUrl())->toBe('https://example.com');
    expect($request->getHandle())->toBeInstanceOf(CurlHandle::class);
    expect($request->getCallback())->toBeCallable();
});
