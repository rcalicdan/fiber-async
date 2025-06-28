<?php

use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\LoopOperations;

beforeEach(function () {
    resetEventLoop();
});

test('LoopOperations class can be instantiated', function () {
    $loopOps = new LoopOperations;
    expect($loopOps)->toBeInstanceOf(LoopOperations::class);
});

test('LoopOperations can be instantiated with AsyncOperations', function () {
    $asyncOps = new AsyncOperations;
    $loopOps = new LoopOperations($asyncOps);
    expect($loopOps)->toBeInstanceOf(LoopOperations::class);
});

test('LoopOperations task method works', function () {
    $loopOps = new LoopOperations;

    $result = $loopOps->task(function () {
        return 'task result';
    });

    expect($result)->toBe('task result');
});

test('LoopOperations asyncSleep method works', function () {
    $loopOps = new LoopOperations;
    $start = microtime(true);

    $loopOps->asyncSleep(0.05);

    $duration = microtime(true) - $start;
    expect($duration)->toBeGreaterThan(0.04);
});
