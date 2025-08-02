<?php
require "vendor/autoload.php";

use Rcalicdan\FiberAsync\Api\CleanupManager;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;

echo "=== EXACT ORIGINAL PARAMETERS TEST ===\n";
function getRandomFloat($min = 0, $max = 1) {
    return $min + mt_rand() / mt_getrandmax() * ($max - $min);
}

for ($round = 1; $round <= 500; $round++) {
    // start time measurement
    $startTime = microtime(true);

    $before = memory_get_usage(true);
    
    $promises = [];
    for ($i = 0; $i < 50; $i++) { 
        $promises[] = delay(getRandomFloat(0.1, 1));
    }
    
    $responses = run(function() use ($promises) {
        return await(Promise::all($promises)); // Concurrency 10
    });
    
    $after = memory_get_usage(true);
    
    // (no explicit cleanup call here, so cleaned == after)
    $cleaned = memory_get_usage(true);
    
    // end time measurement
    $duration = microtime(true) - $startTime;

    echo sprintf(
        "Round %d: Before=%s After=%s Cleaned=%s Net=%s Time=%.2f s\n",
        $round,
        formatBytes($before),
        formatBytes($after),
        formatBytes($cleaned),
        formatBytes($cleaned - $before),
        $duration
    );
}

function formatBytes($size, $precision = 2)
{
    if ($size === 0) return "0 B";
    if ($size < 0) return "-" . formatBytes(abs($size), $precision);
    
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}
