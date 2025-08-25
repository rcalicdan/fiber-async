<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

echo "====== Testing Cache Behavior with Http::fetch() ======\n";

Task::run(function () {
    $handler = Http::startTesting();
    $handler->reset();

    $url = 'https://api.example.com/fetch-cache-test';

    Http::mock('GET')
        ->url($url)
        ->json(['data' => 'live data from fetch', 'timestamp' => time()])
        ->delay(1.5)
        ->persistent()
        ->register()
    ;

    echo "1. Making the first fetch() call (expecting a CACHE MISS)...\n";
    $start1 = microtime(true);

    // Use the `fetch` function with the `cache` option set to true.
    $response1 = await(Http::fetch($url, [
        'cache' => true, // Use default cache TTL
    ]));

    $elapsed1 = microtime(true) - $start1;
    $data1 = $response1->json();
    echo '   -> Finished in '.round($elapsed1, 4)."s.\n";

    echo "\n2. Making the second fetch() call (expecting a CACHE HIT)...\n";
    $start2 = microtime(true);

    $response2 = await(Http::fetch($url, [
        'cache' => true,
    ]));

    $elapsed2 = microtime(true) - $start2;
    $data2 = $response2->json();
    echo '   -> Finished in '.round($elapsed2, 4)."s.\n";

    echo "\n3. Verifying results...\n";

    if ($elapsed1 >= 1.5 && $elapsed2 < 0.01) {
        echo "   ✓ SUCCESS: Timings are correct. The first call was slow (miss) and the second was instant (hit).\n";
    } else {
        echo "   ✗ FAILED: The timing difference was not as expected.\n";
    }

    if ($data1['timestamp'] === $data2['timestamp']) {
        echo "   ✓ SUCCESS: Both responses returned the same timestamped content from cache.\n";
    } else {
        echo "   ✗ FAILED: Response bodies do not match.\n";
    }

    // Assert that the mock was only hit ONCE.
    // The history shows the initial 'GET' and the subsequent 'GET (FROM CACHE)'.
    $handler->assertRequestCount(2);
    echo "   ✓ SUCCESS: Correct number of requests recorded (1 miss, 1 hit).\n";
});

echo "\n====== Fetch Caching Test Complete ======\n";
