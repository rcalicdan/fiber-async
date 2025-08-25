<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require __DIR__ . '/vendor/autoload.php';

echo "====== Integration Test for Cookies AND Caching (FIXED) ======\n";

Task::run(function () {
    // 1. Arrange: Get testing handler and reset FIRST
    $handler = Http::testing();
    $handler->reset(); // Reset first
    
    // THEN enable cookie jar (after reset)
    $handler->withGlobalCookieJar();
    
    // Set as global instance
    Http::setInstance($handler);

    echo "DEBUG: Testing handler created, reset, and configured with cookie jar\n";

    $sessionId = 'session-for-caching-test';
    $domain = 'api.example.com';
    $profileUrl = "https://{$domain}/api/profile";
    $loginUrl = "https://{$domain}/login";

    // Set up mocks
    $handler->mock('POST')->url($loginUrl)
        ->setCookie('session_id', $sessionId)
        ->json(['status' => 'logged_in'])
        ->register();

    $handler->mock('GET')->url($profileUrl)
        ->withHeader('Cookie', "session_id={$sessionId}")
        ->delay(0.5)
        ->json(['user' => 'John Doe', 'timestamp' => microtime(true)])
        ->register();

    echo "\n--- Step 1: Logging in to establish a session ---\n";
    $loginResponse = await(Http::post($loginUrl));
    
    // Now we can safely assert cookie exists
    $handler->assertCookieExists('session_id');
    echo "   ✓ SUCCESS: Logged in and session cookie was stored.\n";

    // -----------------------------------------------------------------

    echo "\n--- Step 2: First profile fetch (expecting CACHE MISS) ---\n";
    $start1 = microtime(true);
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
    
    // Assertion 1: Verify the second call was a cache hit
    if ($elapsed2 < 0.01 && $data1['timestamp'] === $data2['timestamp']) {
        echo "   ✓ SUCCESS: Second request was an instant cache hit with the correct data.\n";
    } else {
        echo "   ✗ FAILED: Second request was not a cache hit.\n";
    }

    // Assertion 2: Verify the total number of requests made
    try {
        $handler->assertRequestCount(3);
        echo "   ✓ SUCCESS: Correct number of requests recorded.\n";
    } catch (Exception $e) {
        echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    }
    
    // Final check: Request history
    $history = $handler->getRequestHistory();
    $profile_miss_found = false;
    $profile_hit_found = false;
    foreach($history as $req) {
        if ($req->url === $profileUrl && $req->method === 'GET') $profile_miss_found = true;
        if ($req->url === $profileUrl && $req->method === 'GET (FROM CACHE)') $profile_hit_found = true;
    }

    if ($profile_miss_found && $profile_hit_found) {
        echo "   ✓ SUCCESS: Request history shows cache miss and cache hit.\n";
    } else {
        echo "   ✗ FAILED: Request history is incorrect.\n";
        echo "   DEBUG: History:\n";
        foreach($history as $req) {
            echo "     - {$req->method} {$req->url}\n";
        }
    }
});

echo "\n====== Cookie and Cache Integration Test Complete ======\n";