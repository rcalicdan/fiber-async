<?php

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

beforeEach(function () {
    resetEventLoop();
});

test('event loop singleton works correctly', function () {
    $loop1 = EventLoop::getInstance();
    $loop2 = EventLoop::getInstance();

    expect($loop1)->toBe($loop2);
});

test('event loop can add and process timers', function () {
    $loop = EventLoop::getInstance();
    $executed = false;

    $loop->addTimer(0.01, function () use (&$executed) {
        $executed = true;
    });

    // Run the loop briefly
    $start = microtime(true);
    while (! $executed && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($executed) {
            break;
        }
        usleep(1000);
    }

    expect($executed)->toBeTrue();
});

test('event loop can process next tick callbacks', function () {
    $loop = EventLoop::getInstance();
    $executed = false;

    $loop->nextTick(function () use (&$executed) {
        $executed = true;
    });

    // Process one tick
    $start = microtime(true);
    while (! $executed && (microtime(true) - $start) < 1) {
        $loop->run();
        if ($executed) {
            break;
        }
        usleep(1000);
    }

    expect($executed)->toBeTrue();
});

test('event loop can be stopped', function () {
    $loop = EventLoop::getInstance();

    $loop->nextTick(function () use ($loop) {
        $loop->stop();
    });

    $loop->run();

    expect(true)->toBeTrue();
});

test('event loop detects when it has work', function () {
    $loop = EventLoop::getInstance();

    // Initially should have no work
    expect($loop->isIdle())->toBeTrue();

    // Add a timer, should have work
    $loop->addTimer(1.0, function () {});
    expect($loop->isIdle())->toBeFalse();
});
