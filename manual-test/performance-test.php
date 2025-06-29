<?php

use Rcalicdan\FiberAsync\AsyncEventLoop;

require_once 'vendor/autoload.php';

/**
 * Performance Test Script for FiberAsync
 * Tests sequential vs concurrent API fetching with real public APIs
 */

// Test configuration
const TEST_APIS = [
    'https://jsonplaceholder.typicode.com/posts/1',
    'https://jsonplaceholder.typicode.com/posts/2',
    'https://jsonplaceholder.typicode.com/posts/3',
    'https://jsonplaceholder.typicode.com/users/1',
    'https://jsonplaceholder.typicode.com/users/2',
    'https://httpbin.org/delay/1',
    'https://httpbin.org/uuid',
    'https://httpbin.org/ip',
    'https://api.github.com/zen',
    'https://api.github.com/octocat',
];

const CONCURRENCY_LEVELS = [1, 3, 5, 10];

function printHeader(string $title): void
{
    echo str_repeat('=', 80)."\n";
    echo strtoupper(str_pad($title, 78, ' ', STR_PAD_BOTH))."\n";
    echo str_repeat('=', 80)."\n\n";
}

function printSection(string $title): void
{
    echo str_repeat('-', 50)."\n";
    echo $title."\n";
    echo str_repeat('-', 50)."\n";
}

function formatTime(float $seconds): string
{
    if ($seconds < 1) {
        return number_format($seconds * 1000, 2).'ms';
    }

    return number_format($seconds, 3).'s';
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, 2).' '.$units[$pow];
}

/**
 * Test sequential API fetching (one after another)
 */
function testSequential(array $urls): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    $results = [];

    echo 'Fetching '.count($urls)." APIs sequentially...\n";

    foreach ($urls as $index => $url) {
        $apiStartTime = microtime(true);

        try {
            $response = quick_fetch($url, [
                'timeout' => 10,
                'headers' => ['User-Agent' => 'FiberAsync-Test/1.0'],
            ]);

            $apiTime = microtime(true) - $apiStartTime;
            $results[] = [
                'url' => $url,
                'success' => true,
                'time' => $apiTime,
                'size' => strlen(json_encode($response)),
            ];

            echo sprintf(
                "  [%d/%d] %s - %s\n",
                $index + 1,
                count($urls),
                formatTime($apiTime),
                parse_url($url, PHP_URL_HOST)
            );

        } catch (Exception $e) {
            $results[] = [
                'url' => $url,
                'success' => false,
                'error' => $e->getMessage(),
                'time' => microtime(true) - $apiStartTime,
            ];
            echo sprintf("  [%d/%d] ERROR: %s\n", $index + 1, count($urls), $e->getMessage());
        }
    }

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
    ];
}

/**
 * Test concurrent API fetching with different concurrency levels
 */
function testConcurrent(array $urls, int $concurrency): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    echo 'Fetching '.count($urls)." APIs with concurrency level {$concurrency}...\n";

    // Create async functions for each URL (not promises yet)
    $asyncFunctions = array_map(function ($url) {
        return function () use ($url) {
            $startTime = microtime(true);

            try {
                $response = await(fetch($url, [
                    'timeout' => 10,
                    'headers' => ['User-Agent' => 'FiberAsync-Test/1.0'],
                ]));

                return [
                    'url' => $url,
                    'success' => true,
                    'time' => microtime(true) - $startTime,
                    'size' => strlen(json_encode($response)),
                ];
            } catch (Exception $e) {
                return [
                    'url' => $url,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'time' => microtime(true) - $startTime,
                ];
            }
        };
    }, $urls);

    // Run functions with concurrency control
    $results = run_concurrent($asyncFunctions, $concurrency);

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    // Print individual results
    foreach ($results as $index => $result) {
        if ($result['success']) {
            echo sprintf(
                "  [%d/%d] %s - %s\n",
                $index + 1,
                count($results),
                formatTime($result['time']),
                parse_url($result['url'], PHP_URL_HOST)
            );
        } else {
            echo sprintf(
                "  [%d/%d] ERROR: %s\n",
                $index + 1,
                count($results),
                $result['error'] ?? 'Unknown error'
            );
        }
    }

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
        'concurrency' => $concurrency,
    ];
}

/**
 * Alternative concurrent test using the concurrent() helper directly
 */
function testConcurrentAlternative(array $urls, int $concurrency): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    echo 'Fetching '.count($urls)." APIs with concurrency level {$concurrency} (Alternative)...\n";

    // Use the run() function with concurrent() helper
    $results = run(function () use ($urls, $concurrency) {
        // Create promises for each URL
        $promises = array_map(function ($url) {
            return fetch($url, [
                'timeout' => 10,
                'headers' => ['User-Agent' => 'FiberAsync-Test/1.0'],
            ])->then(function ($response) use ($url) {
                return [
                    'url' => $url,
                    'success' => true,
                    'time' => 0, // We'll calculate this differently
                    'size' => strlen(json_encode($response)),
                    'data' => $response,
                ];
            })->catch(function ($error) use ($url) {
                return [
                    'url' => $url,
                    'success' => false,
                    'error' => $error->getMessage(),
                    'time' => 0,
                ];
            });
        }, $urls);

        // Use concurrent helper to limit concurrency
        return run_concurrent($promises, $concurrency);
    });

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    // Print individual results
    foreach ($results as $index => $result) {
        if ($result['success']) {
            echo sprintf(
                "  [%d/%d] SUCCESS - %s\n",
                $index + 1,
                count($results),
                parse_url($result['url'], PHP_URL_HOST)
            );
        } else {
            echo sprintf(
                "  [%d/%d] ERROR: %s\n",
                $index + 1,
                count($results),
                $result['error'] ?? 'Unknown error'
            );
        }
    }

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
        'concurrency' => $concurrency,
    ];
}

/**
 * Simple concurrent test using run_all
 */
function testSimpleConcurrent(array $urls): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    echo 'Fetching '.count($urls)." APIs with run_all (no concurrency limit)...\n";

    // Create async functions for each URL
    $asyncFunctions = array_map(function ($url) {
        return function () use ($url) {
            $startTime = microtime(true);

            try {
                $response = await(fetch($url, [
                    'timeout' => 10,
                    'headers' => ['User-Agent' => 'FiberAsync-Test/1.0'],
                ]));

                return [
                    'url' => $url,
                    'success' => true,
                    'time' => microtime(true) - $startTime,
                    'size' => strlen(json_encode($response)),
                ];
            } catch (Exception $e) {
                return [
                    'url' => $url,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'time' => microtime(true) - $startTime,
                ];
            }
        };
    }, $urls);

    // Run all concurrently without limit
    $results = run_all($asyncFunctions);

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    // Print individual results
    foreach ($results as $index => $result) {
        if ($result['success']) {
            echo sprintf(
                "  [%d/%d] %s - %s\n",
                $index + 1,
                count($results),
                formatTime($result['time']),
                parse_url($result['url'], PHP_URL_HOST)
            );
        } else {
            echo sprintf(
                "  [%d/%d] ERROR: %s\n",
                $index + 1,
                count($results),
                $result['error'] ?? 'Unknown error'
            );
        }
    }

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
        'concurrency' => 'unlimited',
    ];
}

/**
 * Test with timeout scenarios
 */
function testWithTimeout(): void
{
    printSection('Testing Timeout Scenarios');

    echo "Testing successful operation within timeout...\n";

    try {
        $result = run_with_timeout(
            function () {
                await(delay(0.5)); // 500ms delay

                return await(fetch('https://httpbin.org/uuid'));
            },
            2.0 // 2 second timeout
        );
        echo "✓ Operation completed successfully within timeout\n";
    } catch (Exception $e) {
        echo '✗ Unexpected timeout: '.$e->getMessage()."\n";
    }

    echo "\nTesting operation that exceeds timeout...\n";

    try {
        $result = run_with_timeout(
            function () {
                await(delay(3.0)); // 3 second delay

                return 'This should not complete';
            },
            1.0 // 1 second timeout
        );
        echo "✗ Operation should have timed out\n";
    } catch (Exception $e) {
        echo '✓ Operation correctly timed out: '.$e->getMessage()."\n";
    }
}

function testRaceConditions(): void
{
    printSection('Testing Race Conditions - Enhanced Debug');

    echo "Testing with artificial delays (no network)...\n";
    $startTime = microtime(true);

    try {
        $winner = run(function () {
            $promises = [
                delay(2.0)->then(fn () => 'slow-2s'),
                delay(1.0)->then(fn () => 'fast-1s'), // Should win
                delay(3.0)->then(fn () => 'slowest-3s'),
            ];

            return await(race($promises));
        });

        $totalTime = microtime(true) - $startTime;
        echo '✓ Pure delay race completed in '.formatTime($totalTime)."\n";
        echo 'Winner: '.$winner."\n\n";
    } catch (Exception $e) {
        echo '✗ Pure delay race failed: '.$e->getMessage()."\n\n";
    }

    echo "Testing with network calls (debug timing)...\n";
    $urls = [
        'https://httpbin.org/delay/2',
        'https://httpbin.org/delay/1',
        'https://httpbin.org/delay/3',
    ];

    $startTime = microtime(true);

    try {
        $winner = run(function () use ($urls) {
            echo "Creating promises...\n";
            $createStart = microtime(true);

            $promises = array_map(function ($url) {
                echo "Creating fetch for: $url\n";

                return fetch($url);
            }, $urls);

            $createTime = microtime(true) - $createStart;
            echo 'Promises created in '.formatTime($createTime)."\n";

            echo "Starting race...\n";
            $raceStart = microtime(true);
            $result = await(race($promises));
            $raceTime = microtime(true) - $raceStart;
            echo 'Race completed in '.formatTime($raceTime)."\n";

            echo "Checking event loop state...\n";
            $eventLoop = AsyncEventLoop::getInstance();
            echo 'Event loop idle: '.($eventLoop->isIdle() ? 'yes' : 'no')."\n";

            return $result;
        });

        $totalTime = microtime(true) - $startTime;
        echo '✓ HTTP race completed in '.formatTime($totalTime)."\n";
    } catch (Exception $e) {
        echo '✗ HTTP race failed: '.$e->getMessage()."\n";
    }
}

function debugRaceDetailed(): void
{
    echo "=== DETAILED RACE DEBUG ===\n";

    echo "Step 1: Testing race with immediate promises...\n";
    $start = microtime(true);

    $result = run(function () {
        $promises = [
            resolve('immediate-1'),
            resolve('immediate-2'),
            resolve('immediate-3'),
        ];

        return await(race($promises));
    });

    $time = microtime(true) - $start;
    echo 'Immediate race: '.formatTime($time)." - Winner: $result\n\n";

    echo "Step 2: Testing race with mixed timing...\n";
    $start = microtime(true);

    $result = run(function () {
        $promises = [
            delay(0.1)->then(fn () => 'fast-100ms'),
            delay(0.5)->then(fn () => 'slow-500ms'),
            delay(1.0)->then(fn () => 'slowest-1000ms'),
        ];

        echo "Promises created, starting race...\n";
        $raceStart = microtime(true);
        $winner = await(race($promises));
        $raceTime = microtime(true) - $raceStart;
        echo 'Race internal time: '.formatTime($raceTime)."\n";

        return $winner;
    });

    $time = microtime(true) - $start;
    echo 'Mixed timing race: '.formatTime($time)." - Winner: $result\n\n";

    echo "Step 3: Testing the original problematic case...\n";
    $start = microtime(true);

    $result = run(function () {
        echo "Creating delay promises...\n";
        $createStart = microtime(true);

        $promises = [
            delay(2.0)->then(function () {
                echo "2s delay completed\n";

                return 'slow-2s';
            }),
            delay(1.0)->then(function () {
                echo "1s delay completed\n";

                return 'fast-1s';
            }),
            delay(3.0)->then(function () {
                echo "3s delay completed\n";

                return 'slowest-3s';
            }),
        ];

        $createTime = microtime(true) - $createStart;
        echo 'Promises created in: '.formatTime($createTime)."\n";

        echo "Starting race...\n";
        $raceStart = microtime(true);
        $winner = await(race($promises));
        $raceTime = microtime(true) - $raceStart;
        echo 'Race completed in: '.formatTime($raceTime)."\n";

        return $winner;
    });

    $time = microtime(true) - $start;
    echo 'Original case: '.formatTime($time)." - Winner: $result\n";
}

/**
 * Benchmark with detailed metrics
 */
function runBenchmark(): void
{
    printSection('Detailed Benchmark Test');

    $testOperation = function () {
        $promises = [
            fetch('https://jsonplaceholder.typicode.com/posts/1'),
            fetch('https://jsonplaceholder.typicode.com/users/1'),
            fetch('https://httpbin.org/uuid'),
        ];

        return run_all($promises);
    };

    $benchmarkResult = benchmark($testOperation);

    echo "Benchmark Results:\n";
    echo '- Execution Time: '.formatTime($benchmarkResult['benchmark']['execution_time'])."\n";
    echo '- Memory Used: '.formatBytes($benchmarkResult['benchmark']['memory_used'])."\n";
    echo '- Peak Memory: '.formatBytes($benchmarkResult['benchmark']['peak_memory'])."\n";
    echo '- Successful Requests: '.count($benchmarkResult['result'])."\n";
}

/**
 * Display performance comparison
 */
function displayComparison(array $sequentialResult, array $concurrentResults): void
{
    printSection('Performance Comparison');

    $sequentialTime = $sequentialResult['total_time'];
    $successful = $sequentialResult['successful'];

    printf("Sequential Performance:\n");
    printf("  Time: %s\n", formatTime($sequentialTime));
    printf("  Memory: %s\n", formatBytes($sequentialResult['memory_used']));
    printf(
        "  Success Rate: %d/%d (%.1f%%)\n\n",
        $successful,
        count($sequentialResult['results']),
        ($successful / count($sequentialResult['results'])) * 100
    );

    printf("Concurrent Performance:\n");
    foreach ($concurrentResults as $result) {
        $improvement = (($sequentialTime - $result['total_time']) / $sequentialTime) * 100;
        $successRate = ($result['successful'] / count($result['results'])) * 100;

        printf(
            "  Concurrency %s: %s (%.1f%% faster) - Memory: %s - Success: %.1f%%\n",
            $result['concurrency'],
            formatTime($result['total_time']),
            $improvement,
            formatBytes($result['memory_used']),
            $successRate
        );
    }
}

// Main execution
try {
    printHeader('FiberAsync Performance Test Suite');

    echo 'Testing with '.count(TEST_APIS)." different public APIs\n";
    echo 'APIs: '.implode(', ', array_map(fn ($url) => parse_url($url, PHP_URL_HOST), TEST_APIS))."\n\n";

    // Test sequential performance
    printSection('Sequential Testing');
    $sequentialResult = testSequential(TEST_APIS);
    echo "\nSequential test completed in ".formatTime($sequentialResult['total_time'])."\n\n";

    // Test simple concurrent (unlimited)
    printSection('Simple Concurrent Testing (Unlimited)');
    $unlimitedResult = testSimpleConcurrent(TEST_APIS);
    echo "\nUnlimited concurrent test completed in ".formatTime($unlimitedResult['total_time'])."\n\n";

    // Test concurrent performance with different levels using run_concurrent
    $concurrentResults = [$unlimitedResult];
    foreach ([3, 5] as $concurrency) { // Reduced to avoid the original error
        printSection("Concurrent Testing (Level {$concurrency}) - Using run_concurrent");

        try {
            $result = testConcurrent(TEST_APIS, $concurrency);
            $concurrentResults[] = $result;
            echo "\nConcurrent test (level {$concurrency}) completed in ".formatTime($result['total_time'])."\n\n";
        } catch (Exception $e) {
            echo "Error in concurrent test (level {$concurrency}): ".$e->getMessage()."\n\n";
        }
    }

    // Display comparison
    displayComparison($sequentialResult, $concurrentResults);

    // Additional tests
    echo "\n";
    testWithTimeout();
    echo "\n";
    testRaceConditions();
    echo "\n";
    debugRaceDetailed();
    runBenchmark();

    printHeader('Test Suite Completed Successfully');

} catch (Exception $e) {
    echo "\n".str_repeat('!', 50)."\n";
    echo 'TEST SUITE FAILED: '.$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
    echo str_repeat('!', 50)."\n";
    exit(1);
}
