<?php

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Promise;

beforeEach(function () {
    resetEventLoop();
});

test('promise can be resolved', function () {
    $promise = new Promise;
    $resolved = false;
    $value = null;

    $promise->then(function ($val) use (&$resolved, &$value) {
        $resolved = true;
        $value = $val;
    });

    $promise->resolve('test value');

    // Process the event loop to handle callbacks
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
    expect($promise->isResolved())->toBeTrue();
    expect($promise->isPending())->toBeFalse();
});

test('promise can be rejected', function () {
    $promise = new Promise;
    $rejected = false;
    $reason = null;

    $promise->catch(function ($r) use (&$rejected, &$reason) {
        $rejected = true;
        $reason = $r;
    });

    $promise->reject('test error');

    // Process the event loop to handle callbacks
    $loop = EventLoop::getInstance();
    $start = microtime(true);
    while (! $rejected && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($rejected) {
            break;
        }
        usleep(1000);
    }

    expect($rejected)->toBeTrue();
    expect($reason)->toBeInstanceOf(Exception::class);
    expect($promise->isRejected())->toBeTrue();
    expect($promise->isPending())->toBeFalse();
});

test('promise with executor function works', function () {
    $resolved = false;
    $value = null;

    $promise = new Promise(function ($resolve, $reject) {
        $resolve('executor value');
    });

    $promise->then(function ($val) use (&$resolved, &$value) {
        $resolved = true;
        $value = $val;
    });

    // Process the event loop to handle callbacks
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
    expect($value)->toBe('executor value');
});

test('promise chaining works', function () {
    $finalValue = null;

    $promise = new Promise(function ($resolve) {
        $resolve(5);
    });

    $promise
        ->then(function ($value) {
            return $value * 2;
        })
        ->then(function ($value) use (&$finalValue) {
            $finalValue = $value;
        })
    ;

    // Process the event loop to handle callbacks
    $loop = EventLoop::getInstance();
    $start = microtime(true);
    while ($finalValue === null && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($finalValue !== null) {
            break;
        }
        usleep(1000);
    }

    expect($finalValue)->toBe(10);
});

test('promise finally callback executes', function () {
    $finallyExecuted = false;

    $promise = new Promise(function ($resolve) {
        $resolve('test');
    });

    $promise->finally(function () use (&$finallyExecuted) {
        $finallyExecuted = true;
    });

    // Process the event loop to handle callbacks
    $loop = EventLoop::getInstance();
    $start = microtime(true);
    while (! $finallyExecuted && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($finallyExecuted) {
            break;
        }
        usleep(1000);
    }

    expect($finallyExecuted)->toBeTrue();
});
