<?php

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\AsyncLoop;
use Rcalicdan\FiberAsync\Api\Promise;

beforeEach(function () {
    resetEventLoop();
});

test('Async Facade can call async operations', function () {
    $asyncFunc = Async::async(function ($value) {
        return $value * 2;
    });

    $result = null;
    $asyncFunc(5)->then(function ($value) use (&$result) {
        $result = $value;
    });

    // Process the event loop
    $loop = Rcalicdan\FiberAsync\EventLoop\EventLoop::getInstance();
    $start = microtime(true);
    while ($result === null && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($result !== null) {
            break;
        }
        usleep(1000);
    }

    expect($result)->toBe(10);
});

test('Async Loop Facade can call loop operations', function () {
    $result = AsyncLoop::task(function () {
        return 'facade task result';
    });

    expect($result)->toBe('facade task result');
});

test('Async resolve works', function () {
    $resolved = false;
    $value = null;

    Promise::resolve('facade test')->then(function ($val) use (&$resolved, &$value) {
        $resolved = true;
        $value = $val;
    });

    $loop = Rcalicdan\FiberAsync\EventLoop\EventLoop::getInstance();
    $start = microtime(true);
    while (! $resolved && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($resolved) {
            break;
        }
        usleep(1000);
    }

    expect($resolved)->toBeTrue();
    expect($value)->toBe('facade test');
});

test('Async Facade can be reset', function () {
    // Use the facade to ensure instances are created
    Async::inFiber();

    // Reset should not throw any errors
    Async::reset();

    // Should still work after reset
    expect(Async::inFiber())->toBeFalse();
});
