<?php

use Rcalicdan\FiberAsync\Facades\AsyncLoop;

$memory_limit = '512M';
$iterations = 10000;
ini_set('memory_limit', $memory_limit);

require 'vendor/autoload.php';

echo "🔁 Test FiberAsync with memory limit of {$memory_limit} and {$iterations} iterations\n\n";

$start_time = microtime(true);
$promises = [];

for ($i = 0; $i <= $iterations; $i++) {
    $promises[] = delay(1);
}

AsyncLoop::runAll($promises);

$microtime = microtime(true) - $start_time;
$peak = memory_get_peak_usage(true) / 1024 / 1024;
$current = memory_get_usage(true) / 1024 / 1024;

echo "✅ Time taken: {$microtime} seconds\n";
echo "📈 Peak memory usage: {$peak} MB\n";
echo "📊 Current memory usage: {$current} MB\n";
