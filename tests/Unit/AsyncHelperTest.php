<?php

require_once __DIR__ . '/../../src/Helpers/async_helper.php';
require_once __DIR__ . '/../../src/Helpers/loop_helper.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;

beforeEach(function () {
    resetEventLoop();
});

test('async function wrapper works', function () {
    $asyncFunc = async(function ($value) {
        return $value * 2;
    });

    $result = null;
    $asyncFunc(5)->then(function ($value) use (&$result) {
        $result = $value;
    });

    $loop = AsyncEventLoop::getInstance();
    $start = microtime(true);
    while ($result === null && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($result !== null) break;
        usleep(1000);
    }

    expect($result)->toBe(10);
});

test('delay function works', function () {
    $start = microtime(true);
    $completed = false;

    delay(0.05)->then(function () use (&$completed) {
        $completed = true;
    });

    $loop = AsyncEventLoop::getInstance();
    while (!$completed && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($completed) break;
        usleep(1000);
    }

    $duration = microtime(true) - $start;

    expect($completed)->toBeTrue();
    expect($duration)->toBeGreaterThan(0.04); // Should take at least 40ms
});

test('resolve helper creates resolved promise', function () {
    $resolved = false;
    $value = null;

    resolve('test value')->then(function ($val) use (&$resolved, &$value) {
        $resolved = true;
        $value = $val;
    });

    $loop = AsyncEventLoop::getInstance();
    $start = microtime(true);
    while (!$resolved && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($resolved) break;
        usleep(1000);
    }

    expect($resolved)->toBeTrue();
    expect($value)->toBe('test value');
});

test('reject helper creates rejected promise', function () {
    $rejected = false;
    $reason = null;

    reject('test error')->catch(function ($r) use (&$rejected, &$reason) {
        $rejected = true;
        $reason = $r;
    });

    $loop = AsyncEventLoop::getInstance();
    $start = microtime(true);
    while (!$rejected && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($rejected) break;
        usleep(1000);
    }

    expect($rejected)->toBeTrue();
    expect($reason)->toBeInstanceOf(Exception::class);
});

test('all helper waits for all promises', function () {
    $result = null;

    $promises = [
        resolve(1),
        resolve(2),
        resolve(3)
    ];

    all($promises)->then(function ($values) use (&$result) {
        $result = $values;
    });

    $loop = AsyncEventLoop::getInstance();
    $start = microtime(true);
    while ($result === null && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($result !== null) break;
        usleep(1000);
    }

    expect($result)->toBe([1, 2, 3]);
});

test('race helper resolves with first promise', function () {
    $result = null;

    $promises = [
        delay(0.1)->then(fn() => 'slow'),
        resolve('fast')
    ];

    race($promises)->then(function ($value) use (&$result) {
        $result = $value;
    });

    $loop = AsyncEventLoop::getInstance();
    $start = microtime(true);
    while ($result === null && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($result !== null) break;
        usleep(1000);
    }

    expect($result)->toBe('fast');
});
