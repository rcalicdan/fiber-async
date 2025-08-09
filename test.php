<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQLi;
use Rcalicdan\FiberAsync\Api\Promise;

require_once __DIR__ . '/vendor/autoload.php';

AsyncMySQLi::init([
    'host' => '127.0.0.1',
    'port' => 3309,
    'username' => 'root',
    'password' => 'Reymart1234',
    'database' => 'yo',
]);
// Test 1: Single query (baseline)
$startTime = microtime(true);
run(function () {
    await(AsyncMySQLi::query('SELECT SLEEP(2)'));
});
$singleQueryTime = microtime(true) - $startTime;
echo "Single query time: " . $singleQueryTime . " seconds\n";

// Test 2: Six parallel queries
$startTime = microtime(true);
run(function () {
    $delays = Promise::all([
        AsyncMySQLi::query('SELECT SLEEP(2)'),
        AsyncMySQLi::query('SELECT SLEEP(2)'),
        AsyncMySQLi::query('SELECT SLEEP(2)'),
        AsyncMySQLi::query('SELECT SLEEP(2)'),
        AsyncMySQLi::query('SELECT SLEEP(2)'),
        AsyncMySQLi::query('SELECT SLEEP(2)'),
    ]);
    await($delays);
});
$parallelQueryTime = microtime(true) - $startTime;
echo "Six parallel queries time: " . $parallelQueryTime . " seconds\n";

echo "Efficiency: " . round(($singleQueryTime * 6) / $parallelQueryTime, 2) . "x faster\n";