<?php

use Rcalicdan\FiberAsync\Services\FiberManager;

test('fiber manager can add and process fibers', function () {
    $manager = new FiberManager;
    $executed = false;

    $fiber = new Fiber(function () use (&$executed) {
        $executed = true;

        return 'fiber result';
    });

    $manager->addFiber($fiber);
    expect($manager->hasFibers())->toBeTrue();

    $processed = $manager->processFibers();
    expect($processed)->toBeTrue();
    expect($executed)->toBeTrue();
});

test('fiber manager handles suspended fibers', function () {
    $manager = new FiberManager;
    $step = 0;

    $fiber = new Fiber(function () use (&$step) {
        $step = 1;
        Fiber::suspend();
        $step = 2;

        return 'completed';
    });

    $manager->addFiber($fiber);

    $manager->processFibers();
    expect($step)->toBe(1);
    expect($manager->hasActiveFibers())->toBeTrue();

    $manager->processFibers();
    expect($step)->toBe(2);
});
