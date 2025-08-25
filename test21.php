<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require __DIR__ . '/vendor/autoload.php';

echo "====== Integration Test for Cookies AND Caching ======\n";

Task::run(function () {
    // 1. Arrange: Enable testing with a managed CookieJar.
    $handler = Http::testing()->withGlobalCookieJar();
    $handler->reset();

    $sessionId = 'session-for-caching-test';
    $domain = 'api.example.com';
    $profileUrl = "https://{$domain}/api/profile";
    $loginUrl = "https://{$domain}/login";

    Http::mock('POST')->url($loginUrl)
        ->setCookie('session_id', $sessionId)
        ->json(['status' => 'logged_in'])
        ->register();

    // Mock the /profile endpoint. It MUST expect the cookie.
    // It is NOT persistent. If it's called more than once, the test will fail.
    Http::mock('GET')->url($profileUrl)
        ->withHeader('Cookie', "session_id={$sessionId}")
        ->delay(0.5) // Add a delay to make the cache hit obvious
        ->json(['user' => 'John Doe', 'timestamp' => microtime(true)])
        ->register();

    echo "--- Step 1: Logging in to establish a session ---\n";
    await(Http::post($loginUrl));
    $handler->assertCookieExists('session_id');
    echo "   ✓ SUCCESS: Logged in and session cookie was stored.\n";

    // -----------------------------------------------------------------

    echo "\n--- Step 2: First profile fetch (expecting CACHE MISS) ---\n";
    $start1 = microtime(true);
    // This request uses BOTH the cookie jar (implicitly) and caching.
    $response1 = await(
        Http::request()
            ->cache(60)
            ->get($profileUrl)
    );
    $elapsed1 = microtime(true) - $start1;
    $data1 = $response1->json();
    echo "   -> Request finished in " . round($elapsed1, 4) . "s.\n";

    // -----------------------------------------------------------------

    echo "\n--- Step 3: Second profile fetch (expecting CACHE HIT) ---\n";
    $start2 = microtime(true);
    $response2 = await(
        Http::request()
            ->cache(60)
            ->get($profileUrl)
    );
    $elapsed2 = microtime(true) - $start2;
    $data2 = $response2->json();
    echo "   -> Request finished in " . round($elapsed2, 4) . "s.\n";

    // -----------------------------------------------------------------

    echo "\n--- Step 4: Verifying Results ---\n";
    
    // Assertion 1: Verify the second call was a cache hit (instant and same data).
    if ($elapsed2 < 0.01 && $data1['timestamp'] === $data2['timestamp']) {
        echo "   ✓ SUCCESS: Second request was an instant cache hit with the correct data.\n";
    } else {
        echo "   ✗ FAILED: Second request was not a cache hit.\n";
    }

    // Assertion 2: Verify the total number of requests made.
    try {
        // We expect 3 total recorded requests:
        // 1. POST /login (consumes a mock)
        // 2. GET /profile (cache miss, consumes a mock)
        // 3. GET /profile (cache hit, does NOT consume a mock)
        $handler->assertRequestCount(3);
        echo "   ✓ SUCCESS: Correct number of requests recorded (login, cache miss, cache hit).\n";
    } catch (Exception $e) {
        echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    }
    
    // Final check: Let's see the history to be sure.
    $history = $handler->getRequestHistory();
    $profile_miss_found = false;
    $profile_hit_found = false;
    foreach($history as $req) {
        if ($req->url === $profileUrl && $req->method === 'GET') $profile_miss_found = true;
        if ($req->url === $profileUrl && $req->method === 'GET (FROM CACHE)') $profile_hit_found = true;
    }

    if ($profile_miss_found && $profile_hit_found) {
        echo "   ✓ SUCCESS: Request history correctly shows one cache miss and one cache hit for the profile.\n";
    } else {
        echo "   ✗ FAILED: Request history is incorrect.\n";
    }

});

echo "\n====== Cookie and Cache Integration Test Complete ======\n";