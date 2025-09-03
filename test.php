<?php

// Simple UV timer test
require_once "vendor/autoload.php";

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

$loop = EventLoop::getInstance();
echo $loop->isUsingUv() ? "Using UV\n" : "Not Using UV\n";

$startTime = microtime(true);
$completed = false;

$loop->addTimer(1.0, function() use ($startTime, &$completed) {
    $endTime = microtime(true);
    $elapsed = ($endTime - $startTime) * 1000; // Convert to ms
    echo "Timer fired after: " . number_format($elapsed, 2) . " ms\n";
    $completed = true;
});

// Run the loop until timer completes
while (!$completed && $loop->isRunning()) {
    $loop->run();
    if (!$loop->hasTimers()) {
        break;
    }
}