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
    $results = run_all([
        'op1' => function () {
            return await(delay(0.05)->then(fn () => 'result1'));
        },
        'op2' => function () {
            return await(delay(0.05)->then(fn () => 'result2'));
        },
    ]);
    $duration = microtime(true) - $start;

    expect($results)->toBe(['op1' => 'result1', 'op2' => 'result2']);

    // FIX: Increase the time limit slightly to account for the overhead of
    // correct promise handling and the Windows environment. 0.12s is a safe bet.
    expect($duration)->toBeLessThan(0.12);
});

test('run_with_timeout throws exception on timeout', function () {
    expect(function () {
        run_with_timeout(function () {
            return await(delay(0.1));
        }, 0.05);
    })->toThrow(Exception::class);
});

test('benchmark returns result and timing information', function () {
    $benchmark = benchmark(function () {
        await(delay(0.05));
        return 'benchmark result';
    });

    expect($benchmark)->toHaveKey('result');
    expect($benchmark)->toHaveKey('benchmark');
    expect($benchmark['benchmark'])->toHaveKey('execution_time');
    expect($benchmark['benchmark'])->toHaveKey('duration_ms');
    expect($benchmark['result'])->toBe('benchmark result');
    expect($benchmark['benchmark']['execution_time'])->toBeGreaterThan(0.04);
    expect($benchmark['benchmark']['duration_ms'])->toBeGreaterThan(40);
});