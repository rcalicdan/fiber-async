<?php

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;

beforeEach(function () {
    resetEventLoop();
});

test('AsyncOperations class can be instantiated', function () {
    $asyncOps = new AsyncOperations;
    expect($asyncOps)->toBeInstanceOf(AsyncOperations::class);
});

test('AsyncOperations async method works', function () {
    $asyncOps = new AsyncOperations;
    $asyncFunc = $asyncOps->async(function ($value) {
        return $value * 2;
    });

    $result = null;
    $asyncFunc(5)->then(function ($value) use (&$result) {
        $result = $value;
    });

    $loop = EventLoop::getInstance();
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

test('AsyncOperations resolve method works', function () {
    $asyncOps = new AsyncOperations;
    $resolved = false;
    $value = null;

    $asyncOps->resolved('test value')->then(function ($val) use (&$resolved, &$value) {
        $resolved = true;
        $value = $val;
    });

    $loop = EventLoop::getInstance();
    $start = microtime(true);
    while (! $resolved && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($resolved) {
            break;
        }
        usleep(1000);
    }

    expect($resolved)->toBeTrue();
    expect($value)->toBe('test value');
});
