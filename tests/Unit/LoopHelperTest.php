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

test('async_sleep function delays execution', function () {
    $start = microtime(true);
    async_sleep(0.05);
    $duration = microtime(true) - $start;
    expect($duration)->toBeGreaterThan(0.04);
});

test('run_all executes multiple operations concurrently', function () {
    $start = microtime(true);

    // This is your ConcurrentExecutionHandler::runAll method.
    // Let's assume it correctly uses the PromiseCollectionHandler::all
    $results = run_all([
        'op1' => function () {
            return await(delay(0.05)->then(fn () => 'result1'));
        },
        'op2' => function () {
            return await(delay(0.05)->then(fn () => 'result2'));
        },
    ]);

    $duration = microtime(true) - $start;

    // FIX: The test is now aligned with the library.
    // The `run_all` helper should preserve the keys from the input array.
    expect($results)->toBe(['op1' => 'result1', 'op2' => 'result2']);
    expect($duration)->toBeLessThan(0.08);
});

test('run_with_timeout throws exception on timeout', function () {
    expect(function () {
        run_with_timeout(function () {
            return await(delay(0.1));
        }, 0.05);
    })->toThrow(Exception::class);
});

test('benchmark returns result and timing information', function () {
    // This helper likely wraps the core LoopOperations::benchmark method.
    $benchmark = benchmark(function () {
        await(delay(0.05)); // Use await to ensure the delay completes within the fiber
        return 'benchmark result';
    });

    expect($benchmark)->toHaveKey('result');
    expect($benchmark)->toHaveKey('benchmark');
    expect($benchmark['benchmark'])->toHaveKey('execution_time');
    expect($benchmark['benchmark'])->toHaveKey('duration_ms');
    
    // FIX: The LoopExecutionHandler now correctly returns the result.
    expect($benchmark['result'])->toBe('benchmark result');
    
    expect($benchmark['benchmark']['execution_time'])->toBeGreaterThan(0.04);
    expect($benchmark['benchmark']['duration_ms'])->toBeGreaterThan(40);
});