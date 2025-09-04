<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

echo "Using UV: " . ($loop->isUsingUv() ? "YES" : "NO") . "\n";

for ($i = 1; $i <= 10; $i++) {
    $startTime = microtime(true);
    run(function () {
        $timers = array_fill(0, 30000, delay(1));
        await(all($timers));
    });
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "Execution time: " . $executionTime . " seconds\n";
}
