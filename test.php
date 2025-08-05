<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQLi;

require "vendor/autoload.php";

// AsyncMySQLi::init([
//     'host' => 'localhost',
//     'username' => 'hey',
//     'password' => '1234',
//     'database' => 'yo',
// ]);

for ($i = 1; $i <= 10; $i++) {
    $startTime = microtime(true);
    run(function () {
        AsyncMySQLi::query("SELECT sleep(1)");
        AsyncMySQLi::query("SELECT sleep(1)");
        AsyncMySQLi::query("SELECT sleep(1)");
    });

    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "Execution time: " . $executionTime . " seconds\n";
}
