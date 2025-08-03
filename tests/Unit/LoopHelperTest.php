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
    expect($duration)->toBeLessThan(0.08);
});

test('run_with_timeout throws exception on timeout', function () {
    expect(function () {
        run_with_timeout(function () {
            return await(delay(0.1));
        }, 0.05);
    })->toThrow(Exception::class);
});
