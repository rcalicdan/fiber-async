<?php

use Rcalicdan\FiberAsync\Api\Async; // adjust if your facade namespace differs
require 'vendor/autoload.php';

// 10 endpoints to test
$urls = [
    'posts'     => 'https://jsonplaceholder.typicode.com/posts/1',
    'users'     => 'https://jsonplaceholder.typicode.com/users/1',
    'albums'    => 'https://jsonplaceholder.typicode.com/albums/1',
    'comments'  => 'https://jsonplaceholder.typicode.com/comments/1',
    'todos'     => 'https://jsonplaceholder.typicode.com/todos/1',
    'posts2'    => 'https://jsonplaceholder.typicode.com/posts/2',
    'users2'    => 'https://jsonplaceholder.typicode.com/users/2',
    'albums2'   => 'https://jsonplaceholder.typicode.com/albums/2',
    'comments2' => 'https://jsonplaceholder.typicode.com/comments/2',
    'todos2'    => 'https://jsonplaceholder.typicode.com/todos/2',
];

function measure(string $label, callable $fn): array {
    // Force a clean slate
    gc_collect_cycles();

    // Record baseline memory
    $memStart = memory_get_usage(false);

    // Run & time
    $t0 = microtime(true);
    $results = $fn();
    $duration = microtime(true) - $t0;

    // Record post-run and peak memory
    $memEnd  = memory_get_usage(false);
    $memPeak = memory_get_peak_usage(false);

    // Convert to KB
    $startKB = $memStart / 1024;
    $endKB   = $memEnd   / 1024;
    $peakKB  = $memPeak  / 1024;

    printf(
        "%-25s : %7.4f s | Start: %6.1f KB | End: %6.1f KB | Peak: %6.1f KB | Count: %d\n\n",
        $label,
        $duration,
        $startKB,
        $endKB,
        $peakKB,
        count($results)
    );

    return $results;
}

// Run “concurrent(3)” test first
$resultsCon3 = measure('FiberAsync concurrent(3)', function() use ($urls) {
    return run(function() use ($urls) {
        $tasks = [];
        foreach ($urls as $key => $url) {
            $tasks[$key] = function() use ($key, $url) {
                $resp = await(fetch($url));
                return [
                    'type'   => $key,
                    'status' => $resp->status(),
                    'body'   => $resp->json(),
                ];
            };
        }
        return await(concurrent($tasks, 3));
    });
});

// Then run “all” test
$resultsAll = measure('FiberAsync all', function() use ($urls) {
    return run(function() use ($urls) {
        $tasks = [];
        foreach ($urls as $key => $url) {
            $tasks[$key] = function() use ($key, $url) {
                $resp = await(fetch($url));
                return [
                    'type'   => $key,
                    'status' => $resp->status(),
                    'body'   => $resp->json(),
                ];
            };
        }
        return await(all($tasks));
    });
});

// Helper to print results
function printResults(array $results, string $label): void {
    echo "=== Results from {$label} ===\n";
    foreach ($results as $item) {
        printf(
            "%-10s | Status: %3d | Data: %s\n",
            $item['type'],
            $item['status'],
            isset($item['body']['title'])   ? $item['body']['title']
          : (isset($item['body']['name'])    ? $item['body']['name']
          : json_encode($item['body']))
        );
    }
    echo "\n";
}

// Print and verify in same order: concurrent then all
printResults($resultsCon3, 'FiberAsync concurrent(3)');
printResults($resultsAll,  'FiberAsync all');
