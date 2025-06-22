<?php

use Rcalicdan\FiberAsync\Facades\Async;

beforeEach(function () {
    resetEventLoop();
});

describe('Async Performance Benchmarks', function () {

    test('sequential vs concurrent HTTP requests performance', function () {
        $urls = [
            'https://jsonplaceholder.typicode.com/posts/1',
            'https://jsonplaceholder.typicode.com/users/1',
            'https://jsonplaceholder.typicode.com/albums/1',
            'https://jsonplaceholder.typicode.com/comments/1',
            'https://jsonplaceholder.typicode.com/photos/1'
        ];

        // Test sequential execution
        $sequentialStart = microtime(true);
        $sequentialResults = run(function () use ($urls) {
            $results = [];
            foreach ($urls as $index => $url) {
                $response = await(fetch($url));
                $results[] = ['index' => $index, 'status' => $response['status'], 'url' => $url];
            }
            return $results;
        });
        $sequentialTime = microtime(true) - $sequentialStart;

        // Test concurrent execution
        $concurrentStart = microtime(true);
        $concurrentResults = run(function () use ($urls) {
            $tasks = [];
            foreach ($urls as $index => $url) {
                $tasks[] = function () use ($url, $index) {
                    $response = await(fetch($url));
                    return ['index' => $index, 'status' => $response['status'], 'url' => $url];
                };
            }
            return runConcurrent($tasks, 3);
        });
        $concurrentTime = microtime(true) - $concurrentStart;

        // Assertions
        expect($sequentialResults)->toHaveCount(5);
        expect($concurrentResults)->toHaveCount(5);
        expect($concurrentTime)->toBeLessThan($sequentialTime);

        $improvement = round($sequentialTime / $concurrentTime, 2);
        echo "\n📊 HTTP Requests Performance:\n";
        echo "  • Sequential: {$sequentialTime}s\n";
        echo "  • Concurrent: {$concurrentTime}s\n";
        echo "  • Improvement: {$improvement}x faster\n";

        // Performance should be at least 1.5x better
        expect($improvement)->toBeGreaterThan(1.5);
    });

    test('sequential vs concurrent mixed operations performance', function () {
        // Sequential mixed operations
        $sequentialStart = microtime(true);
        $sequentialResults = run(function () {
            $results = [];

            await(delay(0.2));
            $results[] = ['type' => 'delay', 'duration' => 0.2];

            $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
            $results[] = ['type' => 'http', 'status' => $response['status']];

            await(delay(0.1));
            $results[] = ['type' => 'delay', 'duration' => 0.1];

            $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
            $results[] = ['type' => 'http', 'status' => $response['status']];

            return $results;
        });
        $sequentialTime = microtime(true) - $sequentialStart;

        // Concurrent mixed operations
        $concurrentStart = microtime(true);
        $concurrentResults = run(function () {
            $tasks = [
                'delay1' => function () {
                    await(delay(0.2));
                    return ['type' => 'delay', 'duration' => 0.2];
                },
                'http1' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                    return ['type' => 'http', 'status' => $response['status']];
                },
                'delay2' => function () {
                    await(delay(0.1));
                    return ['type' => 'delay', 'duration' => 0.1];
                },
                'http2' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                    return ['type' => 'http', 'status' => $response['status']];
                }
            ];

            return runConcurrent($tasks, 4);
        });
        $concurrentTime = microtime(true) - $concurrentStart;

        expect($sequentialResults)->toHaveCount(4);
        expect($concurrentResults)->toHaveCount(4);
        expect($concurrentTime)->toBeLessThan($sequentialTime);

        $improvement = round($sequentialTime / $concurrentTime, 2);
        echo "\n📊 Mixed Operations Performance:\n";
        echo "  • Sequential: {$sequentialTime}s\n";
        echo "  • Concurrent: {$concurrentTime}s\n";
        echo "  • Improvement: {$improvement}x faster\n";
    });

    test('sequential vs concurrent delay operations performance', function () {
        $delays = [0.1, 0.1, 0.1, 0.1, 0.1];

        // Sequential delays
        $sequentialStart = microtime(true);
        $sequentialResults = run(function () use ($delays) {
            $results = [];
            foreach ($delays as $index => $delayTime) {
                await(delay($delayTime));
                $results[] = ['index' => $index, 'delay' => $delayTime];
            }
            return $results;
        });
        $sequentialTime = microtime(true) - $sequentialStart;

        // Concurrent delays
        $concurrentStart = microtime(true);
        $concurrentResults = run(function () use ($delays) {
            $tasks = [];
            foreach ($delays as $index => $delayTime) {
                $tasks[] = function () use ($index, $delayTime) {
                    await(delay($delayTime));
                    return ['index' => $index, 'delay' => $delayTime];
                };
            }
            return runConcurrent($tasks, 5);
        });
        $concurrentTime = microtime(true) - $concurrentStart;

        expect($sequentialResults)->toHaveCount(5);
        expect($concurrentResults)->toHaveCount(5);
        expect($concurrentTime)->toBeLessThan($sequentialTime);

        $improvement = round($sequentialTime / $concurrentTime, 2);
        echo "\n📊 Delay Operations Performance:\n";
        echo "  • Sequential: {$sequentialTime}s\n";
        echo "  • Concurrent: {$concurrentTime}s\n";
        echo "  • Improvement: {$improvement}x faster\n";

        // Should be close to 5x improvement for parallel delays
        expect($improvement)->toBeGreaterThan(3.0);
    });

    test('runConcurrent with string keys works correctly', function () {
        $start = microtime(true);

        $results = run(function () {
            $tasks = [
                'posts' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                    return ['type' => 'posts', 'status' => $response['status']];
                },
                'users' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                    return ['type' => 'users', 'status' => $response['status']];
                },
                'albums' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/albums/1'));
                    return ['type' => 'albums', 'status' => $response['status']];
                }
            ];

            return runConcurrent($tasks, 3);
        });

        $duration = microtime(true) - $start;

        expect($results)->toHaveKey('posts');
        expect($results)->toHaveKey('users');
        expect($results)->toHaveKey('albums');
        expect($results['posts']['type'])->toBe('posts');
        expect($results['users']['type'])->toBe('users');
        expect($results['albums']['type'])->toBe('albums');

        // Should complete concurrently (faster than sequential)
        expect($duration)->toBeLessThan(2.0);

        echo "\n📊 String Keys Test: {$duration}s\n";
    });

    test('high concurrency performance with mixed workload', function () {
        $start = microtime(true);

        $results = run(function () {
            $tasks = [];
            for ($i = 1; $i <= 20; $i++) {
                $taskName = "task_$i";
                $tasks[$taskName] = function () use ($i) {
                    if ($i % 2 === 0) {
                        await(delay(0.1));
                        $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 10 + 1)));
                        return ['task' => $i, 'type' => 'http', 'status' => $response['status']];
                    } else {
                        await(delay(0.2));
                        return ['task' => $i, 'type' => 'delay'];
                    }
                };
            }

            return runConcurrent($tasks, 5);
        });

        $duration = microtime(true) - $start;

        expect($results)->toHaveCount(20);

        echo "\n📊 High Concurrency (20 tasks, limit 5): {$duration}s\n";

        // Should handle high concurrency efficiently
        expect($duration)->toBeLessThan(3.0);
    });

    test('error handling in concurrent execution', function () {
        $results = run(function () {
            $tasks = [
                'valid_request' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                    return ['type' => 'valid', 'status' => $response['status']];
                },
                'invalid_request' => function () {
                    try {
                        $response = await(fetch('https://invalid-domain-12345.com/api/test'));
                        return ['type' => 'invalid', 'status' => $response['status']];
                    } catch (Exception $e) {
                        return ['type' => 'invalid', 'error' => 'handled'];
                    }
                },
                'another_valid' => function () {
                    $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                    return ['type' => 'valid2', 'status' => $response['status']];
                }
            ];

            return runConcurrent($tasks, 3);
        });

        expect($results)->toHaveKey('valid_request');
        expect($results)->toHaveKey('invalid_request');
        expect($results)->toHaveKey('another_valid');
        expect($results['valid_request']['type'])->toBe('valid');
        expect($results['invalid_request']['error'])->toBe('handled');
        expect($results['another_valid']['type'])->toBe('valid2');
    });

    test('mixed promise types work correctly', function () {
        $results = run(function () {
            $tasks = [
                'direct_promise' => fetch('https://jsonplaceholder.typicode.com/posts/1'),
                'async_function' => function () {
                    await(delay(0.05));
                    $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                    return ['type' => 'async_function', 'status' => $response['status']];
                },
                'simple_delay' => delay(0.1)
            ];

            return runConcurrent($tasks, 3);
        });

        expect($results)->toHaveKey('direct_promise');
        expect($results)->toHaveKey('async_function');
        expect($results)->toHaveKey('simple_delay');
        expect($results['async_function']['type'])->toBe('async_function');
    });
});

describe('Concurrency Limit Analysis', function () {

    test('optimal concurrency limit analysis', function () {
        $concurrencyLimits = [1, 2, 4, 6, 8];
        $results = [];

        foreach ($concurrencyLimits as $limit) {
            $start = microtime(true);

            $taskResults = run(function () use ($limit) {
                $tasks = [];

                for ($i = 1; $i <= 10; $i++) {
                    $taskName = "task_$i";
                    $tasks[$taskName] = function () use ($i) {
                        switch ($i % 3) {
                            case 0:
                                $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 5 + 1)));
                                return ['task' => $i, 'type' => 'http', 'status' => $response['status']];
                            case 1:
                                await(delay(0.1));
                                return ['task' => $i, 'type' => 'short_delay', 'duration' => 0.1];
                            case 2:
                                await(delay(0.2));
                                return ['task' => $i, 'type' => 'medium_delay', 'duration' => 0.2];
                        }
                    };
                }

                return runConcurrent($tasks, $limit);
            });

            $duration = microtime(true) - $start;
            $results[$limit] = ['duration' => $duration, 'tasks' => count($taskResults)];

            expect($taskResults)->toHaveCount(10);
        }

        // Find optimal limit
        $bestTime = PHP_FLOAT_MAX;
        $bestLimit = 0;

        echo "\n🔬 Concurrency Limit Analysis (10 tasks):\n";
        foreach ($results as $limit => $result) {
            $duration = $result['duration'];
            $tasksPerSecond = round(10 / $duration, 2);
            echo "  Limit $limit: {$duration}s ({$tasksPerSecond} tasks/sec)\n";

            if ($duration < $bestTime) {
                $bestTime = $duration;
                $bestLimit = $limit;
            }
        }

        echo "\n🏆 Optimal limit: $bestLimit with {$bestTime}s\n";

        // Ensure we found a reasonable optimal limit
        expect($bestLimit)->toBeGreaterThan(0);
        expect($bestLimit)->toBeLessThanOrEqual(8);

        // Higher concurrency should generally perform better than limit 1
        expect($results[4]['duration'])->toBeLessThan($results[1]['duration']);
    });

    test('workload scaling performance', function () {
        $workloadSizes = [5, 10, 20];
        $optimalConcurrency = 4;
        $results = [];

        foreach ($workloadSizes as $workloadSize) {
            $start = microtime(true);

            $taskResults = run(function () use ($workloadSize, $optimalConcurrency) {
                $tasks = [];

                for ($i = 1; $i <= $workloadSize; $i++) {
                    $taskName = "task_$i";
                    $tasks[$taskName] = function () use ($i) {
                        if ($i % 2 === 0) {
                            $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 10 + 1)));
                            return ['task' => $i, 'type' => 'http', 'status' => $response['status']];
                        } else {
                            await(delay(0.1));
                            return ['task' => $i, 'type' => 'delay', 'duration' => 0.1];
                        }
                    };
                }

                return runConcurrent($tasks, $optimalConcurrency);
            });

            $duration = microtime(true) - $start;
            $results[$workloadSize] = ['duration' => $duration, 'efficiency' => $workloadSize / $duration];

            expect($taskResults)->toHaveCount($workloadSize);
        }

        echo "\n📊 Workload Scaling Analysis:\n";
        foreach ($results as $size => $result) {
            $duration = $result['duration'];
            $efficiency = round($result['efficiency'], 2);
            echo "  Size $size: {$duration}s ({$efficiency} tasks/sec)\n";
        }

        // Larger workloads should still maintain reasonable efficiency
        expect($results[20]['efficiency'])->toBeGreaterThan(2.0);
    });
});

describe('Memory and Resource Management', function () {

    test('memory usage stays reasonable with high concurrency', function () {
        $initialMemory = memory_get_usage(true);

        $results = run(function () {
            $tasks = [];

            for ($i = 0; $i < 50; $i++) {
                $tasks["task_$i"] = function () use ($i) {
                    await(delay(0.01));
                    return $i * 2;
                };
            }

            return runConcurrent($tasks, 10);
        });

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        expect($results)->toHaveCount(50);

        echo "\n💾 Memory Usage Analysis:\n";
        echo "  • Initial: " . round($initialMemory / 1024 / 1024, 2) . " MB\n";
        echo "  • Final: " . round($finalMemory / 1024 / 1024, 2) . " MB\n";
        echo "  • Increase: " . round($memoryIncrease / 1024 / 1024, 2) . " MB\n";

        // Memory increase should be reasonable (less than 10MB for 50 tasks)
        expect($memoryIncrease)->toBeLessThan(10 * 1024 * 1024);
    });

    test('handles large batch operations efficiently', function () {
        $totalOperations = 1000;
        $batchSize = 100;
        $completed = 0;

        $start = microtime(true);

        run(function () use ($totalOperations, $batchSize, &$completed) {
            for ($i = 0; $i < $totalOperations; $i += $batchSize) {
                $promises = [];

                for ($j = 0; $j < $batchSize; $j++) {
                    // Create promises directly, don't call the functions yet
                    $promises[] = async(function () use (&$completed) {
                        await(delay(0.001));
                        $completed++;
                        return true;
                    })();
                }

                // Wait for all promises in this batch to complete
                await(all($promises));
            }
        });

        $duration = microtime(true) - $start;

        expect($completed)->toBe($totalOperations);

        echo "\n⚡ Batch Operations Performance:\n";
        echo "  • Total operations: $totalOperations\n";
        echo "  • Batch size: $batchSize\n";
        echo "  • Total time: {$duration}s\n";
        echo "  • Operations/sec: " . round($totalOperations / $duration, 2) . "\n";

        // Should complete efficiently
        expect($duration)->toBeLessThan(5.0);
    });
});
