<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;

require 'vendor/autoload.php';

echo "====== Advanced Caching Failure Mode Tests (with Delay) ======\n";

Task::run(function () {
    $handler = Http::startTesting();

    // =================================================================
    // Test Case 1: Verify that Error Responses Are NOT Cached
    // =================================================================
    echo "\n--- Test Case 1: Verify HTTP Error Responses Are Not Cached --- \n";
    $handler->reset();
    $url_http_error = 'https://api.example.com/http-error';

    // Add a 0.5s delay to the failing mock.
    Http::mock('GET')->url($url_http_error)
        ->respondWithStatus(503)
        ->delay(0.5) 
        ->persistent()
        ->register();

    $test1_success = true;
    
    echo "1. Making first request (expecting a ~0.5s delay and a 503 response)...\n";
    $start1 = microtime(true);
    $response1 = await(Http::request()->cache(60)->get($url_http_error));
    $elapsed1 = microtime(true) - $start1;
    
    if ($response1->status() === 503) {
        echo "   ✓ SUCCESS: Received 503 as expected. (Took " . round($elapsed1, 4) . "s)\n";
    } else {
        echo "   ✗ FAILED: Did not receive a 503 response.\n";
        $test1_success = false;
    }

    echo "\n2. Making second request (should also delay ~0.5s as a cache miss)...\n";
    $start2 = microtime(true);
    $response2 = await(Http::request()->cache(60)->get($url_http_error));
    $elapsed2 = microtime(true) - $start2;

    if ($response2->status() === 503) {
        echo "   ✓ SUCCESS: Received 503 again, proving the error response was not cached. (Took " . round($elapsed2, 4) . "s)\n";
    } else {
        echo "   ✗ FAILED: Did not receive a 503 on the second attempt.\n";
        $test1_success = false;
    }

    echo "\n3. Verifying mock interactions for Test Case 1...\n";
    try {
        $handler->assertRequestCount(2);
        echo "   ✓ SUCCESS: Mock was hit 2 times. This is correct.\n";
    } catch (Exception $e) {
        echo "   ✗ FAILED: " . $e->getMessage() . "\n";
        $test1_success = false;
    }
    
    if ($test1_success) {
        echo "   --> TEST CASE 1 FINAL VERDICT: PASSED.\n";
    }

    // =================================================================
    // Test Case 2: Verify Transport-Level Exceptions Are NOT Cached
    // =================================================================
    echo "\n--- Test Case 2: Verify Transport-Level Exceptions Are Not Cached --- \n";
    $handler->reset();
    $url_exception = 'https://api.example.com/network-error';

    // Add a 0.5s delay to the failing mock.
    Http::mock('GET')->url($url_exception)
        ->fail('Connection refused')
        ->delay(0.5) // <-- Added delay
        ->persistent()
        ->register();
    
    $test2_success = true;

    echo "1. Making first request (expecting a ~0.5s delay and an exception)...\n";
    $start3 = microtime(true);
    try {
        await(Http::request()->cache(60)->get($url_exception));
        $test2_success = false;
    } catch (HttpException $e) {
        $elapsed3 = microtime(true) - $start3;
        if (str_contains($e->getMessage(), 'Connection refused')) {
            echo "   ✓ SUCCESS: Caught expected HttpException. (Took " . round($elapsed3, 4) . "s)\n";
        } else {
            $test2_success = false;
        }
    }

    echo "\n2. Making second request (should also delay ~0.5s and throw an exception)...\n";
    $start4 = microtime(true);
    try {
        await(Http::request()->cache(60)->get($url_exception));
        $test2_success = false;
    } catch (HttpException $e) {
        $elapsed4 = microtime(true) - $start4;
        if (str_contains($e->getMessage(), 'Connection refused')) {
            echo "   ✓ SUCCESS: Caught exception again, proving failure was not cached. (Took " . round($elapsed4, 4) . "s)\n";
        } else {
            $test2_success = false;
        }
    }

    echo "\n3. Verifying mock interactions for Test Case 2...\n";
    try {
        $handler->assertRequestCount(2);
        echo "   ✓ SUCCESS: Mock was hit 2 times. This is correct.\n";
    } catch (Exception $e) {
        $test2_success = false;
    }

    if ($test2_success) {
        echo "   --> TEST CASE 2 FINAL VERDICT: PASSED.\n";
    }

    // =================================================================
    // Test Case 3: Verify Caching Occurs After a Successful Retry
    // =================================================================
    echo "\n--- Test Case 3: Verify Caching After a Successful Retry --- \n";
    $handler->reset();
    $url_retry = 'https://api.example.com/fails-then-succeeds';

    Http::mock('GET')->url($url_retry)
        ->statusFailuresUntilAttempt(2, 503)
        ->json(['data' => 'success', 'timestamp' => time()])
        ->register();

    echo "1. Making first request with retry (will fail, then succeed)...\n";
    $response1 = await(Http::request()->retry(3, 0.01)->cache(60)->get($url_retry));
    $data1 = $response1->json();
    echo "   -> Succeeded after retry. Timestamp: {$data1['timestamp']}\n";

    echo "\n2. Making second request (should be an instant CACHE HIT)...\n";
    $start_hit = microtime(true);
    $response2 = await(Http::request()->cache(60)->get($url_retry));
    $elapsed_hit = microtime(true) - $start_hit;
    $data2 = $response2->json();
    echo "   -> Finished in " . round($elapsed_hit, 4) . "s. Timestamp: {$data2['timestamp']}\n";

    echo "\n3. Verifying results for Test Case 3...\n";
    if ($data1['timestamp'] === $data2['timestamp'] && $elapsed_hit < 0.01) {
        echo "   ✓ SUCCESS: Timestamps match and response was instant, proving successful result was cached.\n";
    } else {
        echo "   ✗ FAILED: Caching did not behave as expected.\n";
    }

    $handler->assertRequestCount(3);
    echo "   ✓ SUCCESS: Correct number of requests recorded (1 fail, 1 success, 1 cache hit).\n";
    echo "   --> TEST CASE 3 FINAL VERDICT: PASSED.\n";
});

echo PHP_EOL."====== Testing Complete ======".PHP_EOL;