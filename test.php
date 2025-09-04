<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

echo "Using UV: " . ($loop->isUsingUv() ? "YES" : "NO") . "\n";

// Test timer precision
$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    $loop->addTimer(0.01 * $i, function () use ($i) {
        echo "Timer $i fired\n";
    });
}

$loop->run();
