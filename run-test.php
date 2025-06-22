<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

// Include helper functions
require_once __DIR__ . '/src/Helpers/async_helper.php';
require_once __DIR__ . '/src/Helpers/loop_helper.php';

echo "🚀 Testing CONCURRENT vs SEQUENTIAL Performance with Public APIs\n";
echo str_repeat("=", 70) . "\n\n";

function benchmark_execution($name, callable $callback)
{
    echo "📊 Testing: {$name}\n";
    $start = microtime(true);
    try {
        $result = $callback();
        $end = microtime(true);
        $duration = round($end - $start, 2);
        echo "   ✅ Completed in {$duration} seconds\n";
        echo "   📈 Results: " . (is_array($result) ? count($result) . " responses" : "success") . "\n\n";
        return ['duration' => $duration, 'result' => $result];
    } catch (Exception $e) {
        $end = microtime(true);
        $duration = round($end - $start, 2);
        echo "   ❌ Failed in {$duration} seconds: " . $e->getMessage() . "\n\n";
        return ['duration' => $duration, 'error' => $e->getMessage()];
    }
}

// Wrap the entire test suite in an async function and run it
$mainTest = async(function () {

    // Test 1: Sequential vs Concurrent - Basic HTTP Requests
    echo "1️⃣ SEQUENTIAL vs CONCURRENT - BASIC HTTP REQUESTS\n";
    echo str_repeat("-", 50) . "\n";

    $urls = [
        'https://jsonplaceholder.typicode.com/posts/1',
        'https://jsonplaceholder.typicode.com/users/1',
        'https://jsonplaceholder.typicode.com/albums/1',
        'https://jsonplaceholder.typicode.com/comments/1',
        'https://jsonplaceholder.typicode.com/photos/1'
    ];

    // Sequential execution
    $sequentialBasicResults = benchmark_execution("Sequential HTTP requests", function () use ($urls) {
        $results = [];
        foreach ($urls as $index => $url) {
            echo "   🔄 Sequential request " . ($index + 1) . "\n";
            $response = await(fetch($url));
            echo "   ✅ Sequential request " . ($index + 1) . " completed\n";
            $results[] = ['index' => $index, 'status' => $response['status'], 'url' => $url];
        }
        return $results;
    });

    // Concurrent execution
    $concurrentBasicResults = benchmark_execution("Concurrent HTTP requests", function () use ($urls) {
        $tasks = [];
        foreach ($urls as $index => $url) {
            $tasks[] = function () use ($url, $index) {
                echo "   🔄 Concurrent request " . ($index + 1) . "\n";
                $response = await(fetch($url));
                echo "   ✅ Concurrent request " . ($index + 1) . " completed\n";
                return ['index' => $index, 'status' => $response['status'], 'url' => $url];
            };
        }
        return runConcurrent($tasks, 3);
    });

    // Test 2: Sequential vs Concurrent - Mixed Operations
    echo "2️⃣ SEQUENTIAL vs CONCURRENT - MIXED OPERATIONS\n";
    echo str_repeat("-", 50) . "\n";

    // Sequential mixed operations
    $sequentialMixedResults = benchmark_execution("Sequential mixed operations", function () {
        $results = [];

        // Delay operation
        echo "   🔄 Sequential delay 1\n";
        await(delay(0.5));
        echo "   ✅ Sequential delay 1 completed\n";
        $results[] = ['type' => 'delay', 'duration' => 0.5];

        // HTTP request
        echo "   🔄 Sequential HTTP request\n";
        $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
        echo "   ✅ Sequential HTTP request completed\n";
        $results[] = ['type' => 'http', 'status' => $response['status']];

        // Another delay
        echo "   🔄 Sequential delay 2\n";
        await(delay(0.3));
        echo "   ✅ Sequential delay 2 completed\n";
        $results[] = ['type' => 'delay', 'duration' => 0.3];

        // Another HTTP request
        echo "   🔄 Sequential HTTP request 2\n";
        $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
        echo "   ✅ Sequential HTTP request 2 completed\n";
        $results[] = ['type' => 'http', 'status' => $response['status']];

        return $results;
    });

    // Concurrent mixed operations
    $concurrentMixedResults = benchmark_execution("Concurrent mixed operations", function () {
        $tasks = [
            'delay1' => function () {
                echo "   🔄 Concurrent delay 1\n";
                await(delay(0.5));
                echo "   ✅ Concurrent delay 1 completed\n";
                return ['type' => 'delay', 'duration' => 0.5];
            },
            'http1' => function () {
                echo "   🔄 Concurrent HTTP request 1\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                echo "   ✅ Concurrent HTTP request 1 completed\n";
                return ['type' => 'http', 'status' => $response['status']];
            },
            'delay2' => function () {
                echo "   🔄 Concurrent delay 2\n";
                await(delay(0.3));
                echo "   ✅ Concurrent delay 2 completed\n";
                return ['type' => 'delay', 'duration' => 0.3];
            },
            'http2' => function () {
                echo "   🔄 Concurrent HTTP request 2\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Concurrent HTTP request 2 completed\n";
                return ['type' => 'http', 'status' => $response['status']];
            }
        ];

        return runConcurrent($tasks, 4);
    });

    // Test 3: Sequential vs Concurrent - Delay-Heavy Operations
    echo "3️⃣ SEQUENTIAL vs CONCURRENT - DELAY-HEAVY OPERATIONS\n";
    echo str_repeat("-", 50) . "\n";

    // Sequential delays
    $sequentialDelayResults = benchmark_execution("Sequential delays", function () {
        $results = [];
        $delays = [0.3, 0.2, 0.4, 0.1, 0.3];

        foreach ($delays as $index => $delayTime) {
            echo "   🔄 Sequential delay " . ($index + 1) . " ({$delayTime}s)\n";
            await(delay($delayTime));
            echo "   ✅ Sequential delay " . ($index + 1) . " completed\n";
            $results[] = ['index' => $index, 'delay' => $delayTime];
        }
        return $results;
    });

    // Concurrent delays
    $concurrentDelayResults = benchmark_execution("Concurrent delays", function () {
        $delays = [0.3, 0.2, 0.4, 0.1, 0.3];
        $tasks = [];

        foreach ($delays as $index => $delayTime) {
            $tasks[] = function () use ($index, $delayTime) {
                echo "   🔄 Concurrent delay " . ($index + 1) . " ({$delayTime}s)\n";
                await(delay($delayTime));
                echo "   ✅ Concurrent delay " . ($index + 1) . " completed\n";
                return ['index' => $index, 'delay' => $delayTime];
            };
        }

        return runConcurrent($tasks, 5);
    });

    // Test 4: runConcurrent with string keys
    echo "4️⃣ RUNCONCURRENT WITH STRING KEYS\n";
    echo str_repeat("-", 40) . "\n";

    $stringKeyResults = benchmark_execution("runConcurrent with string keys", function () {
        $tasks = [
            'posts' => function () {
                echo "   🔄 Fetching posts\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                echo "   ✅ Posts completed\n";
                return ['type' => 'posts', 'status' => $response['status']];
            },
            'users' => function () {
                echo "   🔄 Fetching users\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Users completed\n";
                return ['type' => 'users', 'status' => $response['status']];
            },
            'albums' => function () {
                echo "   🔄 Fetching albums\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/albums/1'));
                echo "   ✅ Albums completed\n";
                return ['type' => 'albums', 'status' => $response['status']];
            }
        ];

        return runConcurrent($tasks, 3);
    });

    // Test 5: High concurrency test
    echo "5️⃣ HIGH CONCURRENCY TEST\n";
    echo str_repeat("-", 40) . "\n";

    $highConcurrencyResults = benchmark_execution("High concurrency (10 tasks, limit 4)", function () {
        $tasks = [];
        for ($i = 1; $i <= 10; $i++) {
            $taskName = "task_$i";
            $tasks[$taskName] = function () use ($i) {
                echo "   🔄 Starting task $i\n";

                // Mix of delays and HTTP requests
                if ($i % 2 === 0) {
                    await(delay(0.2));
                    $response = await(fetch("https://jsonplaceholder.typicode.com/posts/$i"));
                    echo "   ✅ Task $i (HTTP) completed\n";
                    return ['task' => $i, 'type' => 'http', 'status' => $response['status']];
                } else {
                    await(delay(0.5));
                    echo "   ✅ Task $i (delay) completed\n";
                    return ['task' => $i, 'type' => 'delay'];
                }
            };
        }

        return runConcurrent($tasks, 4);
    });

    // Test 6: Mixed promise types
    echo "6️⃣ MIXED PROMISE TYPES TEST\n";
    echo str_repeat("-", 40) . "\n";

    $mixedPromiseResults = benchmark_execution("Mixed promise types", function () {
        $tasks = [
            'direct_promise' => fetch('https://jsonplaceholder.typicode.com/posts/1'),
            'async_function' => function () {
                echo "   🔄 Async function executing\n";
                await(delay(0.1));
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Async function completed\n";
                return ['type' => 'async_function', 'status' => $response['status']];
            },
            'simple_delay' => delay(0.3)
        ];

        return runConcurrent($tasks, 3);
    });

    // Test 7: Error handling in concurrent execution
    echo "7️⃣ ERROR HANDLING TEST\n";
    echo str_repeat("-", 40) . "\n";

    $errorHandlingResults = benchmark_execution("Error handling in concurrent", function () {
        $tasks = [
            'valid_request' => function () {
                echo "   🔄 Valid request starting\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                echo "   ✅ Valid request completed\n";
                return ['type' => 'valid', 'status' => $response['status']];
            },
            'invalid_request' => function () {
                echo "   🔄 Invalid request starting\n";
                try {
                    $response = await(fetch('https://invalid-domain-12345.com/api/test'));
                    echo "   ✅ Invalid request unexpectedly succeeded\n";
                    return ['type' => 'invalid', 'status' => $response['status']];
                } catch (Exception $e) {
                    echo "   ⚠️  Invalid request properly failed\n";
                    return ['type' => 'invalid', 'error' => 'handled'];
                }
            },
            'another_valid' => function () {
                echo "   🔄 Another valid request starting\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Another valid request completed\n";
                return ['type' => 'valid2', 'status' => $response['status']];
            }
        ];

        return runConcurrent($tasks, 3);
    });

    // Test 8: Varying Concurrency Limits
    echo "8️⃣ VARYING CONCURRENCY LIMITS TEST\n";
    echo str_repeat("-", 50) . "\n";

    $varyingConcurrencyResults = [];

    // Define a consistent workload for testing different limits
    $testWorkload = function ($concurrencyLimit) {
        $tasks = [];

        // Create 20 mixed tasks for a substantial workload
        for ($i = 1; $i <= 20; $i++) {
            $taskName = "task_$i";
            $tasks[$taskName] = function () use ($i) {
                echo "   🔄 Starting task $i (limit test)\n";

                // Mix of different operation types
                switch ($i % 4) {
                    case 0: // HTTP request
                        $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 10 + 1)));
                        echo "   ✅ Task $i (HTTP) completed\n";
                        return ['task' => $i, 'type' => 'http', 'status' => $response['status']];

                    case 1: // Short delay
                        await(delay(0.2));
                        echo "   ✅ Task $i (short delay) completed\n";
                        return ['task' => $i, 'type' => 'short_delay', 'duration' => 0.2];

                    case 2: // Medium delay
                        await(delay(0.5));
                        echo "   ✅ Task $i (medium delay) completed\n";
                        return ['task' => $i, 'type' => 'medium_delay', 'duration' => 0.5];

                    case 3: // HTTP + delay combo
                        await(delay(0.1));
                        $response = await(fetch("https://jsonplaceholder.typicode.com/users/" . ($i % 10 + 1)));
                        echo "   ✅ Task $i (HTTP+delay) completed\n";
                        return ['task' => $i, 'type' => 'combo', 'status' => $response['status']];
                }
            };
        }

        return runConcurrent($tasks, $concurrencyLimit);
    };

    // Test different concurrency limits
    $concurrencyLimits = [1, 2, 4, 6, 8, 10, 15, 20];

    foreach ($concurrencyLimits as $limit) {
        $testName = "Concurrency Limit: $limit";

        $result = benchmark_execution($testName, function () use ($testWorkload, $limit) {
            return $testWorkload($limit);
        });

        $varyingConcurrencyResults[$limit] = $result;
    }

    // Test 9: Concurrency Limit Analysis with Different Workload Sizes
    echo "9️⃣ WORKLOAD SIZE vs CONCURRENCY LIMIT\n";
    echo str_repeat("-", 50) . "\n";

    $workloadSizeResults = [];

    // Test different workload sizes with optimal concurrency
    $workloadSizes = [5, 10, 20, 30];
    $optimalConcurrency = 6; // Based on previous results

    foreach ($workloadSizes as $workloadSize) {
        $testName = "Workload Size: $workloadSize tasks";

        $result = benchmark_execution($testName, function () use ($workloadSize, $optimalConcurrency) {
            $tasks = [];

            for ($i = 1; $i <= $workloadSize; $i++) {
                $taskName = "task_$i";
                $tasks[$taskName] = function () use ($i) {
                    // Simplified mixed workload
                    if ($i % 2 === 0) {
                        $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 10 + 1)));
                        return ['task' => $i, 'type' => 'http', 'status' => $response['status']];
                    } else {
                        await(delay(0.3));
                        return ['task' => $i, 'type' => 'delay', 'duration' => 0.3];
                    }
                };
            }

            return runConcurrent($tasks, $optimalConcurrency);
        });

        $workloadSizeResults[$workloadSize] = $result;
    }

    // Performance Summary with Comparisons
    echo "📈 PERFORMANCE SUMMARY & COMPARISONS\n";
    echo str_repeat("=", 70) . "\n";

    $results = [
        'Sequential HTTP' => $sequentialBasicResults,
        'Concurrent HTTP' => $concurrentBasicResults,
        'Sequential Mixed' => $sequentialMixedResults,
        'Concurrent Mixed' => $concurrentMixedResults,
        'Sequential Delays' => $sequentialDelayResults,
        'Concurrent Delays' => $concurrentDelayResults,
        'String Keys' => $stringKeyResults,
        'High Concurrency' => $highConcurrencyResults,
        'Mixed Promises' => $mixedPromiseResults,
        'Error Handling' => $errorHandlingResults
    ];

    foreach ($results as $testName => $result) {
        if (isset($result['duration'])) {
            echo "$testName: {$result['duration']}s\n";
        } else {
            echo "$testName: Failed\n";
        }
    }

    // Performance comparisons
    echo "\n🔥 PERFORMANCE IMPROVEMENTS\n";
    echo str_repeat("-", 40) . "\n";

    $comparisons = [
        'HTTP Requests' => [$sequentialBasicResults, $concurrentBasicResults],
        'Mixed Operations' => [$sequentialMixedResults, $concurrentMixedResults],
        'Delay Operations' => [$sequentialDelayResults, $concurrentDelayResults]
    ];

    foreach ($comparisons as $comparisonName => $comparison) {
        [$sequential, $concurrent] = $comparison;

        if (isset($sequential['duration']) && isset($concurrent['duration'])) {
            $improvement = round(($sequential['duration'] / $concurrent['duration']), 2);
            $timeSaved = round($sequential['duration'] - $concurrent['duration'], 2);
            echo "{$comparisonName}:\n";
            echo "  • Sequential: {$sequential['duration']}s\n";
            echo "  • Concurrent: {$concurrent['duration']}s\n";
            echo "  • Improvement: {$improvement}x faster\n";
            echo "  • Time saved: {$timeSaved}s\n\n";
        }
    }

    echo "💾 MEMORY USAGE\n";
    echo str_repeat("-", 30) . "\n";
    echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "Current memory usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n\n";

    echo "🎉 Concurrent vs Sequential performance comparison completed!\n";
    echo "💡 Key takeaways:\n";
    echo "   • HTTP requests: Concurrent execution shines with I/O operations\n";
    echo "   • Delays: Maximum benefit when operations can overlap\n";
    echo "   • Mixed operations: Best performance when combining different async tasks\n";

    echo "🔬 CONCURRENCY LIMIT ANALYSIS\n";
    echo str_repeat("=", 70) . "\n";

    echo "📊 Performance by Concurrency Limit (20 tasks):\n";
    echo str_repeat("-", 50) . "\n";

    $bestTime = PHP_FLOAT_MAX;
    $bestLimit = 0;
    $worstTime = 0;
    $worstLimit = 0;

    foreach ($varyingConcurrencyResults as $limit => $result) {
        if (isset($result['duration'])) {
            $duration = $result['duration'];
            echo "Limit $limit: {$duration}s";

            if ($duration < $bestTime) {
                $bestTime = $duration;
                $bestLimit = $limit;
            }

            if ($duration > $worstTime) {
                $worstTime = $duration;
                $worstLimit = $limit;
            }

            // Calculate efficiency (tasks per second)
            $efficiency = round(20 / $duration, 2);
            echo " (${efficiency} tasks/sec)\n";
        } else {
            echo "Limit $limit: Failed\n";
        }
    }

    echo "\n🏆 OPTIMAL CONCURRENCY ANALYSIS:\n";
    echo str_repeat("-", 40) . "\n";
    echo "Best performance: Limit $bestLimit with {$bestTime}s\n";
    echo "Worst performance: Limit $worstLimit with {$worstTime}s\n";

    if ($bestLimit > 0 && $worstLimit > 0) {
        $improvement = round($worstTime / $bestTime, 2);
        echo "Improvement: {$improvement}x faster at optimal limit\n";
    }

    // Analyze the sweet spot
    echo "\n📈 CONCURRENCY SWEET SPOT ANALYSIS:\n";
    echo str_repeat("-", 40) . "\n";

    $efficiencyData = [];
    foreach ($varyingConcurrencyResults as $limit => $result) {
        if (isset($result['duration'])) {
            $efficiencyData[$limit] = 20 / $result['duration'];
        }
    }

    // Find diminishing returns point
    $previousEfficiency = 0;
    $diminishingPoint = 0;

    foreach ($efficiencyData as $limit => $efficiency) {
        if ($previousEfficiency > 0) {
            $improvement = ($efficiency - $previousEfficiency) / $previousEfficiency * 100;
            echo "Limit $limit: " . round($improvement, 1) . "% improvement over limit " . ($limit - 2) . "\n";

            if ($improvement < 10 && $diminishingPoint === 0) { // Less than 10% improvement
                $diminishingPoint = $limit;
            }
        }
        $previousEfficiency = $efficiency;
    }

    if ($diminishingPoint > 0) {
        echo "\n💡 Diminishing returns start around limit: $diminishingPoint\n";
    }

    echo "\n📊 WORKLOAD SCALING ANALYSIS:\n";
    echo str_repeat("-", 40) . "\n";

    foreach ($workloadSizeResults as $size => $result) {
        if (isset($result['duration'])) {
            $duration = $result['duration'];
            $tasksPerSecond = round($size / $duration, 2);
            echo "Size $size: {$duration}s ({$tasksPerSecond} tasks/sec)\n";
        }
    }

    // Calculate scaling efficiency
    $baseSize = 5;
    $baseResult = $workloadSizeResults[$baseSize] ?? null;

    if ($baseResult && isset($baseResult['duration'])) {
        echo "\n🔍 SCALING EFFICIENCY (vs $baseSize tasks baseline):\n";
        echo str_repeat("-", 40) . "\n";

        $baseEfficiency = $baseSize / $baseResult['duration'];

        foreach ($workloadSizeResults as $size => $result) {
            if ($size === $baseSize || !isset($result['duration'])) continue;

            $currentEfficiency = $size / $result['duration'];
            $scalingFactor = round($currentEfficiency / $baseEfficiency, 2);
            $expectedDuration = $size / $baseEfficiency;
            $actualDuration = $result['duration'];
            $overhead = round((($actualDuration - $expectedDuration) / $expectedDuration) * 100, 1);

            echo "Size $size: {$scalingFactor}x baseline efficiency";
            if ($overhead > 0) {
                echo " (+{$overhead}% overhead)";
            } else {
                echo " ({$overhead}% overhead)";
            }
            echo "\n";
        }
    }

    echo "\n💾 MEMORY USAGE BY CONCURRENCY:\n";
    echo str_repeat("-", 40) . "\n";
    echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "Current memory usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

    echo "\n🎯 RECOMMENDATIONS:\n";
    echo str_repeat("-", 30) . "\n";
    echo "• Optimal concurrency limit appears to be around: $bestLimit\n";
    echo "• For I/O-heavy workloads, consider limits between 4-8\n";
    echo "• Monitor memory usage with high concurrency limits\n";
    echo "• Test with your specific API rate limits in mind\n";

    // Add this to your key takeaways section:
    echo "\n💡 Additional insights:\n";
    echo "   • Concurrency limit: Sweet spot around $bestLimit for this workload\n";
    echo "   • Scaling: Efficiency may decrease with very large workloads\n";
    echo "   • Memory: Stays reasonable even with high concurrency\n";
});

run($mainTest());
