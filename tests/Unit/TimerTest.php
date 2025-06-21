<?php

use Rcalicdan\FiberAsync\Services\TimerManager;
use Rcalicdan\FiberAsync\ValueObjects\Timer;

test('timer manager can add and process timers', function () {
    $manager = new TimerManager;
    $executed = false;

    $timerId = $manager->addTimer(0.01, function () use (&$executed) {
        $executed = true;
    });

    expect($timerId)->toBeString();
    expect($manager->hasTimers())->toBeTrue();

    // Wait for timer to be ready
    usleep(15000); // 15ms

    $processed = $manager->processTimers();
    expect($processed)->toBeTrue();
    expect($executed)->toBeTrue();
});

test('timer calculates next delay correctly', function () {
    $manager = new TimerManager;

    $manager->addTimer(0.1, function () {});
    $manager->addTimer(0.05, function () {});

    $nextDelay = $manager->getNextTimerDelay();
    expect($nextDelay)->toBeLessThanOrEqual(0.1);
    expect($nextDelay)->toBeGreaterThan(0);
});

test('timer object works correctly', function () {
    $executed = false;
    $timer = new Timer(0.01, function () use (&$executed) {
        $executed = true;
    });

    expect($timer->getId())->toBeString();
    expect($timer->getCallback())->toBeCallable();
    expect($timer->getExecuteAt())->toBeFloat();

    // Should not be ready immediately
    expect($timer->isReady(microtime(true)))->toBeFalse();

    // Should be ready after delay
    usleep(15000); // 15ms
    expect($timer->isReady(microtime(true)))->toBeTrue();

    $timer->execute();
    expect($executed)->toBeTrue();
});
