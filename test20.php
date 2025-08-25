<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\CookieJar;

require __DIR__.'/vendor/autoload.php';

echo "====== Mocked Integration Test for Sending Cookies ======\n";

Task::run(function () {
    $handler = Http::enableTesting();

    // =================================================================
    // Test Case 1: Manually Attaching Cookies to a Request
    // =================================================================
    echo "\n--- Test Case 1: Manually Attaching Cookies --- \n";
    echo "Goal: Use the ->cookie() and ->cookies() helpers to send cookies.\n\n";

    $handler->reset();
    $url = 'https://api.example.com/analytics';
    $expectedCookieHeader = 'user_preference=dark; tracking_id=xyz-987';

    // Mock the endpoint to expect the exact Cookie header.
    Http::mock('POST')
        ->url($url)
        ->withHeader('Cookie', $expectedCookieHeader)
        ->respondWithStatus(200)
        ->json(['status' => 'ok'])->persistent()
        ->register()
    ;

    echo "   -> Sending request with manually attached cookies...\n";

    $response = await(
        Http::request()
            ->cookie('user_preference', 'dark') // Attach one cookie
            ->cookies(['tracking_id' => 'xyz-987']) // Attach another
            ->post($url)
    );

    if ($response->status() === 200) {
        echo "   ✓ SUCCESS: Received 200 OK. The mock received the correct Cookie header.\n";
    } else {
        echo '   ✗ FAILED: The mock did not match. Status received: '.$response->status()."\n";
    }

    // Assert against the history for good measure.
    $handler->assertRequestMade('POST', $url);
    $lastRequest = $handler->getRequestHistory()[0];
    $sentCookieHeader = '';
    foreach ($lastRequest->options[CURLOPT_HTTPHEADER] as $header) {
        if (str_starts_with($header, 'Cookie:')) {
            $sentCookieHeader = substr($header, 8);

            break;
        }
    }

    if ($sentCookieHeader === $expectedCookieHeader) {
        echo "   ✓ SUCCESS: Verified from history that the correct Cookie header was sent.\n";
    } else {
        echo "   ✗ FAILED: History shows incorrect Cookie header: '{$sentCookieHeader}'.\n";
    }

    // =================================================================
    // Test Case 2: Automatically Attaching Cookies from a CookieJar
    // =================================================================
    echo "\n--- Test Case 2: Attaching Cookies from a CookieJar --- \n";
    echo "Goal: Pre-populate a CookieJar and verify the request sends its cookies.\n\n";

    $handler->reset();
    $url = 'https://api.example.com/session-check';
    $domain = 'api.example.com';
    $sessionId = 'pre-existing-session-abc';

    // 1. Arrange: Create and pre-populate a cookie jar.
    $cookieJar = new CookieJar;
    $cookieJar->setCookie(new Rcalicdan\FiberAsync\Http\Cookie(
        'session_id',
        $sessionId,
        time() + 3600,
        $domain,
        '/'
    ));

    echo "   -> CookieJar pre-populated with 'session_id: {$sessionId}'.\n";

    // 2. Mock the endpoint to expect the cookie from the jar.
    Http::mock('GET')
        ->url($url)
        ->withHeader('Cookie', "session_id={$sessionId}")
        ->respondWithStatus(200)
        ->json(['status' => 'session_valid'])
        ->persistent()
        ->register()
    ;

    echo "   -> Sending request with the pre-populated cookie jar...\n";

    // 3. Act: Attach the jar and make the request.
    $response = await(
        Http::request()
            ->useCookieJar($cookieJar)
            ->get($url)
    );

    if ($response->status() === 200) {
        echo "   ✓ SUCCESS: Received 200 OK. The cookie from the jar was sent automatically.\n";
    } else {
        echo '   ✗ FAILED: The mock did not match. Status received: '.$response->status()."\n";
    }

    $handler->assertRequestCount(1);
});

echo "\n====== Cookie Sending Test Complete ======\n";
