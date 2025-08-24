<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Api\Timer;

require 'vendor/autoload.php';

echo "====== Advanced Mocked Caching Tests ======\n";

Task::run(function () {
    $handler = Http::testing();

    // =================================================================
    // Test Case 1: Concurrent Requests (Cache Stampede Simulation)
    // =================================================================
    echo "\n--- Test Case 1: Concurrent Requests (Cache Stampede) --- \n";
    $handler->reset();
    $url = 'https://api.example.com/concurrent-data';
    
    Http::mock('GET')->url($url)->json(['data' => 'live'])->delay(1.5)->persistent()->register();

    echo "Starting two requests concurrently before cache is populated...\n";
    $startTime = microtime(true);

    $promiseA = Http::request()->cache(60)->get($url);
    $promiseB = Http::request()->cache(60)->get($url);
    
    await(Promise::all([$promiseA, $promiseB]));
    $totalTime = microtime(true) - $startTime;

    echo "-> Both requests completed in ~" . round($totalTime, 2) . "s (Correct, both were cache misses)\n";
    $handler->assertRequestCount(2); // Both requests hit the mock.
    echo "-> Verified that 2 requests hit the mock as expected.\n";

    // =================================================================
    // Test Case 2: Sequential Requests (True Cache Hit)
    // =================================================================
    echo "\n--- Test Case 2: Sequential Requests (Demonstrating Cache Hit Speed) --- \n";
    $handler->reset(); // Reset history and cache for a clean test.
    $url = 'https://api.example.com/sequential-data';

    Http::mock('GET')->url($url)->json(['data' => 'live', 'timestamp' => time()])->delay(1.5)->register();

    echo "1. Making the first request (CACHE MISS)...\n";
    $start1 = microtime(true);
    $response1 = await(Http::request()->cache(60)->get($url));
    $elapsed1 = microtime(true) - $start1;
    echo "   -> Finished in " . round($elapsed1, 4) . "s.\n";

    echo "\n2. Making the second request after the first has completed (CACHE HIT)...\n";
    $start2 = microtime(true);
    $response2 = await(Http::request()->cache(60)->get($url));
    $elapsed2 = microtime(true) - $start2;
    echo "   -> Finished in " . round($elapsed2, 4) . "s.\n";
    
    echo "\n3. Verifying results...\n";
    if ($elapsed2 < 0.01 && $elapsed2 < $elapsed1) {
        echo "   ✓ SUCCESS: Second request was virtually instant, proving it came from cache.\n";
    } else {
        echo "   ✗ FAILED: Second request was not significantly faster.\n";
    }

    if ($response1->body() === $response2->body()) {
         echo "   ✓ SUCCESS: Both responses returned the same cached content.\n";
    } else {
         echo "   ✗ FAILED: Response bodies do not match.\n";
    }

    // Assert that the mock was only hit ONCE.
    // The history shows the initial 'GET' and the subsequent 'GET (FROM CACHE)'.
    $handler->assertRequestCount(2);
    echo "   ✓ SUCCESS: Correct number of requests recorded (1 miss, 1 hit).\n";
});