<?php

use Rcalicdan\FiberAsync\Http\Uri;

require 'vendor/autoload.php';

// ======================= TEST CONFIGURATION =======================
// We define our test targets here. It's easy to add more!
$testApis = [
    [
        'name' => 'PokéAPI (Larger JSON Payloads)',
        'baseUri' => 'https://pokeapi.co/api/v2',
        'resourcePathFormat' => '/pokemon/%d', // e.g., /pokemon/1, /pokemon/2
        'requests' => 25, // Let's use a slightly higher number
    ],
    [
        'name' => 'JSONPlaceholder (Simple JSON Payloads)',
        'baseUri' => 'https://jsonplaceholder.typicode.com',
        'resourcePathFormat' => '/todos/%d', // e.g., /todos/1, /todos/2
        'requests' => 25,
    ],
];
// =================================================================

/**
 * A reusable function to run our concurrent fetch test for a specific HTTP version.
 *
 * @param string $version The protocol version to use ('1.1' or '2.0').
 * @param array $urls The list of UriInterface objects to fetch.
 * @return float The total execution time in seconds.
 */
function runBenchmark(string $version, array $urls): float
{
    echo "--- Testing with HTTP/{$version} ---\n";
    $startTime = microtime(true);

    $responses = run(function () use ($version, $urls) {
        $promises = array_map(function (Uri $uri) use ($version) {
            return http()
                ->withProtocolVersion($version)
                ->get((string)$uri);
        }, $urls);

        return await(all($promises));
    });

    echo "Received " . count($responses) . " responses successfully.\n";

    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    echo "Time taken: " . number_format($duration, 4) . " seconds\n\n";
    return $duration;
}


// --- Main Execution Loop ---
foreach ($testApis as $apiConfig) {
    $line = str_repeat('=', 60);
    echo "{$line}\n";
    echo "BENCHMARKING: {$apiConfig['name']}\n";
    echo "{$line}\n";

    echo "Preparing to fetch {$apiConfig['requests']} resources from {$apiConfig['baseUri']}...\n\n";

    // --- Perform a single warm-up request for this specific domain ---
    echo "--- Performing warm-up request... ---\n";
    run(fn() => await(http()->get($apiConfig['baseUri'])));
    echo "Warm-up complete. Starting benchmark.\n\n";
    sleep(1); // Small pause to let the network settle.

    // --- Generate the URLs for this API test ---
    $urls = [];
    for ($i = 1; $i <= $apiConfig['requests']; $i++) {
        $path = sprintf($apiConfig['resourcePathFormat'], $i);
        $urls[] = (new Uri($apiConfig['baseUri']))->withPath($path);
    }

    // --- Run the benchmarks ---
    // We run HTTP/1.1 first this time to give HTTP/2 the "warm connection" advantage
    $http2_duration = runBenchmark('2.0', $urls);
    $http1_duration = runBenchmark('1.1', $urls);


    // --- Per-API Summary ---
    echo "-------------------- Summary for {$apiConfig['name']} --------------------\n";
    echo "HTTP/1.1 Total Time: " . number_format($http1_duration, 4) . " seconds\n";
    echo "HTTP/2 Total Time:   " . number_format($http2_duration, 4) . " seconds\n";

    if ($http2_duration < $http1_duration && $http2_duration > 0) {
        $improvement = ($http1_duration / $http2_duration);
        echo "Result: HTTP/2 was " . number_format($improvement, 2) . " times faster.\n";
    } else {
        echo "Result: No significant performance improvement was observed for this API.\n";
    }
    echo "\n\n";
}
