<?php

// tests/Unit/LoopHelperTest.php

require_once __DIR__.'/../../src/Helpers/async_helper.php';
require_once __DIR__.'/../../src/Helpers/loop_helper.php';

beforeEach(function () {
    resetEventLoop();
});

test('run function executes async operation and returns result', function () {
    $result = run(function () {
        return await(resolve('test result'));
    });

    expect($result)->toBe('test result');
});

test('task function works as shorthand for run', function () {
    $result = task(function () {
        return 'task result';
    });

    expect($result)->toBe('task result');
});

test('asyncSleep function delays execution', function () {
    $start = microtime(true);

    asyncSleep(0.05);

    $duration = microtime(true) - $start;
    expect($duration)->toBeGreaterThan(0.04);
});

test('runAll executes multiple operations concurrently', function () {
    $start = microtime(true);

    $results = runAll([
        'op1' => function () {
            return await(delay(0.05)->then(fn () => 'result1'));
        },
        'op2' => function () {
            return await(delay(0.05)->then(fn () => 'result2'));
        },
    ]);

    $duration = microtime(true) - $start;

    expect($results)->toBe(['result1', 'result2']);
    // Should take around 50ms (parallel), not 100ms (sequential)
    expect($duration)->toBeLessThan(0.08);
});

test('runWithTimeout throws exception on timeout', function () {
    expect(function () {
        runWithTimeout(function () {
            return await(delay(0.1));
        }, 0.05);
    })->toThrow(Exception::class);
});

test('benchmark returns result and timing information', function () {
    $benchmark = benchmark(function () {
        asyncSleep(0.05);

        return 'benchmark result';
    });

    expect($benchmark)->toHaveKey('result');
    expect($benchmark)->toHaveKey('duration');
    expect($benchmark)->toHaveKey('duration_ms');
    expect($benchmark['result'])->toBe('benchmark result');
    expect($benchmark['duration'])->toBeGreaterThan(0.04);
    expect($benchmark['duration_ms'])->toBeGreaterThan(40);
});
