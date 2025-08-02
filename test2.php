<?php
require "vendor/autoload.php";

use Rcalicdan\FiberAsync\Api\CleanupManager;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;

echo "=== EXACT ORIGINAL PARAMETERS TEST ===\n";

for ($round = 1; $round <= 50; $round++) {
    $before = memory_get_usage(true);
    
    // Exact same parameters as your failing test
    $promises = [];
    for ($i = 0; $i < 5; $i++) { // 30 requests
        $promises[] = http_get('https://jsonplaceholder.typicode.com/posts/1');
    }
    
    $responses = run(function() use ($promises) {
        return await(Promise::concurrent($promises, 10)); // Concurrency 10
    });
    
    $after = memory_get_usage(true);
    
    CleanupManager::cleanup();
    
    $cleaned = memory_get_usage(true);
    
    echo "Round $round: Before=" . formatBytes($before) . 
         " After=" . formatBytes($after) . 
         " Cleaned=" . formatBytes($cleaned) . 
         " Net=" . formatBytes($cleaned - $before) . "\n";
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