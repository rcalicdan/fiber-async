<?php

beforeEach(function () {
    resetEventLoop();
});

test('handles many concurrent timers efficiently', function () {
    $start = microtime(true);
    $completed = 0;

    $result = run(function () use (&$completed) {
        $promises = [];

        for ($i = 0; $i < 100; $i++) {
            $promises[] = delay(0.01)->then(function () use (&$completed) {
                $completed++;
                return $completed;
            });
        }

        return await(all($promises));
    });

    $duration = microtime(true) - $start;

    expect($completed)->toBe(100);
    expect(count($result))->toBe(100);
    // Should complete in reasonable time (much less than 1 second)
    expect($duration)->toBeLessThan(0.1);
});

test('memory usage stays reasonable with many operations', function () {
    $initialMemory = memory_get_usage();

    run(function () {
        $promises = [];

        for ($i = 0; $i < 50; $i++) {
            $promises[] = async(function () use ($i) {
                return await(delay(0.001)->then(fn() => $i * 2));
            })();
        }

        return await(all($promises));
    });

    $finalMemory = memory_get_usage();
    $memoryIncrease = $finalMemory - $initialMemory;

    // Memory increase should be reasonable (less than 5MB)
    expect($memoryIncrease)->toBeLessThan(5 * 1024 * 1024);
});
