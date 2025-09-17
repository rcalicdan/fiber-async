<?php

// Include Composer's autoloader
require __DIR__ . '/vendor/autoload.php';

// If you have a separate helpers file, include it.
// If your functions are autoloaded via composer.json, this is not needed.
if (file_exists(__DIR__ . '/app/helpers.php')) {
    require __DIR__ . '/app/helpers.php';
}

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

// Use Task::run to create the async context
Task::run(function () {
    // We'll use a static URL for Berlin to ensure it's identical every time.
    $url = "https://api.open-meteo.com/v1/forecast?latitude=52.52&longitude=13.41&current_weather=true";
    echo "Testing cache with URL: $url\n\n";

    // --- FIRST REQUEST ---
    echo "1. Making first request (this should be SLOW and populate the cache)...\n";
    $start1 = microtime(true);
    $response1 = await(
        http()->cache(60)->get($url) // Cache for 60 seconds
    );
    $end1 = microtime(true);
    $time1 = ($end1 - $start1) * 1000;

    echo "   - Status: " . $response1->status() . "\n";
    echo "   - Is Cached: " . ($response1->header('X-Cache-Hit') ? 'Yes' : 'No') . "\n";
    echo "   - Time taken: " . round($time1, 2) . " ms\n\n";

    // Small pause to make the time difference obvious
    sleep(1);

    // --- SECOND REQUEST ---
    echo "2. Making second request (this should be VERY FAST and hit the cache)...\n";
    $start2 = microtime(true);
    $response2 = await(
        http()->cache(60)->get($url)
    );
    $end2 = microtime(true);
    $time2 = ($end2 - $start2) * 1000;

    echo "   - Status: " . $response2->status() . "\n";
    echo "   - Is Cached: " . ($response2->header('X-Cache-Hit') ? 'Yes' : 'No') . "\n";
    echo "   - Time taken: " . round($time2, 2) . " ms\n\n";

    // --- VERIFICATION ---
    if ($time2 < 50 && $time2 < ($time1 / 2)) {
        echo "✅ SUCCESS: The second request was significantly faster. Caching is working correctly!\n";
    } else {
        echo "❌ FAILED: The second request was not significantly faster. See troubleshooting below.\n";
    }
});