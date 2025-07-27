<?php

beforeEach(function () {
    resetEventLoop();
});

describe('Async Performance and Concurrency', function () {

    test('sequential vs concurrent HTTP requests performance', function () {
        $urls = [
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
            'https://httpbin.org/delay/0.2',
        ];

        $sequentialStart = microtime(true);
        run(function () use ($urls) {
            foreach ($urls as $url) {
                await(fetch($url));
            }
        });
        $sequentialTime = microtime(true) - $sequentialStart;
        $concurrentStart = microtime(true);
        run(function () use ($urls) {
            $tasks = array_map(fn($url) => fn() => await(fetch($url)), $urls);
            await(all($tasks));
        });
        $concurrentTime = microtime(true) - $concurrentStart;
        $improvement = round($sequentialTime / $concurrentTime, 2);
        echo "\n\n📊 HTTP Requests Performance:\n";
        echo '  • Sequential: ' . round($sequentialTime, 4) . "s\n";
        echo '  • Concurrent: ' . round($concurrentTime, 4) . "s\n";
        echo "  • Improvement: {$improvement}x faster\n";

        expect($concurrentTime)->toBeLessThan($sequentialTime);
        expect($improvement)->toBeGreaterThan(1);
    })->group('performance');

    test('run_concurrent preserves string keys', function () {
        $results = run(function () {
            $tasks = [
                'posts' => fn() => await(fetch('https://jsonplaceholder.typicode.com/posts/1')),
                'users' => fn() => await(fetch('https://jsonplaceholder.typicode.com/users/1')),
            ];

            return run_concurrent($tasks, 2);
        });

        expect($results)->toHaveKeys(['posts', 'users']);
    });

    test('memory usage with high task count', function () {
        $initialMemory = memory_get_usage();

        run(function () {
            $tasks = [];
            for ($i = 0; $i < 100; $i++) {
                $tasks[] = fn() => await(delay(0.001));
            }
            run_concurrent($tasks, 10);
        });

        $memoryIncrease = memory_get_usage() - $initialMemory;
        $memoryIncreaseMB = round($memoryIncrease / 1024 / 1024, 2);

        echo "\n💾 Memory Increase for 100 concurrent tasks: {$memoryIncreaseMB} MB\n";

        // Should be very low, well under 5MB.
        expect($memoryIncrease)->toBeLessThan(5 * 1024 * 1024);
    })->group('performance');

    test('handles large batch operations efficiently', function () {
        $totalOperations = 500;
        $batchSize = 100;
        $completed = 0;

        $start = microtime(true);
        run(function () use ($totalOperations, $batchSize, &$completed) {
            for ($i = 0; $i < $totalOperations; $i += $batchSize) {
                $promises = [];
                for ($j = 0; $j < $batchSize; $j++) {
                    $promises[] = async(function () use (&$completed) {
                        await(delay(0.001));
                        $completed++;

                        return true;
                    })();
                }
                await(all($promises));
            }
        });
        $duration = microtime(true) - $start;

        expect($completed)->toBe($totalOperations);
        echo "\n⚡ Batch of 500 ops (100 at a time): " . round($duration, 4) . "s\n";
        expect($duration)->toBeLessThan(2.0);
    })->group('performance');
});
