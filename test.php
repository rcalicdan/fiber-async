<?php

use Rcalicdan\FiberAsync\EventLoop\Loop;

require __DIR__ . '/vendor/autoload.php';

function asyncTask(string $name, float $delay)
{
    return new Fiber(function () use ($name, $delay) {
        $start = microtime(true);
        echo sprintf("[%s] Start at %.3f\n", $name, $start);

        // Your async delay function (float seconds)
        delay($delay);

        $end = microtime(true);
        echo sprintf("[%s] End at %.3f (Duration: %.3f)\n", $name, $end, $end - $start);
    });
}

$startTime = microtime(true);

run(function () {
    yield asyncTask('Task A', 0.5);
    yield asyncTask('Task B', 1.2);
    yield asyncTask('Task C', 2.8);
});

$totalTime = microtime(true) - $startTime;

echo sprintf("Total runtime: %.3f seconds\n", $totalTime);
