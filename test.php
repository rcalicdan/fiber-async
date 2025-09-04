<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQL;
use Rcalicdan\FiberAsync\Api\Task;

require_once 'vendor/autoload.php';

echo "=== Initializing AsyncMySQL ===\n";
AsyncMySQL::init([
    "host" => "localhost",
    "port" => 3309,
    "username" => "root",
    "password" => "Reymart1234",
    "database" => "yo",
    "debug" => false,
]); 

for ($i = 1; $i <= 3; $i++) {
    echo "\n=== ITERATION $i START ===\n";
    $startTime = microtime(true);
    
    Task::runStateful(function () use ($i) {
        await(all([
            AsyncMySQL::query("SELECT SLEEP(1)"),
            AsyncMySQL::query("SELECT SLEEP(1)"),
            AsyncMySQL::query("SELECT SLEEP(1)"),
        ]));
    });
    
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "[TEST-$i] Execution time: " . $executionTime . " seconds\n";
    echo "=== ITERATION $i END ===\n";
}

echo "\n=== Cleanup ===\n";
AsyncMySQL::reset();