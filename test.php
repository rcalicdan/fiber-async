<?php

require 'vendor/autoload.php';

/**
 * Helper to recursively delete the default filesystem cache directory to ensure a clean test.
 */
function clearFilesystemCache()
{
    $cacheDir = getcwd() . '/cache/http';
    if (!is_dir($cacheDir)) {
        return;
    }
    echo "Clearing filesystem cache...\n";
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        ($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath());
    }
    @rmdir($cacheDir);
}

// --- Test Configuration ---
const NUM_REQUESTS = 15;
const BASE_URI = 'https://jsonplaceholder.typicode.com';

echo "====================================================\n";
echo "Starting Concurrent HTTP Caching Benchmark...\n";
echo "Performing " . NUM_REQUESTS . " concurrent requests per run.\n";
echo "====================================================\n\n";

// --- Generate the list of URLs to fetch ---
$urls = [];
for ($i = 1; $i <= NUM_REQUESTS; $i++) {
    $urls[] = BASE_URI . "/posts/{$i}";
}

// =================================================================
// BENCHMARK 1: CONCURRENT REQUESTS WITHOUT CACHING
// =================================================================
echo "--- BENCHMARK 1: CONCURRENT REQUESTS WITHOUT CACHING ---\n";

// Run 1a: Cold network requests (DNS, TLS, etc.)
$start_cold_nocache = microtime(true);
run(function () use ($urls) {
    $promises = array_map(function ($url) {
        // THE FIX: Add timeout and retry logic to make the client resilient.
        return http()->timeout(10)->retry(3, 0.5)->get($url);
    }, $urls);
    await(all($promises));
});
$time_cold_nocache = microtime(true) - $start_cold_nocache;
echo "Cold Run (Network): " . number_format($time_cold_nocache, 4) . " seconds\n";

// Run 1b: Warm network requests (reusing connections)
$start_warm_nocache = microtime(true);
run(function () use ($urls) {
    $promises = array_map(function ($url) {
        // THE FIX: Also add it here for consistency.
        return http()->timeout(10)->retry(3, 0.5)->get($url);
    }, $urls);
    await(all($promises));
});
$time_warm_nocache = microtime(true) - $start_warm_nocache;
echo "Warm Run (Network): " . number_format($time_warm_nocache, 4) . " seconds\n\n";


// =================================================================
// BENCHMARK 2: CONCURRENT REQUESTS WITH CACHING
// =================================================================
echo "--- BENCHMARK 2: CONCURRENT REQUESTS WITH CACHING ---\n";
clearFilesystemCache();

// Run 2a: Cold network requests (populating the cache)
$start_cold_cache = microtime(true);
run(function () use ($urls) {
    $promises = array_map(function ($url) {
        // THE FIX: Add it here as well for the cache-populating run.
        return http()->timeout(10)->retry(3, 0.5)->cache(3600)->get($url);
    }, $urls);
    await(all($promises));
});
$time_cold_cache = microtime(true) - $start_cold_cache;
echo "Cold Run (Populating Cache): " . number_format($time_cold_cache, 4) . " seconds\n";

// Run 2b: Warm cache requests (reading from the filesystem)
$start_warm_cache = microtime(true);
run(function () use ($urls) {
    $promises = array_map(fn($url) => http()->cache(3600)->get($url), $urls);
    await(all($promises));
});
$time_warm_cache = microtime(true) - $start_warm_cache;
echo "Warm Run (Hitting Cache):    " . number_format($time_warm_cache, 4) . " seconds\n\n";


// =================================================================
// FINAL SUMMARY
// =================================================================
echo "==================== FINAL SUMMARY ====================\n";
echo "This compares the 'warm' runs to provide the fairest comparison.\n\n";
echo "Concurrent performance WITHOUT Caching: " . number_format($time_warm_nocache, 4) . " seconds\n";
echo "Concurrent performance WITH Caching:    " . number_format($time_warm_cache, 4) . " seconds\n";

if ($time_warm_cache > 0 && $time_warm_nocache > $time_warm_cache) {
    $improvement = $time_warm_nocache / $time_warm_cache;
    echo "\nResult: Caching made the concurrent tasks " . number_format($improvement, 1) . " times faster.\n";
} else {
    echo "\nResult: No significant performance improvement was observed.\n";
}
echo "===============================================\n";